<?php
declare(strict_types=1);

namespace OwnPay\Cron;

use OwnPay\Service\System\HttpClient;

/**
 * Class CurrencyUpdateJob
 *
 * Enterprise cron job executing currency exchange rate updates for multi-currency transaction operations.
 * Synchronizes the `op_exchange_rates` table with external exchange rate services to enable
 * real-time, gateway-scoped currency conversions during checkout processes.
 *
 * @package OwnPay\Cron
 */
final class CurrencyUpdateJob
{
    /**
     * @var \OwnPay\Core\Database The database connection instance.
     */
    private \OwnPay\Core\Database $db;

    /**
     * @var HttpClient HTTP client used to fetch rate feeds from the remote provider.
     */
    private HttpClient $http;

    /**
     * CurrencyUpdateJob constructor.
     *
     * @param \OwnPay\Core\Database $db   The database connection instance.
     * @param HttpClient|null      $http Optional HTTP client service.
     */
    public function __construct(\OwnPay\Core\Database $db, ?HttpClient $http = null)
    {
        $this->db = $db;
        $this->http = $http ?? new HttpClient(10, 5);
    }

    /**
     * Runs the exchange rate synchronisation process.
     *
     * @param bool $force If true, bypasses the manual exchange rate mode skip.
     * @return array{success: bool, updated?: int, skipped?: bool, error?: string} The synchronization result payload.
     */
    public function run(bool $force = false): array
    {
        try {
            // Load configuration settings
            $rowMode = $this->db->fetchOne(
                "SELECT `value` FROM op_system_settings WHERE `group_name` = 'general' AND `key_name` = 'exchange_rate_mode' LIMIT 1"
            );
            $mode = $rowMode['value'] ?? 'auto';

            if (!$force && $mode === 'manual') {
                return ['success' => true, 'updated' => 0, 'skipped' => true];
            }

            $rowBase = $this->db->fetchOne(
                "SELECT `value` FROM op_system_settings WHERE `group_name` = 'general' AND `key_name` = 'base_currency' LIMIT 1"
            );
            $baseCurrency = is_string($rowBase['value'] ?? null) && trim($rowBase['value']) !== '' ? strtoupper(trim($rowBase['value'])) : 'USD';

            $rowApiUrl = $this->db->fetchOne(
                "SELECT `value` FROM op_system_settings WHERE `group_name` = 'general' AND `key_name` = 'exchange_rate_api_url' LIMIT 1"
            );
            $customApiUrl = is_string($rowApiUrl['value'] ?? null) ? trim($rowApiUrl['value']) : '';

            $baseLower = strtolower($baseCurrency);
            $urlSequence = [];

            if ($customApiUrl !== '') {
                $urlSequence[] = str_replace('{currency}', $baseLower, $customApiUrl);
            }
            $urlSequence[] = "https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/{$baseLower}.json";
            $urlSequence[] = "https://latest.currency-api.pages.dev/v1/currencies/{$baseLower}.json";

            $response = null;
            $usedUrl = '';
            foreach ($urlSequence as $url) {
                try {
                    $res = $this->http->get($url);
                    if ($res['status'] === 200 && !empty($res['body'])) {
                        $response = $res;
                        $usedUrl = $url;
                        break;
                    }
                } catch (\Throwable $e) {
                    // Failover to next URL sequence
                }
            }

            if ($response === null) {
                return ['success' => false, 'error' => 'All exchange rate API service connections timed out or failed'];
            }

            $data = json_decode($response['body'], true);
            if (!is_array($data)) {
                return ['success' => false, 'error' => 'Malformed JSON exchange rate structure received'];
            }

            $rates = $data[$baseLower] ?? null;
            if (!is_array($rates)) {
                return ['success' => false, 'error' => "Exchange rates for base currency '{$baseCurrency}' not found in API response"];
            }

            // Get registered currencies to avoid DB pollution
            $registeredCurrencies = $this->db->fetchAll("SELECT code FROM op_currencies");
            $registeredSet = [];
            foreach ($registeredCurrencies as $rc) {
                if (isset($rc['code']) && is_string($rc['code'])) {
                    $registeredSet[strtoupper($rc['code'])] = true;
                }
            }

            $updated = 0;
            $this->db->execute("START TRANSACTION");

            foreach ($rates as $currency => $rate) {
                if (!is_string($currency) || !is_scalar($rate)) {
                    continue;
                }
                $currencyUpper = strtoupper($currency);
                if (!isset($registeredSet[$currencyUpper])) {
                    continue;
                }

                $exists = $this->db->fetchOne(
                    "SELECT id FROM op_exchange_rates WHERE base_currency = :base AND target_currency = :cur",
                    ['base' => $baseCurrency, 'cur' => $currencyUpper]
                );

                if ($exists) {
                    $this->db->update(
                        "UPDATE op_exchange_rates SET rate = :rate, updated_at = NOW()
                         WHERE base_currency = :base AND target_currency = :cur",
                        ['rate' => (string) $rate, 'base' => $baseCurrency, 'cur' => $currencyUpper]
                    );
                } else {
                    $this->db->insert(
                        "INSERT INTO op_exchange_rates (base_currency, target_currency, rate, updated_at)
                         VALUES (:base, :cur, :rate, NOW())",
                        ['base' => $baseCurrency, 'cur' => $currencyUpper, 'rate' => (string) $rate]
                    );
                }
                $updated++;
            }

            // Ensure base currency itself exists with rate 1.0
            $existsBase = $this->db->fetchOne(
                "SELECT id FROM op_exchange_rates WHERE base_currency = :base AND target_currency = :target",
                ['base' => $baseCurrency, 'target' => $baseCurrency]
            );
            if ($existsBase) {
                $this->db->update(
                    "UPDATE op_exchange_rates SET rate = '1.00000000', updated_at = NOW() WHERE base_currency = :base AND target_currency = :target",
                    ['base' => $baseCurrency, 'target' => $baseCurrency]
                );
            } else {
                $this->db->insert(
                    "INSERT INTO op_exchange_rates (base_currency, target_currency, rate, updated_at) VALUES (:base, :target, '1.00000000', NOW())",
                    ['base' => $baseCurrency, 'target' => $baseCurrency]
                );
            }

            $this->db->execute("COMMIT");
            return ['success' => true, 'updated' => $updated];

        } catch (\Throwable $e) {
            try {
                $this->db->execute("ROLLBACK");
            } catch (\Throwable $rollbackEx) {
                // Ignore rollback exceptions if transaction wasn't active
            }
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
