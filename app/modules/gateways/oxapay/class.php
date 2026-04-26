<?php

declare(strict_types=1);

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;

class OxapayGateway implements GatewayAdapterInterface
{
    use GatewayDefaults;
        public function info(): array
    {
            return [
                'title'       => 'OxaPay Gateway',
                'logo'        => 'assets/logo.jpg',
                'currency'        => 'USD',
                'tab'        => 'global',

                'gateway_type'        => 'api',
            ];
        }

        public function color(): array
    {
            return [
                'primary_color'        => '#1a34c2',
                'text_color'        => '#FFFFFF',
                'btn_color'        => '#1a34c2',
                'btn_text_color'        => '#FFFFFF',
            ];
        }

        public function fields(): array
    {
            return [
                [
                    'name'  => 'api_key',
                    'label' => 'Merchant Api Key',
                    'type'  => 'text',
                ],
                [
                    'name'  => 'fee_paid_by_payer',
                    'label' => 'Fee Paid By',
                    'type'  => 'select',
                    'options' => [
                        '0'  => 'Merchant',
                        '1' => 'Payer',
                    ],
                    'value' => '1',
                    'required' => true,
                    'multiple' => false,
                ],
                [
                    'name'  => 'under_paid_coverage',
                    'label' => 'Under Paid Coverage',
                    'type'  => 'text',
                    'value' => '0',
                ],
                [
                    'name'  => 'mixed_payment',
                    'label' => 'Mixed Payment',
                    'type'  => 'select',
                    'options' => [
                        'allow'  => 'Allow',
                        'disallow' => 'Disallow',
                    ],
                    'value' => 'disallow',
                    'required' => true,
                    'multiple' => false,
                ],
                [
                    'name'  => 'mode',
                    'label' => 'Mode',
                    'type'  => 'select',
                    'options' => [
                        'live'  => 'Live',
                        'sandbox' => 'Sandbox',
                    ],
                    'value' => 'live',
                    'required' => true,
                    'multiple' => false,
                ],
            ];
        }

        public function process_payment(array $data = []): void
    {
            echo '<center><div class="spinner-border text-primary m-3 loading-123412341234" role="status"><span class="visually-hidden">Loading...</span></div></center>';

            $url = "https://api.oxapay.com/v1/payment/invoice";

            $datas = [
                "amount" => $data['transaction']['local_net_amount'],
                "currency" => $data['transaction']['local_currency'],
                "lifetime" => 30,
                "fee_paid_by_payer" => ($data['options']['fee_paid_by_payer'] ?? '0'),
                "under_paid_coverage" => ($data['options']['under_paid_coverage'] ?? '0'),
                "to_currency" => "USDT",
                "auto_withdrawal" => false,
                "mixed_payment" => (($data['options']['mixed_payment'] ?? 'disallow') === 'allow') ? true : false,
                "callback_url" => op_ipn_url($data['gateway']['gateway_id']),
                "return_url" => op_callback_url(),
                "email" => $data['transaction']['customer']['email'],
                "order_id" => rand().'-BP-'.$data['transaction']['ref'],
                "sandbox" => (($data['options']['mode'] ?? 'sandbox') === 'live') ? false : true
            ];

            $ch = curl_init($url);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($datas),
                CURLOPT_HTTPHEADER => [
                    "merchant_api_key: ".($data['options']['api_key'] ?? ''),
                    "Content-Type: application/json"
                ],
            ]);

            $response = curl_exec($ch);

            curl_close($ch);

            $response_de = json_decode($response, true);

            if(isset($response_de['data']['payment_url'])){
                set_env('oxapay-gateway-op_'.$data['transaction']['ref'], $response_de['data']['track_id']);

                echo '<script nonce="' . ($csp_nonce ?? '') . '">location.href="' . $response_de['data']['payment_url'] . '";</script>';
            }else{
                echo '<div class="alert alert-danger" role="alert">'.$response.'</div> <style>.loading-123412341234{display: none;}</style>';
            }
        }

        public function callback(array $data = []): void
    {
            echo '<center><div class="spinner-border text-primary m-3 loading-123412341234" role="status"><span class="visually-hidden">Loading...</span></div></center>';

            $transaction_id = (get_env('oxapay-gateway-op_'.$data['transaction']['ref']) == "") ? 0 : get_env('oxapay-gateway-op_'.$data['transaction']['ref']);

            $url = "https://api.oxapay.com/v1/payment/" . $transaction_id;

            $ch = curl_init($url);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => "GET",
                CURLOPT_HTTPHEADER     => [
                    "merchant_api_key: ".($data['options']['api_key'] ?? ''),
                    "Content-Type: application/json"
                ],
            ]);

            $response = curl_exec($ch);

            curl_close($ch);

            $response_de = json_decode($response, true);
            
            $status = $response_de['data']['status'] ?? '';

            if($status == "paid"){
                $parts = explode('-BP-', $response_de['data']['order_id']);
                $order_id = $parts[1] ?? '';

                $track_id = $response_de['data']['track_id'];

                if($data['transaction']['local_net_amount'] == $response_de['data']['amount']){
                    if($order_id == $data['transaction']['ref']){
                        op_set_transaction_status($data['transaction']['ref'], 'completed', $data['gateway']['gateway_id'], $track_id);

                        echo '<script nonce="' . ($csp_nonce ?? '') . '">location.reload();</script>';
                    }else{
                        echo "<center>Payment not completed or failed!</center> <style>.loading-123412341234{display: none;}</style>";
                    }
                }else{
                    echo '<div class="alert alert-danger" role="alert">Expected amount and paid amount do not match.</div> <style>.loading-123412341234{display: none;}</style>';
                }
            }else{
                echo '<div class="alert alert-danger" role="alert">Payment not completed or failed!</div> <style>.loading-123412341234{display: none;}</style>';
            }
        }

        function ipn($data = []){
            $postData = file_get_contents('php://input');
            $data = json_decode($postData, true);

            $data_type = $data['type'] ?? '';

            if ($data_type === 'invoice') {
                $apiSecretKey = ($data['gateway']['options']['api_key'] ?? '');

                $hmacHeader = $_SERVER['HTTP_HMAC'];
                $calculatedHmac = hash_hmac('sha512', $postData, $apiSecretKey);

                if ($calculatedHmac === $hmacHeader) {
                    $url = "https://api.oxapay.com/v1/payment/" . $data['track_id'];

                    $ch = curl_init($url);

                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_CUSTOMREQUEST  => "GET",
                        CURLOPT_HTTPHEADER     => [
                            "merchant_api_key: ".($data['gateway']['options']['api_key'] ?? ''),
                            "Content-Type: application/json"
                        ],
                    ]);

                    $response = curl_exec($ch);

                    curl_close($ch);

                    $response_de = json_decode($response, true);
                    
                    $status = $response_de['data']['status'] ?? '';

                    if($status == "paid"){
                        $parts = explode('-BP-', $response_de['data']['order_id']);
                        $order_id = $parts[1] ?? '';

                        $track_id = $response_de['data']['track_id'];

                        op_set_transaction_status($order_id, 'completed', $data['gateway']['gateway_id'], $track_id);
                    }

                    http_response_code(200);
                    echo 'OK';
                } else {
                    // HMAC signature is not valid
                    // Handle the error accordingly
                    http_response_code(400);
                    echo 'Invalid HMAC signature';
                }
            } else {
                http_response_code(400);
                echo 'Invalid data.type';
                exit;
            }
        }
    }


