<?php
declare(strict_types=1);

namespace OwnPay\Service;

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
        $providers = [
            'bkash' => [
                'name' => 'bKash',
                'currency' => 'BDT',
                'balance_verify' => 'true',
                'senders' => ['bkash'],
            ],
            'nagad' => [
                'name' => 'Nagad',
                'currency' => 'BDT',
                'balance_verify' => 'true',
                'senders' => ['nagad'],
            ],
            'rocket' => [
                'name' => 'Rocket',
                'currency' => 'BDT',
                'balance_verify' => 'true',
                'senders' => ['16216'],
            ],
            'upay' => [
                'name' => 'Upay',
                'currency' => 'BDT',
                'balance_verify' => 'true',
                'senders' => ['upay'],
            ],
            'tap' => [
                'name' => 'Tap',
                'currency' => 'USD',
                'balance_verify' => 'true',
                'senders' => ['tap.'],
            ],
            'cellfin' => [
                'name' => 'Cellfin',
                'currency' => 'BDT',
                'balance_verify' => 'false',
                'senders' => ['ibbl .'],
            ],
            'okwallet' => [
                'name' => 'Ok Wallet',
                'currency' => 'BDT',
                'balance_verify' => 'true',
                'senders' => ['01847-348685'],
            ],
            'mcash' => [
                'name' => 'mCash',
                'currency' => 'BDT',
                'balance_verify' => 'true',
                'senders' => ['16259'],
            ],
            'pathaopay' => [
                'name' => 'Pathao Pay',
                'currency' => 'BDT',
                'balance_verify' => 'true',
                'senders' => ['pathaopay'],
            ],
            'telecash' => [
                'name' => 'TeleCash',
                'currency' => 'BDT',
                'balance_verify' => 'true',
                'senders' => ['telecash'],
            ],
            'ipay' => [
                'name' => 'Ipay',
                'currency' => 'BDT',
                'balance_verify' => 'true',
                'senders' => ['09638-900800'],
            ],
        ];

        // ── Plugin hook: allow addons to register additional MFS providers ──
        // Usage: add_filter('mfs.providers', function($providers) {
        //     $providers['myprovider'] = [
        //         'name' => 'My Provider',
        //         'currency' => 'BDT',
        //         'balance_verify' => 'true',
        //         'senders' => ['myprovider', 'my-provider-alt'],
        //     ];
        //     return $providers;
        // });
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

        $formats = [
            'bkash' => [
                // 🔹 PERSONAL (Most specific first)
                [
                    'type' => 'Personal',
                    'priority' => 100,
                    'pattern' => '/You have received Tk ([\d,.]+) from (\d+)\.(?:\s*Ref[:\-]?\s*(\S+))? Fee Tk ([\d,.]+)\. Balance Tk ([\d,.]+)\. TrxID ([A-Z0-9]+) at ([\d\/:\s]+)/i',
                    'map' => ['amount', 'sender', 'ref', 'fee', 'balance', 'trxid', 'datetime']
                ],
                [
                    'type' => 'Personal',
                    'priority' => 90,
                    'pattern' => '/Cash In Tk ([\d,.]+) from (\d+) successful\. Fee Tk ([\d,.]+)\. Balance Tk ([\d,.]+)\. TrxID ([A-Z0-9]+) at ([\d\/:\s]+)/i',
                    'map' => ['amount', 'sender', 'fee', 'balance', 'trxid', 'datetime']
                ],
                [
                    'type' => 'Merchant',
                    'priority' => 80,
                    'pattern' => '/You have received payment Tk ([\d,.]+) from (\d+)\.(?:\s*Ref[:\-]?\s*(\S+))? Fee Tk ([\d,.]+)\. Balance Tk ([\d,.]+)\. TrxID ([A-Z0-9]+) at ([\d\/:\s]+)/i',
                    'map' => ['amount', 'sender', 'ref', 'fee', 'balance', 'trxid', 'datetime']
                ],

                /*
                // 🔹 AGENT
                [
                    'type'     => 'Agent',
                    'priority' => 60,
                    'pattern'  => '/Cash In Tk ([\d,.]+) from (\d+) successful\. Balance Tk ([\d,.]+)\. TrxID ([A-Z0-9]+)/i',
                    'map'      => ['amount', 'sender', 'balance', 'trxid']
                ],*/
            ],
            'nagad' => [
                // 🔹 PERSONAL (Most specific first)
                [
                    'type' => 'Personal',
                    'priority' => 100,
                    'pattern' => '/Money Received\. Amount: Tk ([\d,.]+) Sender: (\d+)(?:\s*Ref[:\-]?\s*(\S+))? TxnID: ([A-Z0-9]+) Balance: Tk ([\d,.]+) ([\d\/:\s]+)/i',
                    'map' => ['amount', 'sender', 'ref', 'trxid', 'balance', 'datetime']
                ],
                [
                    'type' => 'Personal',
                    'priority' => 90,
                    'pattern' => '/Cash In Received\. Amount: Tk ([\d,.]+) Uddokta: (\d+) TxnID: ([A-Z0-9]+) Balance: ([\d,.]+) ([\d\/:\s]+)/i',
                    'map' => ['amount', 'sender', 'trxid', 'balance', 'datetime']
                ],

                /*
                [
                    'type'     => 'Merchant',
                    'priority' => 70,
                    'pattern'  => '/received a payment of Tk ([\d,.]+) from (\d+)\. TrxID ([A-Z0-9]+) at ([\d\/:\s]+)/i',
                    'map'      => ['amount', 'sender', 'trxid', 'datetime']
                ],

                // 🔹 AGENT
                [
                    'type'     => 'Agent',
                    'priority' => 60,
                    'pattern'  => '/Cash In Tk ([\d,.]+) from (\d+) successful\. Balance Tk ([\d,.]+)\. TrxID ([A-Z0-9]+)/i',
                    'map'      => ['amount', 'sender', 'balance', 'trxid']
                ],*/
            ],
            'rocket' => [
                // 🔹 PERSONAL (Most specific first)
                [
                    'type' => 'Personal',
                    'priority' => 100,
                    'pattern' => '/Tk([\d,.]+) received from A\/C:([*\d]+) Fee:Tk([\d,.]+)\, Your A\/C Balance: Tk([\d,.]+) TxnId:([A-Z0-9]+)(?: Date:([\w\-:\s]+))?/i',
                    'map' => ['amount', 'sender', 'fee', 'balance', 'trxid', 'datetime']
                ],

                /*
                [
                    'type'     => 'Merchant',
                    'priority' => 70,
                    'pattern'  => '/received a payment of Tk ([\d,.]+) from (\d+)\. TrxID ([A-Z0-9]+) at ([\d\/:\s]+)/i',
                    'map'      => ['amount', 'sender', 'trxid', 'datetime']
                ],

                // 🔹 AGENT
                [
                    'type'     => 'Agent',
                    'priority' => 60,
                    'pattern'  => '/Cash In Tk ([\d,.]+) from (\d+) successful\. Balance Tk ([\d,.]+)\. TrxID ([A-Z0-9]+)/i',
                    'map'      => ['amount', 'sender', 'balance', 'trxid']
                ],*/
            ],
            'upay' => [
                // 🔹 PERSONAL (Most specific first)
                [
                    'type' => 'Personal',
                    'priority' => 100,
                    'pattern' => '/Tk\. ([\d,.]+) has been received from (\d+)\.(?:\s*Ref[:\-]?\s*(\S+))? Balance Tk\. ([\d,.]+)\. TrxID ([A-Z0-9]+) at ([\d\/:\s]+)\./i',
                    'map' => ['amount', 'sender', 'ref', 'balance', 'trxid', 'datetime']
                ],

                /*
                [
                    'type'     => 'Merchant',
                    'priority' => 70,
                    'pattern'  => '/received a payment of Tk ([\d,.]+) from (\d+)\. TrxID ([A-Z0-9]+) at ([\d\/:\s]+)/i',
                    'map'      => ['amount', 'sender', 'trxid', 'datetime']
                ],

                // 🔹 AGENT
                [
                    'type'     => 'Agent',
                    'priority' => 60,
                    'pattern'  => '/Cash In Tk ([\d,.]+) from (\d+) successful\. Balance Tk ([\d,.]+)\. TrxID ([A-Z0-9]+)/i',
                    'map'      => ['amount', 'sender', 'balance', 'trxid']
                ],*/
            ],
            'tap' => [
                // 🔹 PERSONAL (Most specific first)
                [
                    'type' => 'Personal',
                    'priority' => 100,
                    'pattern' => '/Received Tk ([\d,.]+) from (\d+)\. Balance Tk\. ([\d,.]+)\. TxID: ([A-Z0-9]+)\./i',
                    'map' => ['amount', 'sender', 'balance', 'trxid']
                ],

                /*
                [
                    'type'     => 'Merchant',
                    'priority' => 70,
                    'pattern'  => '/received a payment of Tk ([\d,.]+) from (\d+)\. TrxID ([A-Z0-9]+) at ([\d\/:\s]+)/i',
                    'map'      => ['amount', 'sender', 'trxid', 'datetime']
                ],

                // 🔹 AGENT
                [
                    'type'     => 'Agent',
                    'priority' => 60,
                    'pattern'  => '/Cash In Tk ([\d,.]+) from (\d+) successful\. Balance Tk ([\d,.]+)\. TrxID ([A-Z0-9]+)/i',
                    'map'      => ['amount', 'sender', 'balance', 'trxid']
                ],*/
            ],
            'cellfin' => [
                // 🔹 PERSONAL (Most specific first)
                [
                    'type' => 'Personal',
                    'priority' => 100,
                    'pattern' => '/Islami Bank CellFin Received ([\d,.]+) Tk From CellFin: (\d+) To CellFin: (\d+) TrxId: ([A-Z0-9]+)/i',
                    'map' => ['amount', 'sender', 'receiver', 'trxid']
                ],

                /*
                [
                    'type'     => 'Merchant',
                    'priority' => 70,
                    'pattern'  => '/received a payment of Tk ([\d,.]+) from (\d+)\. TrxID ([A-Z0-9]+) at ([\d\/:\s]+)/i',
                    'map'      => ['amount', 'sender', 'trxid', 'datetime']
                ],

                // 🔹 AGENT
                [
                    'type'     => 'Agent',
                    'priority' => 60,
                    'pattern'  => '/Cash In Tk ([\d,.]+) from (\d+) successful\. Balance Tk ([\d,.]+)\. TrxID ([A-Z0-9]+)/i',
                    'map'      => ['amount', 'sender', 'balance', 'trxid']
                ],*/
            ],
            'okwallet' => [
                // 🔹 PERSONAL (Most specific first)
                [
                    'type' => 'Personal',
                    'priority' => 100,
                    'pattern' => '/\(OK Wallet\) Successfully received Tk ([\d,.]+) from A\/C (\d+)\.(?:\s*Ref[:\-]?\s*(\S+))? Balance Tk ([\d,.]+)\. TrxID ([A-Z0-9]+)/i',
                    'map' => ['amount', 'sender', 'ref', 'balance', 'trxid']
                ],

                /*
                [
                    'type'     => 'Merchant',
                    'priority' => 70,
                    'pattern'  => '/received a payment of Tk ([\d,.]+) from (\d+)\. TrxID ([A-Z0-9]+) at ([\d\/:\s]+)/i',
                    'map'      => ['amount', 'sender', 'trxid', 'datetime']
                ],

                // 🔹 AGENT
                [
                    'type'     => 'Agent',
                    'priority' => 60,
                    'pattern'  => '/Cash In Tk ([\d,.]+) from (\d+) successful\. Balance Tk ([\d,.]+)\. TrxID ([A-Z0-9]+)/i',
                    'map'      => ['amount', 'sender', 'balance', 'trxid']
                ],*/
            ],
            'mcash' => [
                // 🔹 PERSONAL (Most specific first)
                [
                    'type' => 'Personal',
                    'priority' => 100,
                    'pattern' => '/IBBL mCash You have received Tk: ([\d,.]+) From: (\d+)(?:\s*Reference:\s*(\S*))? Balance Tk: ([\d,.]+) TrxID: ([A-Z0-9]+)/i',
                    'map' => ['amount', 'sender', 'ref', 'balance', 'trxid']
                ],

                /*
                [
                    'type'     => 'Merchant',
                    'priority' => 70,
                    'pattern'  => '/received a payment of Tk ([\d,.]+) from (\d+)\. TrxID ([A-Z0-9]+) at ([\d\/:\s]+)/i',
                    'map'      => ['amount', 'sender', 'trxid', 'datetime']
                ],

                // 🔹 AGENT
                [
                    'type'     => 'Agent',
                    'priority' => 60,
                    'pattern'  => '/Cash In Tk ([\d,.]+) from (\d+) successful\. Balance Tk ([\d,.]+)\. TrxID ([A-Z0-9]+)/i',
                    'map'      => ['amount', 'sender', 'balance', 'trxid']
                ],*/
            ],
            'pathaopay' => [
                // 🔹 PERSONAL (Most specific first)
                [
                    'type' => 'Personal',
                    'priority' => 100,
                    'pattern' => '/You have received BDT ([\d,.]+) from (\+?\d+)\. Balance BDT ([\d,.]+) TrxID ([A-Z0-9]+)/i',
                    'map' => ['amount', 'sender', 'balance', 'trxid']
                ],

                /*
                [
                    'type'     => 'Merchant',
                    'priority' => 70,
                    'pattern'  => '/received a payment of Tk ([\d,.]+) from (\d+)\. TrxID ([A-Z0-9]+) at ([\d\/:\s]+)/i',
                    'map'      => ['amount', 'sender', 'trxid', 'datetime']
                ],

                // 🔹 AGENT
                [
                    'type'     => 'Agent',
                    'priority' => 60,
                    'pattern'  => '/Cash In Tk ([\d,.]+) from (\d+) successful\. Balance Tk ([\d,.]+)\. TrxID ([A-Z0-9]+)/i',
                    'map'      => ['amount', 'sender', 'balance', 'trxid']
                ],*/
            ],

        ];

        // ── Plugin hook: allow addons to register additional MFS message formats ──
        // Usage: add_filter('mfs.formats', function($formats) {
        //     $formats['myprovider'] = [
        //         [
        //             'type' => 'Personal',
        //             'priority' => 100,
        //             'pattern' => '/Received Tk ([\d,.]+) from (\d+)\. TrxID ([A-Z0-9]+)/i',
        //             'map' => ['amount', 'sender', 'trxid'],
        //         ],
        //     ];
        //     return $formats;
        // });
        $formats = apply_filters('mfs.formats', $formats);

        if (!isset($formats[strtolower($mfs)])) {
            return false;
        }

        // 🔥 Sort by priority (DESC)
        usort($formats[strtolower($mfs)], fn($a, $b) => $b['priority'] <=> $a['priority']);

        foreach ($formats[strtolower($mfs)] as $format) {
            if (preg_match($format['pattern'], $message, $matches)) {

                $data = [
                    'mfs' => strtolower($mfs),
                    'type' => $format['type'],
                    'raw' => $message,
                ];

                // Map values safely
                foreach ($format['map'] as $i => $key) {
                    $data[$key] = $matches[$i + 1] ?? null;
                }

                // Normalize numbers
                foreach (['amount', 'balance', 'fee'] as $field) {
                    if (isset($data[$field]) && $data[$field] !== null) {
                        $data[$field] = str_replace(',', '', $data[$field]);
                    }
                }

                return $data;
            }
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
