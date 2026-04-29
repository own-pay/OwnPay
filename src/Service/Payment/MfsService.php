<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

/**
 * MFS (Mobile Financial Services) Service
 *
 * Handles MFS provider whitelisting, message verification/parsing,
 * and balance reconciliation via longest-chain algorithm.
 *
 * Extensible via hooks:
 *   add_filter('mfs.providers', fn($providers) => array_merge($providers, [...]));
 *   add_filter('mfs.formats',   fn($formats)   => array_merge($formats, [...]));
 */
class MfsService
{
    /**
     * Return the database table prefix from the environment.
     */
    private static function dbPrefix(): string
    {
        return $_ENV['DB_PREFIX'] ?? $_SERVER['DB_PREFIX'] ?? 'op_';
    }

    public static function senderWhitelist(?string $sender = null, ?string $providerKey = null, string $mode = 'provider', ?string $providerName = null)
    {
        $repo = new \OwnPay\Repository\SmsTemplateRepository();
        $dbProviders = $repo->findAllProviders();
        
        $providers = [];
        foreach ($dbProviders as $row) {
            $key = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', $row['provider_name']));
            if (!isset($providers[$key])) {
                $providers[$key] = [
                    'name' => $row['provider_name'],
                    'currency' => $row['currency'] ?? 'BDT',
                    'balance_verify' => ((int)$row['balance_verify'] === 1) ? 'true' : 'false',
                    'senders' => [],
                ];
            }
            if (!in_array($row['sender_pattern'], $providers[$key]['senders'])) {
                $providers[$key]['senders'][] = $row['sender_pattern'];
            }
        }

        // ── Plugin hook: allow addons to register additional MFS providers ──
        $providers = apply_filters('mfs.providers', $providers);

        if ($mode === 'senders') {
            $allSenders = [];
            foreach ($providers as $provider) {
                $allSenders = array_merge($allSenders, $provider['senders']);
            }
            $allSenders = array_values(array_unique($allSenders));
            return $allSenders;
        }

        if ($sender !== null) {
            $sender = strtolower(trim($sender));
            foreach ($providers as $key => $provider) {
                foreach ($provider['senders'] as $s) {
                    if (strtolower($s) === $sender) {
                        return [
                            'provider_key' => $key,
                            'name' => $provider['name'],
                            'currency' => $provider['currency'],
                            'balance_verify' => $provider['balance_verify'],
                            'sender' => $sender,
                        ];
                    }
                }
            }
            return false;
        }

        if ($providerKey !== null) {
            return $providers[$providerKey] ?? false;
        }

        if ($providerName !== null) {
            $providerName = strtolower(trim($providerName));
            foreach ($providers as $key => $provider) {
                if (strtolower($provider['name']) === $providerName) {
                    return [
                        'provider_key' => $key,
                        'name' => $provider['name'],
                        'currency' => $provider['currency'],
                        'balance_verify' => $provider['balance_verify'],
                        'senders' => $provider['senders'],
                    ];
                }
            }
            return false;
        }

        return $providers;
    }

    public static function MFSMessageVerified(string $mfs, string $message)
    {
        $message = trim(preg_replace('/\s+/', ' ', $message));

        $repo = new \OwnPay\Repository\SmsTemplateRepository();
        $dbTemplates = $repo->findBySender($mfs);

        // ── Plugin hook: allow addons to register additional SMS templates ──
        $templates = apply_filters('mfs.templates', $dbTemplates);

        if (empty($templates)) {
            return false;
        }

        $parser = new \OwnPay\Service\Sms\SmsRegexParser();
        $parsed = $parser->parse($message, $templates);

        if ($parsed) {
            return [
                'mfs' => strtolower($mfs),
                'type' => ucfirst(strtolower($parsed['parsed_type'] ?? 'Personal')),
                'raw' => $message,
                'amount' => $parsed['parsed_amount'],
                'sender' => $parsed['parsed_sender'],
                'trxid' => $parsed['parsed_trx_id'],
                'balance' => $parsed['parsed_balance'],
                'fee' => null, // Legacy fields not typically captured by dynamic regex
                'datetime' => null,
                'ref' => null,
            ];
        }

        return false;
    }

    public static function reconcileByLongestChain($device_id, $sender_key, $type)
    {
        $db_prefix = self::dbPrefix();

        $resBalance = CrudService::select(
            $db_prefix . 'balance_verification',
            'WHERE device_id = :device_id AND sender_key = :sender_key AND type = :type',
            '* FROM',
            [':device_id' => $device_id, ':sender_key' => $sender_key, ':type' => $type]
        );

        $canonicalBalanceInt = 0;

        if (!empty($resBalance['response'][0]['current_balance'])) {
            $canonicalBalanceInt = moneyToInt($resBalance['response'][0]['current_balance']);
        }

        $res = CrudService::select(
            $db_prefix . 'sms_data',
            'WHERE device_id = :device_id AND sender_key = :sender_key AND type = :type AND status IN ("approved","awaiting-review") AND source IN ("app") ORDER BY id ASC',
            '* FROM',
            [':device_id' => $device_id, ':sender_key' => $sender_key, ':type' => $type]
        );

        $smsList = $res['response'] ?? [];
        if (count($smsList) < 1)
            return;

        foreach ($smsList as &$s) {
            $amountInt = moneyToInt($s['amount'] ?? "0");
            $balanceInt = moneyToInt($s['balance'] ?? "0");

            if ($amountInt <= 0 || $balanceInt <= 0)
                continue;

            $s['amount_int'] = $amountInt;
            $s['balance_int'] = $balanceInt;

            $s['prev'] = $balanceInt - $amountInt;
            $s['bal'] = $balanceInt;
        }
        unset($s);

        $next = [];

        foreach ($smsList as $s) {
            if (!isset($s['prev']))
                continue;
            $next[$s['prev']][] = $s;
        }

        $bestChain = [];
        $queue = [$canonicalBalanceInt];

        while (!empty($queue)) {

            $current = array_shift($queue);

            if (!isset($next[$current]))
                continue;

            foreach ($next[$current] as $sms) {
                $chain = [];
                $tempCurrent = $current;
                $tempNext = $next;

                while (isset($tempNext[$tempCurrent]) && count($tempNext[$tempCurrent]) > 0) {

                    $smsInChain = array_shift($tempNext[$tempCurrent]);

                    $chain[] = $smsInChain;
                    $tempCurrent = $smsInChain['bal'];
                }

                if (count($chain) > count($bestChain)) {
                    $bestChain = $chain;
                }
            }
        }

        if (count($bestChain) < 1)
            return;

        $idsToApprove = array_column($bestChain, 'id');

        if (!empty($idsToApprove)) {
            // Build named params for IN clause (ids are trusted — ints from array_column)
            $inPlaceholders = [];
            $idParams = [];
            foreach ($idsToApprove as $idx => $idVal) {
                $placeholder = ":approve_id_{$idx}";
                $inPlaceholders[] = $placeholder;
                $idParams[$placeholder] = $idVal;
            }
            CrudService::update(
                $db_prefix . 'sms_data',
                ['status', 'reason', 'updated_date'],
                ['approved', '--', getCurrentDatetime('Y-m-d H:i:s')],
                'id IN (' . implode(',', $inPlaceholders) . ')',
                $idParams
            );
        }

        $last = end($bestChain);
        $finalBalanceInt = $last['bal'];

        $finalBalance = intToMoney($finalBalanceInt, 2);

        CrudService::update(
            $db_prefix . 'balance_verification',
            ['current_balance', 'updated_date'],
            [$finalBalance, getCurrentDatetime('Y-m-d H:i:s')],
            'device_id = :device_id AND sender_key = :sender_key AND type = :type',
            [':device_id' => $device_id, ':sender_key' => $sender_key, ':type' => $type]
        );
    }
}
