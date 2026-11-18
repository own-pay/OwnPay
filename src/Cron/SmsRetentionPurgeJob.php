<?php

declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Core\Database;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Service\System\Logger;

/**
 * Daily job: permanently purges raw SMS body columns when the retention setting is enabled.
 *
 * The purge deliberately rewrites ONLY `body` and `encrypted_raw` - the ledger-audit
 * metadata (sender, amount, TrxID, gateway, match result) stays forever. SMS in
 * `admin_review` state is never purged because the rows exist to be reviewed.
 *
 * Configuration lives in op_system_settings (group 'sms', keys 'retention_enabled',
 * 'retention_days'), resolved per merchant scope with brand-override semantics:
 * a brand's own override wins over the All-Brands default. Default: disabled.
 * A retention_days value of 0 or invalid is treated as disabled (fail-safe).
 */
final class SmsRetentionPurgeJob implements CronJobInterface
{
    private const CHUNK = 1000;

    public function __construct(
        private readonly Database $db,
        private readonly SettingsRepository $settings,
        private readonly Logger $logger,
    ) {
    }

    public function run(): mixed
    {
        $merchants = $this->db->fetchAll(
            "SELECT DISTINCT merchant_id FROM op_sms_parsed
             WHERE (body IS NOT NULL OR encrypted_raw IS NOT NULL)"
        );

        /** @var array<int, int|null> $scopes */
        $scopes = [];
        foreach ($merchants as $row) {
            $mid = $row['merchant_id'] ?? null;
            if ($mid === null) {
                $scopes[0] = null;
            } elseif (is_scalar($mid)) {
                $scopes[(int) $mid] = (int) $mid;
            }
        }

        $totalPurged = 0;
        foreach ($scopes as $scope => $merchantId) {
            $config = $this->resolveConfig($merchantId);
            if (!$config['enabled']) {
                continue;
            }
            $totalPurged += $this->purgeScope($merchantId, $config['days']);
        }

        if ($totalPurged > 0) {
            $this->logger->info("SMS retention purge: erased raw body columns on {$totalPurged} row(s)");
        }

        return ['status' => 'ok', 'purged' => $totalPurged];
    }

    /**
     * @return array{enabled: bool, days: int}
     */
    private function resolveConfig(?int $merchantId): array
    {
        if ($merchantId !== null) {
            $enabledRaw = $this->settings->getScoped('sms', 'retention_enabled', $merchantId, '0');
            $daysRaw = $this->settings->getScoped('sms', 'retention_days', $merchantId, '0');
        } else {
            $enabledRaw = $this->settings->get('sms', 'retention_enabled', '0');
            $daysRaw = $this->settings->get('sms', 'retention_days', '0');
        }
        $days = is_string($daysRaw) && ctype_digit($daysRaw) ? (int) $daysRaw : 0;
        return ['enabled' => $enabledRaw === '1' && $days >= 1, 'days' => max(1, $days)];
    }

    private function purgeScope(?int $merchantId, int $days): int
    {
        $ts = strtotime("-{$days} days");
        $cutoff = date('Y-m-d H:i:s', $ts === false ? time() : $ts);
        $purged = 0;

        while (true) {
            if ($merchantId === null) {
                $affected = $this->db->update(
                    "UPDATE op_sms_parsed SET body = NULL, encrypted_raw = NULL
                     WHERE merchant_id IS NULL AND received_at < :cutoff AND match_status != 'admin_review'
                       AND (body IS NOT NULL OR encrypted_raw IS NOT NULL)
                     ORDER BY id ASC LIMIT " . self::CHUNK,
                    ['cutoff' => $cutoff]
                );
            } else {
                $affected = $this->db->update(
                    "UPDATE op_sms_parsed SET body = NULL, encrypted_raw = NULL
                     WHERE merchant_id = :mid AND received_at < :cutoff AND match_status != 'admin_review'
                       AND (body IS NOT NULL OR encrypted_raw IS NOT NULL)
                     ORDER BY id ASC LIMIT " . self::CHUNK,
                    ['cutoff' => $cutoff, 'mid' => $merchantId]
                );
            }

            if ($affected <= 0) {
                break;
            }
            $purged += $affected;
        }

        return $purged;
    }
}
