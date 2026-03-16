<?php

namespace AnirbanPay\Controller\Frontend;

use AnirbanPay\Http\RequestContext;

class PaymentLinkCheckoutController
{
    public static function handle(array $context, ?RequestContext $ctx = null) {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');
        extract($context);

                    $paymentLinkID = $param1;

                    if ($paymentLinkID == "default") {
                        $brandID = $segments[2] ?? null;

                        $params = [':brand_id' => $brandID];

                        $response_brand = json_decode(getData($db_prefix . 'brands', 'WHERE brand_id = :brand_id', '* FROM', $params), true);
                        if ($response_brand['status'] == true) {
                            if (file_exists(__DIR__ . '/app/modules/themes/' . $response_brand['response'][0]['theme'] . '/class.php')) {
                                require_once __DIR__ . '/app/modules/themes/' . $response_brand['response'][0]['theme'] . '/class.php';

                                $class = str_replace(' ', '', ucwords(str_replace('-', ' ', $response_brand['response'][0]['theme']))) . 'Theme';

                                $theme = new $class();

                                $fields = $theme->fields();

                                $supported_languages = $theme->supported_languages();
                                $lang_text = $theme->lang_text();

                                $language = resolveModuleLanguage($response_brand['response'][0]['language'], $supported_languages);

                                $lang = buildLangArray($lang_text, $language);

                                $options = [];
                                foreach ($fields as $field) {
                                    $optionName = $response_brand['response'][0]['theme'] . '-' . $field['name'];
                                    $value = get_env($optionName, $response_brand['response'][0]['brand_id']);

                                    if (!empty($field['multiple']) && !empty($value)) {
                                        $value = is_array($value) ? $value : json_decode($value, true);
                                    }

                                    $options[$field['name']] = $value;
                                }

                                $paymentLinkInfo = [
                                    'pid' => $response_brand['response'][0]['brand_id'],
                                    'currency' => (($v = get_env('payment-link-default-currency', $response_brand['response'][0]['brand_id'])) && $v !== '--') ? $v : $brandRow['currency_code'],
                                    'brandId' => $response_brand['response'][0]['brand_id'],
                                ];

                                $brandRow = $response_brand['response'][0];

                                $brandInfo = [
                                    'id' => $brandRow['brand_id'],
                                    'name' => ($brandRow['name'] == "--") ? $brandRow['identify_name'] : $brandRow['name'],
                                    'identifyName' => $brandRow['identify_name'],
                                    'logo' => $brandRow['logo'] !== '--' ? $brandRow['logo'] : 'https://help.AnirbanPay.com/storage/branding_media/8a5c6ee4-8eba-401d-bffb-c43006d5f65d.png',
                                    'favicon' => $brandRow['favicon'] !== '--' ? $brandRow['favicon'] : 'https://help.AnirbanPay.com/favicon/icon-144x144.png',

                                    'support' => [
                                        'email' => $brandRow['support_email_address'],
                                        'phone' => $brandRow['support_phone_number'],
                                        'website' => $brandRow['support_website'],
                                        'whatsapp' => $brandRow['whatsapp_number'],
                                        'telegram' => $brandRow['telegram'],
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

                                $pageData = [
                                    'paymentLink' => $paymentLinkInfo,
                                    'brand' => $brandInfo,
                                    'options' => $options,
                                    'lang' => $lang,
                                ];

                                // Pass to theme to render checkout page
                                $theme->renderPaymentLinkDefault($pageData);
                            } else {
                                http_response_code(403);
                                exit('Invalid theme slug');
                            }
                        } else {
                            if (file_exists(__DIR__ . '/errors/404.php')) {
                                http_response_code(404);
                                require __DIR__ . '/errors/404.php';
                            } else {
                                http_response_code(403);
                                exit('Direct access not allowed');
                            }
                        }
                    } else {
                        $params = [':ref' => $paymentLinkID];

                        $response_payment_link = json_decode(getData($db_prefix . 'payment_link', 'WHERE ref = :ref', '* FROM', $params), true);
                        if ($response_payment_link['status'] == true) {
                            $params = [':brand_id' => $response_payment_link['response'][0]['brand_id']];

                            $response_brand = json_decode(getData($db_prefix . 'brands', 'WHERE brand_id = :brand_id', '* FROM', $params), true);
                            if ($response_brand['status'] == true) {
                                if (file_exists(__DIR__ . '/app/modules/themes/' . $response_brand['response'][0]['theme'] . '/class.php')) {
                                    require_once __DIR__ . '/app/modules/themes/' . $response_brand['response'][0]['theme'] . '/class.php';

                                    $class = str_replace(' ', '', ucwords(str_replace('-', ' ', $response_brand['response'][0]['theme']))) . 'Theme';

                                    $theme = new $class();

                                    $fields = $theme->fields();

                                    $supported_languages = $theme->supported_languages();
                                    $lang_text = $theme->lang_text();

                                    $language = resolveModuleLanguage($response_brand['response'][0]['language'], $supported_languages);

                                    // Build $lang array for developer
                                    $lang = buildLangArray($lang_text, $language);

                                    $options = [];
                                    foreach ($fields as $field) {
                                        $optionName = $response_brand['response'][0]['theme'] . '-' . $field['name'];
                                        $value = get_env($optionName, $response_brand['response'][0]['brand_id']);

                                        // Handle multi-select stored as JSON
                                        if (!empty($field['multiple']) && !empty($value)) {
                                            $value = is_array($value) ? $value : json_decode($value, true);
                                        }

                                        $options[$field['name']] = $value;
                                    }

                                    $paymentRow = $response_payment_link['response'][0];

                                    $product_info = json_decode($paymentRow['product_info'], true);

                                    if ($paymentRow['expired_date'] == "--") {
                                        $status = $paymentRow['status'];
                                    } else {
                                        if (isExpired($paymentRow['expired_date'])) {
                                            $status = 'expired';
                                        } else {
                                            $status = $paymentRow['status'];
                                        }
                                    }

                                    $paymentLinkInfo = [
                                        'pid' => $paymentRow['ref'],
                                        'status' => $status,
                                        'currency' => $paymentRow['currency'],
                                        'total' => money_round($paymentRow['amount']),
                                        'quantity' => money_sanitize($paymentRow['quantity']),
                                        'expired_date' => ($paymentRow['expired_date'] == "" || $paymentRow['expired_date'] == "--") ? '--' : convertUTCtoUserTZ($paymentRow['expired_date'], ($response_brand['response'][0]['timezone'] === '--' || $response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $response_brand['response'][0]['timezone'], "M d, Y"),
                                        'created_date' => convertUTCtoUserTZ($paymentRow['created_date'], ($response_brand['response'][0]['timezone'] === '--' || $response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $response_brand['response'][0]['timezone'], "M d, Y"),
                                        'updated_date' => convertUTCtoUserTZ($paymentRow['updated_date'], ($response_brand['response'][0]['timezone'] === '--' || $response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $response_brand['response'][0]['timezone'], "M d, Y"),

                                        'product' => [
                                            'title' => $product_info['title'] ?? 'Product',
                                            'description' => $product_info['description'] ?? null,
                                        ],

                                        'brandId' => $paymentRow['brand_id'],
                                    ];

                                    $customFields = [];

                                    $params = [':paymentLinkID' => $paymentRow['ref']];

                                    $response_PaymentLinkItem = json_decode(getData($db_prefix . 'payment_link_field', 'WHERE paymentLinkID = :paymentLinkID', '* FROM', $params), true);
                                    if ($response_PaymentLinkItem['status'] == true) {
                                        foreach ($response_PaymentLinkItem['response'] as $row) {
                                            $Inputoptions = [];
                                            if ($row['formType'] === 'select' && $row['value'] !== '--' || $row['formType'] === 'file' && $row['value'] !== '--' || $row['formType'] === 'checkbox' && $row['value'] !== '--' || $row['formType'] === 'radio' && $row['value'] !== '--') {
                                                $Inputoptions = array_map('trim', explode(',', $row['value']));
                                            }

                                            $customFields[] = [
                                                'type' => $row['formType'],              // text, textarea, select
                                                'name' => strtolower(preg_replace('/[^a-z0-9_]/i', '_', $row['fieldName'])),                              // customer_name
                                                'label' => $row['fieldName'],             // Customer Name
                                                'options' => $Inputoptions,                      // for select
                                                'required' => $row['required'],                          // future extend
                                            ];
                                        }
                                    }


                                    $paymentLinkInfo['fields'] = $customFields;


                                    $brandRow = $response_brand['response'][0];

                                    $brandInfo = [
                                        'id' => $brandRow['brand_id'],
                                        'name' => ($brandRow['name'] == "--") ? $brandRow['identify_name'] : $brandRow['name'],
                                        'identifyName' => $brandRow['identify_name'],
                                        'logo' => $brandRow['logo'] !== '--' ? $brandRow['logo'] : 'https://help.AnirbanPay.com/storage/branding_media/8a5c6ee4-8eba-401d-bffb-c43006d5f65d.png',
                                        'favicon' => $brandRow['favicon'] !== '--' ? $brandRow['favicon'] : 'https://help.AnirbanPay.com/favicon/icon-144x144.png',

                                        'support' => [
                                            'email' => $brandRow['support_email_address'],
                                            'phone' => $brandRow['support_phone_number'],
                                            'website' => $brandRow['support_website'],
                                            'whatsapp' => $brandRow['whatsapp_number'],
                                            'telegram' => $brandRow['telegram'],
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

                                    $pageData = [
                                        'paymentLink' => $paymentLinkInfo,
                                        'brand' => $brandInfo,
                                        'options' => $options,
                                        'lang' => $lang,
                                    ];

                                    // Pass to theme to render checkout page
                                    $theme->renderPaymentLink($pageData);
                                } else {
                                    http_response_code(403);
                                    exit('Invalid theme slug');
                                }
                            } else {
                                if (file_exists(__DIR__ . '/errors/404.php')) {
                                    http_response_code(404);
                                    require __DIR__ . '/errors/404.php';
                                } else {
                                    http_response_code(403);
                                    exit('Direct access not allowed');
                                }
                            }
                        } else {
                            if (file_exists(__DIR__ . '/errors/404.php')) {
                                http_response_code(404);
                                require __DIR__ . '/errors/404.php';
                            } else {
                                http_response_code(403);
                                exit('Direct access not allowed');
                            }
                        }
                    }
    }
}
