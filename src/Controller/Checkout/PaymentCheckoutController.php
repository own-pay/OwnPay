<?php
declare(strict_types=1);

namespace OwnPay\Controller\Checkout;

use OwnPay\Http\RequestContext;
use OwnPay\Service\System\CrudService;
use OwnPay\Service\System\EnvironmentService;

class PaymentCheckoutController
{
    public static function handle(array $context, ?RequestContext $ctx = null) {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');
        extract($context);

                    $paymentID = $param1;
                    $paymentID124123412 = $param1;

                    $params = [':ref' => $paymentID];

                    $response_transaction = CrudService::select($db_prefix . 'transaction', 'WHERE ref = :ref', '* FROM', $params);
                    if ($response_transaction['status'] == true) {
                        $params = [':brand_id' => $response_transaction['response'][0]['brand_id']];

                        $response_brand = CrudService::select($db_prefix . 'brands', 'WHERE brand_id = :brand_id', '* FROM', $params);
                        if ($response_brand['status'] == true) {
                            $themeSlug = $response_brand['response'][0]['theme'];
                            $themePath = safeModulePath($themeSlug, __DIR__ . '/app/modules/themes');
                            if ($themePath !== false) {
                                require_once $themePath;

                                $class = str_replace(' ', '', ucwords(str_replace('-', ' ', $themeSlug))) . 'Theme';

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
                                    $value = EnvironmentService::get($optionName, $response_brand['response'][0]['brand_id']);

                                    // Handle multi-select stored as JSON
                                    if (!empty($field['multiple']) && !empty($value)) {
                                        $value = is_array($value) ? $value : json_decode($value, true);
                                    }

                                    $options[$field['name']] = $value;
                                }

                                $transactionRow = $response_transaction['response'][0];

                                $customer = json_decode($transactionRow['customer_info'], true) ?? [];

                                $response_gateway = CrudService::select($db_prefix . 'gateways', 'WHERE brand_id = :brand_id AND gateway_id = :gateway_id', '* FROM', [':brand_id' => $response_brand['response'][0]['brand_id'], ':gateway_id' => $response_transaction['response'][0]['gateway_id']]);

                                $gateway = $response_gateway['response'][0]['display'] ?? '';

                                if ($transactionRow['status'] == "initiated") {
                                    $finalUrl = '--';
                                } else {
                                    if (empty($transactionRow['return_url'])) {
                                        $finalUrl = '--';
                                    } else {
                                        $finalUrl = addQueryParams($transactionRow['return_url'], ['op_status' => $transactionRow['status'], 'transaction_ref' => $transactionRow['ref']]);
                                    }
                                }

                                $response_faq = CrudService::select($db_prefix . 'faq', 'WHERE brand_id = :brand_id AND status = :status ORDER BY 1 DESC', '* FROM', [':brand_id' => $response_brand['response'][0]['brand_id'], ':status' => 'active']);

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
