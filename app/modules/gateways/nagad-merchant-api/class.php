<?php

declare(strict_types=1);

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;

use Xenon\NagadApi\Base;
use Xenon\NagadApi\Exception\NagadPaymentException;
use Xenon\NagadApi\Helper;

class NagadMerchantApiGateway implements GatewayAdapterInterface
{
    use GatewayDefaults;
    public function info(): array
    {
        return [
            'title' => 'Nagad Merchant Api',
            'logo' => 'assets/logo.jpg',
            'currency' => 'BDT',
            'tab' => 'mfs',

            'gateway_type' => 'api',
        ];
    }

    public function color(): array
    {
        return [
            'primary_color' => '#ed1c24',
            'text_color' => '#FFFFFF',
            'btn_color' => '#ed1c24',
            'btn_text_color' => '#FFFFFF',
        ];
    }

    public function fields(): array
    {
        return [
            [
                'name' => 'app_account',
                'label' => 'App Account',
                'type' => 'text',
            ],
            [
                'name' => 'merchant_id',
                'label' => 'Merchant ID',
                'type' => 'text',
            ],
            [
                'name' => 'private_key',
                'label' => 'Private Key',
                'type' => 'text',
            ],
            [
                'name' => 'public_key',
                'label' => 'Public Key',
                'type' => 'text',
            ],
            [
                'name' => 'mode',
                'label' => 'Mode',
                'type' => 'select',
                'options' => [
                    'live' => 'Live',
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
        if (!class_exists('\\Xenon\\NagadApi\\Base')) {
            echo '<div class="alert alert-danger" role="alert">Nagad SDK not found</div><style>.loading-123412341234{display: none;}</style>';
            exit();
        }

        echo '<center><div class="spinner-border text-primary m-3 loading-123412341234" role="status"><span class="visually-hidden">Loading...</span></div></center>';

        $config = [
            'NAGAD_APP_ENV' => ($data['options']['mode'] ?? 'sandbox') === 'sandbox' ? 'development' : 'production',
            'NAGAD_APP_LOG' => '1',
            'NAGAD_APP_ACCOUNT' => $data['options']['app_account'] ?? '', //demo
            'NAGAD_APP_MERCHANTID' => $data['options']['merchant_id'] ?? '', //demo
            'NAGAD_APP_MERCHANT_PRIVATE_KEY' => $data['options']['private_key'] ?? '',
            'NAGAD_APP_MERCHANT_PG_PUBLIC_KEY' => $data['options']['public_key'] ?? '',
            'NAGAD_APP_TIMEZONE' => 'Asia/Dhaka',
        ];


        $url = op_site_url();

        // First, get the query string after '?'
        $parts = explode('?', $url, 2);
        $queryString = isset($parts[1]) ? $parts[1] : '';

        // Sometimes there is a '/?' in the middle, replace it with '&' to normalize
        $queryString = str_replace('/?', '&', $queryString);

        // Parse the query parameters
        parse_str($queryString, $params);

        // Now you can access the values
        $merchant = $params['merchant'] ?? '';
        $order_id = $params['order_id'] ?? '';
        $status = $params['status'] ?? '';

        if (!empty($merchant) && !empty($order_id) && !empty($status)) {
            $responseArray = Helper::successResponse(op_site_url());

            if ($responseArray['status'] == 'Aborted') {
                echo '<div class="alert alert-danger" role="alert">Transaction Canceled</div><style>.loading-123412341234{display: none;}</style>';
            } else {
                if (isset($responseArray['payment_ref_id'], $responseArray['status']) && $responseArray['status'] == "Success") {
                    $helper = new Helper($config);
                    try {
                        $response = $helper->verifyPayment($responseArray['payment_ref_id']);

                        $buffer = json_decode($response, true);

                        if (isset($buffer['status']) && $buffer['status'] == 'Success') {
                            $transaction_id = (get_env('nagad-merchant-api-op_' . $buffer['orderId']) == "") ? 0 : get_env('nagad-merchant-api-op_' . $buffer['orderId']);

                            if ($transaction_id == $data['transaction']['ref']) {
                                $moreinfo = [
                                    [
                                        'label' => 'Client Mobile Number',
                                        'value' => $buffer['clientMobileNo']
                                    ],
                                    [
                                        'label' => 'Service Type',
                                        'value' => $buffer['serviceType']
                                    ]
                                ];

                                op_set_transaction_status($data['transaction']['ref'], 'completed', $data['gateway']['gateway_id'], $buffer['issuerPaymentRefNo'], $moreinfo);

                                echo '<script nonce="' . ($csp_nonce ?? '') . '">location.reload();</script>';
                            } else {
                                echo '<div class="alert alert-danger" role="alert">Invalid Transaction</div><style>.loading-123412341234{display: none;}</style>';
                            }
                        } else {
                            echo '<div class="alert alert-danger" role="alert">Transaction ' . $buffer['status'] . '</div><style>.loading-123412341234{display: none;}</style>';
                        }
                    } catch (Exception $e) {
                        echo '<div class="alert alert-danger" role="alert">' . $e->getMessage() . '</div><style>.loading-123412341234{display: none;}</style>';
                    }
                } else {
                    echo '<div class="alert alert-danger" role="alert">Transaction ' . $responseArray['status'] . '</div><style>.loading-123412341234{display: none;}</style>';
                }
            }
        } else {
            try {
                $sess = rand();

                set_env('nagad-merchant-api-op_' . $sess, $data['transaction']['ref']);

                $nagad = new Base($config, [
                    'amount' => (string) round($data['transaction']['local_net_amount']),
                    'invoice' => $sess,
                    'merchantCallback' => op_callback_url(),
                ]);

                $status = $nagad->payNow($nagad);
            } catch (\Xenon\NagadApi\Exception\ExceptionHandler $e) {
                echo '<div class="alert alert-danger" role="alert">' . $e->getMessage() . '</div><style>.loading-123412341234{display: none;}</style>';
                exit();
            }
        }
    }
}


