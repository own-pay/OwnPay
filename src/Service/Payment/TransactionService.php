<?php
declare(strict_types=1);

namespace OwnPay\Service\Payment;

/**
 * Transaction Service  
 *
 * Handles transaction lifecycle: approve, reject, refund,
 * auto-matching, IPN dispatch, and status transitions.
 */
class TransactionService
{
    /**
     * Return the database table prefix from the environment.
     */
    private static function dbPrefix(): string
    {
        return $_ENV['DB_PREFIX'] ?? $_SERVER['DB_PREFIX'] ?? 'op_';
    }

    public static function op_set_transaction_status($transactionid, $status = '', $gateway_id = '', $trxid = '', $source_info = [])
{
    $db_prefix = self::dbPrefix();

    $params = [':ref' => $transactionid, ':status' => 'initiated'];

    $response_transaciton = CrudService::select($db_prefix . 'transaction', 'WHERE ref = :ref AND status = :status', '* FROM', $params);

    if ($response_transaciton['status'] === true) {
        if ($status == "canceled") {
            $columns = ['status', 'updated_date'];
            $values = ['canceled', getCurrentDatetime('Y-m-d H:i:s')];
            $condition = 'id = :where_id';
            $whereParams = [':where_id' => (int) $response_transaciton['response'][0]['id']];

            CrudService::update($db_prefix . 'transaction', $columns, $values, $condition, $whereParams);

            return true;
        }

        if ($status == "completed") {
            $final_source_info = '--';

            if (is_array($source_info) && !empty($source_info)) {
                $valid = true;

                foreach ($source_info as $item) {
                    if (
                        !is_array($item) ||
                        empty($item['label']) ||
                        empty($item['value'])
                    ) {
                        $valid = false;
                        break;
                    }
                }

                if ($valid) {
                    $final_source_info = json_encode($source_info, JSON_UNESCAPED_UNICODE);
                }
            }

            $params = [':gateway_id' => $gateway_id, ':brand_id' => $response_transaciton['response'][0]['brand_id'], ':status' => 'active'];

            $response_gateway = CrudService::select($db_prefix . 'gateways', 'WHERE gateway_id = :gateway_id AND brand_id = :brand_id AND status = :status', '* FROM', $params);
            if ($response_gateway['status'] == true) {
                $currencyRates = [];

                $currencyRes = CrudService::select($db_prefix . 'currency', 'WHERE brand_id = :brand_id', '* FROM', [':brand_id' => $response_gateway['response'][0]['brand_id']]);

                if (!empty($currencyRes['response'])) {
                    foreach ($currencyRes['response'] as $c) {
                        $currencyRates[$c['code']] = money_sanitize($c['rate']);
                    }
                }

                $txnAmount = money_sanitize($response_transaciton['response'][0]['amount']);
                $txnCurrency = $response_transaciton['response'][0]['currency'];
                $gatewayCurrency = $response_gateway['response'][0]['currency'];

                if ($txnCurrency === $gatewayCurrency) {
                    $convertedAmount = $txnAmount;
                } else {
                    if (isset($currencyRates[$gatewayCurrency])) {
                        $convertedAmount = money_div($txnAmount, $currencyRates[$gatewayCurrency]);
                    } else {
                        $convertedAmount = "0";
                    }
                }

                $fixed_discount = money_sanitize($response_gateway['response'][0]['fixed_discount']);
                $percentage_discount = money_sanitize($response_gateway['response'][0]['percentage_discount']);

                $fixed_charge = money_sanitize($response_gateway['response'][0]['fixed_charge']);
                $percentage_charge = money_sanitize($response_gateway['response'][0]['percentage_charge']);

                $percentageDiscountAmount = money_div(money_mul($convertedAmount, $percentage_discount, 8), "100", 8);
                $totalDiscount = money_add($fixed_discount, $percentageDiscountAmount, 8);

                $percentageChargeAmount = money_div(money_mul($convertedAmount, $percentage_charge, 8), "100", 8);
                $totalProcessingFee = money_add($fixed_charge, $percentageChargeAmount, 8);

                $convertedAmount = money_add(money_sub($convertedAmount, $totalDiscount, 8), $totalProcessingFee, 8);

                if ($txnCurrency !== $gatewayCurrency && isset($currencyRates[$gatewayCurrency])) {
                    $totalDiscount = money_mul($totalDiscount, $currencyRates[$gatewayCurrency]);
                    $totalProcessingFee = money_mul($totalProcessingFee, $currencyRates[$gatewayCurrency]);
                }
            } else {
                return false;
            }

            $columns = ['processing_fee', 'discount_amount', 'local_net_amount', 'local_currency', 'gateway_id', 'status', 'trx_id', 'source_info', 'updated_date'];
            $values = [$totalProcessingFee, $totalDiscount, $convertedAmount, $response_gateway['response'][0]['currency'], $gateway_id, 'completed', $trxid, $final_source_info, getCurrentDatetime('Y-m-d H:i:s')];
            $condition = 'id = :where_id';
            $whereParams = [':where_id' => (int) $response_transaciton['response'][0]['id']];

            CrudService::update($db_prefix . 'transaction', $columns, $values, $condition, $whereParams);

            $params = [':ref' => $response_transaciton['response'][0]['ref'], ':status' => 'completed'];

            $response_transaction = CrudService::select($db_prefix . 'transaction', 'WHERE ref = :ref AND status = :status ', '* FROM', $params);

            $metadata = json_decode($response_transaction['response'][0]['metadata'], true) ?: [];

            $response_gateway = CrudService::select($db_prefix . 'gateways', 'WHERE brand_id = :brand_id AND gateway_id = :gateway_id', '* FROM', [':brand_id' => $response_transaction['response'][0]['brand_id'], ':gateway_id' => $gateway_id]);

            $gateway = $response_gateway['response'][0]['display'] ?? '';

            $customer_info = json_decode($response_transaction['response'][0]['customer_info'], true) ?: [];

            $response_brand = CrudService::select($db_prefix . 'brands', 'WHERE brand_id = :brand_id', '* FROM', [':brand_id' => $response_transaction['response'][0]['brand_id']]);

            $net = money_sub(money_add($response_transaction['response'][0]['amount'], $response_transaction['response'][0]['processing_fee']), $response_transaction['response'][0]['discount_amount']);

            $all_transactions = [];

            $all_transactions[] = [
                "op_id" => $response_transaction['response'][0]['ref'],
                "full_name" => $customer_info['name'] ?? 'N/A',
                "email_address" => $customer_info['email'] ?? 'N/A',
                "mobile_number" => $customer_info['mobile'] ?? 'N/A',
                "gateway" => $gateway,
                "amount" => money_round($response_transaction['response'][0]['amount']),
                "fee" => money_round($response_transaction['response'][0]['processing_fee']),
                "discount_amount" => money_round($response_transaction['response'][0]['discount_amount']),
                "total" => money_round($net),
                "local_net_amount" => money_round($response_transaction['response'][0]['local_net_amount']),
                "currency" => $response_transaction['response'][0]['currency'],
                "local_currency" => $response_transaction['response'][0]['local_currency'],
                "metadata" => $metadata, // ← AS-IS
                "sender" => $response_transaction['response'][0]['sender'],
                "transaction_id" => $response_transaction['response'][0]['trx_id'],
                "status" => $response_transaction['response'][0]['status'],
                "date" => convertUTCtoUserTZ($response_transaction['response'][0]['created_date'], empty($response_brand['response'][0]['timezone']) ? 'Asia/Dhaka' : $response_brand['response'][0]['timezone'], "M d, Y h:i A")
            ];

            if (empty($response_transaction['response'][0]['webhook_url'])) {

            } else {
                $ipnData = [
                    "op_id" => $response_transaction['response'][0]['ref'],
                    "full_name" => $customer_info['name'] ?? 'N/A',
                    "email_address" => $customer_info['email'] ?? 'N/A',
                    "mobile_number" => $customer_info['mobile'] ?? 'N/A',
                    "gateway" => $gateway,
                    "amount" => money_round($response_transaction['response'][0]['amount']),
                    "fee" => money_round($response_transaction['response'][0]['processing_fee']),
                    "discount_amount" => money_round($response_transaction['response'][0]['discount_amount']),
                    "total" => money_round($net),
                    "local_net_amount" => money_round($response_transaction['response'][0]['local_net_amount']),
                    "currency" => $response_transaction['response'][0]['currency'],
                    "local_currency" => $response_transaction['response'][0]['local_currency'],
                    "metadata" => $metadata, // ← AS-IS
                    "sender" => $response_transaction['response'][0]['sender'],
                    "transaction_id" => $response_transaction['response'][0]['trx_id'],
                    "status" => $response_transaction['response'][0]['status'],
                    "date" => convertUTCtoUserTZ($response_transaction['response'][0]['created_date'], empty($response_brand['response'][0]['timezone']) ? 'Asia/Dhaka' : $response_brand['response'][0]['timezone'], "M d, Y h:i A")
                ];

                $payload = json_encode($ipnData, JSON_UNESCAPED_UNICODE);

                $jobs = [
                    [
                        'id' => random_int(100000000, PHP_INT_MAX),
                        'url' => $response_transaction['response'][0]['webhook_url'],
                        'payload' => json_decode($payload, true),
                    ]
                ];

                $results = sendIPNMulti($jobs);

                foreach ($jobs as $job) {
                    $code = $results[$job['id']] ?? 0;
                    $status = ($code === 200) ? 'completed' : 'pending';

                    if ($status == 'completed') {

                    } else {
                        $columns = ['ref', 'brand_id', 'payload', 'url', 'created_date', 'updated_date'];
                        $values = [random_int(100000000, PHP_INT_MAX), $response_brand['response'][0]['brand_id'], $payload, $response_transaction['response'][0]['webhook_url'], getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                        CrudService::insert($db_prefix . 'webhook_log', $columns, $values);
                    }
                }
            }

            if (!empty($all_transactions)) {
                do_action('transactions.updated', $all_transactions);
            }

            return true;
        }

    } else {
        return false;
    }
}
}
