<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api;

use OwnPay\Http\RequestContext;
use OwnPay\Service\System\CrudService;

class IpnController
{
    public static function handle(array $context, ?RequestContext $ctx = null) {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');
        extract($context);

                    $gateway_id = $segments[1] ?? null;

                    $params = [':gateway_id' => $gateway_id];

                    $response_gateway = CrudService::select($db_prefix . 'gateways', 'WHERE gateway_id = :gateway_id', '* FROM', $params);
                    if ($response_gateway['status'] == false) {
                        http_response_code(400);

                        echo json_encode([
                            'error' => [
                                'code' => 'INVALID_GATEWAY',
                                'message' => 'The Gateway provided is incorrect or invalid.'
                            ]
                        ]);
                        exit;
                    } else {
                        $params = [':brand_id' => $response_gateway['response'][0]['brand_id']];

                        $response_brand = CrudService::select($db_prefix . 'brands', 'WHERE brand_id = :brand_id', '* FROM', $params);
                        if ($response_brand['status'] == true) {
                            $options = [];

                            $params = [':gateway_id' => $gateway_id];
                            $response_gateways_parameter = CrudService::select($db_prefix . 'gateways_parameter', 'WHERE gateway_id = :gateway_id', '* FROM', $params);
                            foreach ($response_gateways_parameter['response'] as $field) {
                                $value = $field['value'];

                                if (!empty($field['multiple']) && !empty($value)) {
                                    $value = is_array($value) ? $value : json_decode($value, true);
                                }

                                $options[$field['option_name']] = $value;
                            }

                            if (file_exists(__DIR__ . '/app/modules/gateways/' . $response_gateway['response'][0]['slug'] . '/class.php')) {
                                require_once __DIR__ . '/app/modules/gateways/' . $response_gateway['response'][0]['slug'] . '/class.php';

                                $class = str_replace(' ', '', ucwords(str_replace('-', ' ', $response_gateway['response'][0]['slug']))) . 'Gateway';

                                $gateway = new $class();

                                $gateway_info = $gateway->info();

                                if (method_exists($gateway, 'supported_languages')) {
                                    $supported_languages = $gateway->supported_languages();
                                } else {
                                    $supported_languages = [];
                                }

                                if (method_exists($gateway, 'lang_text')) {
                                    $lang_text = $gateway->lang_text();
                                } else {
                                    $lang_text = [];
                                }

                                $language = resolveModuleLanguage($response_brand['response'][0]['language'], $supported_languages);

                                // Build $lang array for developer
                                $lang = buildLangArray($lang_text, $language);

                                $brandRow = $response_brand['response'][0];

                                $brandInfo = [
                                    'id' => $brandRow['brand_id'],
                                    'name' => empty($brandRow['name']) ? $brandRow['identify_name'] : $brandRow['name'],
                                    'identifyName' => $brandRow['identify_name'],
                                    'logo' => ($brandRow['logo'] !== null && $brandRow['logo'] !== '') ? $brandRow['logo'] : 'https://help.OwnPay.com/storage/branding_media/8a5c6ee4-8eba-401d-bffb-c43006d5f65d.png',
                                    'favicon' => ($brandRow['favicon'] !== null && $brandRow['favicon'] !== '') ? $brandRow['favicon'] : 'https://help.OwnPay.com/favicon/icon-144x144.png',

                                    'support' => [
                                        'email' => $brandRow['support_email_address'],
                                        'phone' => $brandRow['support_phone_number'],
                                        'website' => $brandRow['support_website'],
                                        'whatsapp' => $brandRow['whatsapp_number'],
                                        'telegram' => 'https://t.me/' . $brandRow['telegram'],
                                        'messenger' => 'https://m.me/' . $brandRow['facebook_messenger'],
                                        'fb_page' => 'https://facebook.com/' . $brandRow['facebook_page'],
                                    ],

                                    'address' => [
                                        'street' => $brandRow['street_address'],
                                        'city' => $brandRow['city_town'],
                                        'postal' => $brandRow['postal_code'],
                                        'country' => $brandRow['country'],
                                    ],

                                    'locale' => [
                                        'timezone' => $brandRow['timezone'],
                                        'language' => $language,
                                        'currency' => $brandRow['currency_code'],
                                    ],
                                ];

                                $response = [
                                    'gateway' => [
                                        'gateway_id' => $response_gateway['response'][0]['gateway_id'],
                                        'slug' => $response_gateway['response'][0]['slug'],
                                        'name' => $response_gateway['response'][0]['name'],
                                        'display' => $response_gateway['response'][0]['display'],
                                        'logo' => $response_gateway['response'][0]['logo'],
                                        'currency' => $response_gateway['response'][0]['currency'],

                                        'min_allow' => money_round($response_gateway['response'][0]['min_allow']),
                                        'max_allow' => money_round($response_gateway['response'][0]['max_allow']),

                                        'fixed_discount' => money_round($response_gateway['response'][0]['fixed_discount']),
                                        'percentage_discount' => money_round($response_gateway['response'][0]['percentage_discount']),
                                        'fixed_charge' => money_round($response_gateway['response'][0]['fixed_charge']),
                                        'percentage_charge' => money_round($response_gateway['response'][0]['percentage_charge']),

                                        'primary_color' => $response_gateway['response'][0]['primary_color'],
                                        'text_color' => $response_gateway['response'][0]['text_color'],
                                        'btn_color' => $response_gateway['response'][0]['btn_color'],
                                        'btn_text_color' => $response_gateway['response'][0]['btn_text_color'],

                                        'options' => $options
                                    ],

                                    'brand' => [
                                        'id' => $brandRow['brand_id'],
                                        'name' => $brandRow['name'],
                                        'identifyName' => $brandRow['identify_name'],
                                        'logo' => ($brandRow['logo'] !== null && $brandRow['logo'] !== '') ? $brandRow['logo'] : null,
                                        'favicon' => ($brandRow['favicon'] !== null && $brandRow['favicon'] !== '') ? $brandRow['favicon'] : null,

                                        'support' => [
                                            'email' => $brandRow['support_email_address'],
                                            'phone' => $brandRow['support_phone_number'],
                                            'website' => $brandRow['support_website'],
                                            'whatsapp' => $brandRow['whatsapp_number'],
                                            'telegram' => 'https://t.me/' . $brandRow['telegram'],
                                            'messenger' => 'https://m.me/' . $brandRow['facebook_messenger'],
                                            'fb_page' => 'https://facebook.com/' . $brandRow['facebook_page'],
                                        ],

                                        'address' => [
                                            'street' => $brandRow['street_address'],
                                            'city' => $brandRow['city_town'],
                                            'postal' => $brandRow['postal_code'],
                                            'country' => $brandRow['country'],
                                        ],

                                        'locale' => [
                                            'timezone' => $brandRow['timezone'],
                                            'language' => $language,
                                            'currency' => $brandRow['currency_code'],
                                        ],
                                    ],

                                    'lang' => $lang
                                ];

                                if (is_callable([$gateway, 'ipn'])) {
                                    $gateway->ipn($response);
                                }
                            } else {
                                http_response_code(400);

                                echo json_encode([
                                    'error' => [
                                        'code' => 'INVALID_GATEWAY',
                                        'message' => 'The Gateway provided is incorrect or invalid.'
                                    ]
                                ]);
                                exit;
                            }
                        } else {
                            http_response_code(400);

                            echo json_encode([
                                'error' => [
                                    'code' => 'INVALID_GATEWAY',
                                    'message' => 'The Gateway provided is incorrect or invalid.'
                                ]
                            ]);
                            exit;
                        }
                    }

    }
}
