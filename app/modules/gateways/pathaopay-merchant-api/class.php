<?php

declare(strict_types=1);

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;

class PathaopayMerchantApiGateway implements GatewayAdapterInterface
{
    use GatewayDefaults;
        public function info(): array
    {
            return [
                'title'       => 'PathaoPay Merchant Api',
                'logo'        => 'assets/logo.jpg',
                'currency'        => 'BDT',
                'tab'        => 'mfs',

                'gateway_type'        => 'api',
            ];
        }

        public function color(): array
    {
            return [
                'primary_color'        => '#3b82de',
                'text_color'        => '#FFFFFF',
                'btn_color'        => '#3b82de',
                'btn_text_color'        => '#FFFFFF',
            ];
        }

        public function fields(): array
    {
            return [
                [
                    'name'  => 'api_key',
                    'label' => 'Api Key',
                    'type'  => 'text',
                ],
                [
                    'name'  => 'secret_key',
                    'label' => 'Secret Key',
                    'type'  => 'text',
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

            $base_url = (($data['options']['mode'] ?? 'sandbox') === 'live') ? 'https://api.pathaopay.com' : 'https://api-stage.pathaopay.com';

            $amount = (int) round($data['transaction']['local_net_amount'] * 100);

            $refid = uniqid("ORDER_").'-BP-'.$data['transaction']['ref'];

            $payload = [
                "amount" => $amount, // in paisa
                "merchant_reference_id" => $refid,
                "force_otp" => false,
                "merchant_callback_url" => op_callback_url()
            ];

            $ch = curl_init($base_url.'/api/v1/settlements/request-payment');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HEADER => true, 
                CURLOPT_HTTPHEADER => [
                    "Content-Type: application/json",
                    "Application-Key: ".($data['options']['api_key'] ?? ''),
                    "Application-Secret: ".($data['options']['secret_key'] ?? '')
                ],
                CURLOPT_POSTFIELDS => json_encode($payload)
            ]);

            $full_response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $response_headers = substr($full_response, 0, $header_size);
            $response_body = substr($full_response, $header_size);

            curl_close($ch);

            $result = json_decode($response_body, true);

            if ($httpCode === 200 && isset($result['data']['redirect_url'])) {
                $headers_array = [];
                foreach (explode("\r\n", $response_headers) as $line) {
                    if (strpos($line, ':') !== false) {
                        list($key, $value) = explode(':', $line, 2);

                        $headers_array[strtolower(trim($key))] = trim($value);
                    }
                }

                $_SESSION['op_pathaopay_reference_token'] = $headers_array['payment-reference-token'];
                $_SESSION['op_pathaopay_invoice_id'] = $result['data']['invoice_id'];

                echo '<script nonce="' . ($csp_nonce ?? '') . '">location.href="' . $result['data']['redirect_url'] . '";</script>';
            } else {
                echo "<center>".$response_body."</center> <style>.loading-123412341234{display: none;}</style>";
            }
        }

        public function callback(array $data = []): void
    {
            echo '<center><div class="spinner-border text-primary m-3 loading-123412341234" role="status"><span class="visually-hidden">Loading...</span></div></center>';

            $base_url = (($data['options']['mode'] ?? 'sandbox') === 'live') ? 'https://api.pathaopay.com' : 'https://api-stage.pathaopay.com';

            $payload = [
                "invoice_id" => $_SESSION['op_pathaopay_invoice_id'] ?? ''
            ];
            
            $ch = curl_init($base_url.'/api/v1/settlements/request-payment/capture');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    "Content-Type: application/json",
                    "Application-Key: ".($data['options']['api_key'] ?? ''),
                    "Application-Secret: ".($data['options']['secret_key'] ?? ''),
                    "Payment-Reference-Token: ".($_SESSION['op_pathaopay_reference_token'] ?? '')
                ],
                CURLOPT_POSTFIELDS => json_encode($payload)
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $result = json_decode($response, true);

            if ($httpCode === 200 && $result['data']['status'] === 'success') {
                $parts = explode('-BP-', $result['data']['merchant_reference_id']);

                if($parts[1] == $data['transaction']['ref']){
                    $moreinfo = [
                        [
                            'label' => 'Invoice ID',
                            'value' => $result['data']['invoice_id']
                        ],
                        [
                            'label' => 'Merchant Reference ID',
                            'value' => $result['data']['merchant_reference_id']
                        ]
                    ];

                    op_set_transaction_status($data['transaction']['ref'], 'completed', $data['gateway']['gateway_id'], $result['data']['transaction_id'], $moreinfo);

                    echo '<script nonce="' . ($csp_nonce ?? '') . '">location.reload();</script>';
                }else{
                    echo '<div class="alert alert-danger" role="alert">Transaction not valid or not found.</div><style>.loading-123412341234{display: none;}</style>';
                }
            } else {
                echo '<div class="alert alert-danger" role="alert">'.$result['title'].'</div><style>.loading-123412341234{display: none;}</style>';
            }
        }
    }

