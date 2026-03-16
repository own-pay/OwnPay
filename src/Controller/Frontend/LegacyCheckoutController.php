<?php

namespace AnirbanPay\Controller\Frontend;

use AnirbanPay\Http\RequestContext;

class LegacyCheckoutController
{
    public static function handle(array $context, ?RequestContext $ctx = null) {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');
        extract($context);

                    $paymentID = $param1;
                    $paymentID124123412 = $param1;

                    $params = [':ref' => $paymentID];

                    $response_transaction = json_decode(getData($db_prefix . 'transaction', 'WHERE ref = :ref', '* FROM', $params), true);
                    if ($response_transaction['status'] == true) {
                        $params = [':brand_id' => $response_transaction['response'][0]['brand_id']];

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

                                $transactionRow = $response_transaction['response'][0];

                                $customer = json_decode($transactionRow['customer_info'], true) ?? [];

                                $response_gateway = json_decode(getData($db_prefix . 'gateways', ' WHERE brand_id ="' . $response_brand['response'][0]['brand_id'] . '" AND gateway_id = "' . $response_transaction['response'][0]['gateway_id'] . '"'), true);

                                $gateway = $response_gateway['response'][0]['display'] ?? '';

                                if ($transactionRow['status'] == "initiated") {
                                    $finalUrl = '--';
                                } else {
                                    if ($transactionRow['return_url'] == "" || $transactionRow['return_url'] == "--") {
                                        $finalUrl = '--';
                                    } else {
                                        $finalUrl = addQueryParams($transactionRow['return_url'], ['ap_status' => $transactionRow['status'], 'transaction_ref' => $transactionRow['ref']]);
                                    }
                                }

                                $response_faq = json_decode(getData($db_prefix . 'faq', ' WHERE brand_id ="' . $response_brand['response'][0]['brand_id'] . '" AND status ="active" ORDER BY 1 DESC'), true);

                                /* Clean Transaction Info */
                                $transactionInfo = [
                                    'ref' => $transactionRow['ref'],
                                    'customer' => [
                                        'id' => $customer['id'] ?? null,
                                        'name' => $customer['name'] ?? null,
                                        'email' => $customer['email'] ?? null,
                                        'mobile' => $customer['mobile'] ?? null,
                                    ],
                                    'payment_method' => $gateway,
                                    'currency' => $transactionRow['currency'],
                                    'amount' => money_round($transactionRow['amount']),
                                    'discount_amount' => money_round($transactionRow['discount_amount']),
                                    'processing_fee' => money_round($transactionRow['processing_fee']),
                                    'local_net_amount' => money_round($transactionRow['local_net_amount']),
                                    'local_currency' => $transactionRow['local_currency'],
                                    'return_url' => $finalUrl,
                                    'created_date' => $transactionRow['created_date'],
                                    'updated_date' => $transactionRow['updated_date'],
                                    'status' => $transactionRow['status'],
                                    'brandId' => $transactionRow['brand_id'],
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

                                $faqs = [];

                                foreach ($response_faq['response'] as $faq) {
                                    $faqs[] = [
                                        'title' => $faq['title'],
                                        'description' => $faq['description']
                                    ];
                                }

                                $pageData = [
                                    'transaction' => $transactionInfo,
                                    'brand' => $brandInfo,
                                    'faqs' => $faqs,
                                    'options' => $options,
                                    'lang' => $lang,
                                ];

                                // Pass to theme to render checkout page
                                $theme->renderCheckout($pageData);
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
