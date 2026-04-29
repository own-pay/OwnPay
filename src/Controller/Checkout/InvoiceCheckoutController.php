<?php
declare(strict_types=1);

namespace OwnPay\Controller\Checkout;

use OwnPay\Http\RequestContext;
use OwnPay\Service\System\CrudService;
use OwnPay\Service\System\EnvironmentService;

class InvoiceCheckoutController
{
    public static function handle(array $context, ?RequestContext $ctx = null) {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');
        extract($context);

                    $invoiceID = $param1;

                    if ($invoiceID == "webhook") {
                        // 1️⃣ Read raw JSON
                        $raw = file_get_contents('php://input');

                        if ($raw === '' || $raw === false) {
                            http_response_code(400);
                            exit('No payload received');
                        }

                        // 2️⃣ Decode JSON
                        $data = json_decode($raw, true);

                        if (json_last_error() !== JSON_ERROR_NONE) {
                            http_response_code(400);
                            exit('Invalid JSON');
                        }

                        // 3️⃣ Access data (EXACT match to sender)
                        $op_id = $data['op_id'] ?? null;

                        $params = [':ref' => $op_id];

                        $response_transaction = CrudService::select($db_prefix . 'transaction', 'WHERE ref = :ref', '* FROM', $params);
                        if ($response_transaction['status'] == true) {

                            $metadata_decode = json_decode($response_transaction['response'][0]['metadata'], true);

                            $invoiceIDD = $metadata_decode['invoice_id'] ?? '';

                            $params = [':ref' => $invoiceIDD];

                            $response_invoice = CrudService::select($db_prefix . 'invoice', 'WHERE ref = :ref', '* FROM', $params);
                            if ($response_invoice['status'] == true) {
                                if ($response_transaction['response'][0]['status'] == "completed") {
                                    $columns = ['gateway_id', 'status', 'updated_date'];
                                    $values = [$response_transaction['response'][0]['gateway_id'], 'paid', getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = 'id ="' . $response_invoice['response'][0]['id'] . '"';

                                    CrudService::update($db_prefix . 'invoice', $columns, $values, $condition);
                                }

                                if ($response_transaction['response'][0]['status'] == "refunded") {
                                    $columns = ['gateway_id', 'status', 'updated_date'];
                                    $values = [$response_transaction['response'][0]['gateway_id'], 'refunded', getCurrentDatetime('Y-m-d H:i:s')];
                                    $condition = 'id ="' . $response_invoice['response'][0]['id'] . '"';

                                    CrudService::update($db_prefix . 'invoice', $columns, $values, $condition);
                                }

                                $params = [':ref' => $invoiceIDD];

                                $response_invoice = CrudService::select($db_prefix . 'invoice', 'WHERE ref = :ref', '* FROM', $params);

                                $params = [':brand_id' => $response_invoice['response'][0]['brand_id']];
                                $response_brand = CrudService::select($db_prefix . 'brands', 'WHERE brand_id = :brand_id', '* FROM', $params);

                                $invoice_items_array = [];

                                $response_items = CrudService::select($db_prefix . 'invoice_items', 'WHERE brand_id = :brand_id AND ref = :ref', '* FROM', [':brand_id' => $response_invoice['response'][0]['brand_id'], ':ref' => $response_invoice['response'][0]['ref']]);
                                foreach ($response_items['response'] as $rowItem) {
                                    $invoice_items_array[] = [
                                        'description' => $rowItem['description'],
                                        'amount' => money_round($rowItem['amount']),
                                        'quantity' => money_round($rowItem['quantity']),
                                        'discount' => money_round($rowItem['discount']),
                                        'vat' => money_round($rowItem['vat'])
                                    ];
                                }

                                $all_invoices = [
                                    'customer_info' => $response_invoice['response'][0]['customer_info'],
                                    'invoice_info' => [
                                        'invoice_id' => $response_invoice['response'][0]['ref'],
                                        'brand_id' => $response_invoice['response'][0]['brand_id'],
                                        'currency' => $response_invoice['response'][0]['currency'],
                                        'due_date' => $response_invoice['response'][0]['expired_date'],
                                        'shipping' => money_round($response_invoice['response'][0]['shipping']),
                                        'status' => $response_invoice['response'][0]['status'],
                                        'note' => $response_invoice['response'][0]['note'],
                                        'private_note' => $response_invoice['response'][0]['private_note'],
                                        'created_date' => convertUTCtoUserTZ($response_invoice['response'][0]['created_date'], empty($response_brand['response'][0]['timezone']) ? 'Asia/Dhaka' : $response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                                        'updated_date' => convertUTCtoUserTZ(getCurrentDatetime('Y-m-d H:i:s'), empty($response_brand['response'][0]['timezone']) ? 'Asia/Dhaka' : $response_brand['response'][0]['timezone'], "M d, Y h:i A")
                                    ],
                                    'invoice_items' => $invoice_items_array
                                ];
                                if (!empty($all_invoices)) {
                                    do_action('invoices.updated.status', $all_invoices);
                                }
                            }
                        }

                        // 6️⃣ IMPORTANT: Return 200 OK
                        http_response_code(200);
                        echo 'OK';
                        exit();
                    }

                    $params = [':ref' => $invoiceID];

                    $response_invoice = CrudService::select($db_prefix . 'invoice', 'WHERE ref = :ref', '* FROM', $params);
                    if ($response_invoice['status'] == true) {
                        $params = [':brand_id' => $response_invoice['response'][0]['brand_id']];

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

                                $invoiceRow = $response_invoice['response'][0];

                                $customer = json_decode($invoiceRow['customer_info'], true) ?? [];

                                $params = [':gateway_id' => $response_invoice['response'][0]['gateway_id']];

                                $response_gateway = CrudService::select($db_prefix . 'gateways', 'WHERE gateway_id = :gateway_id', '* FROM', $params);

                                /* Clean Invoice Info */
                                $invoiceInfo = [
                                    'iid' => $invoiceRow['ref'],
                                    'gateway' => $response_gateway['response'][0]['display'] ?? '',
                                    'status' => $invoiceRow['status'],
                                    'currency' => $invoiceRow['currency'],
                                    'due_date' => !empty($invoiceRow['due_date']) ? convertUTCtoUserTZ($invoiceRow['due_date'], empty($response_brand['response'][0]['timezone']) ? 'Asia/Dhaka' : $response_brand['response'][0]['timezone'], "M d, Y") : null,
                                    'shippingFee' => money_round($invoiceRow['shipping']),
                                    'note' => !empty($invoiceRow['note']) ? $invoiceRow['note'] : null,
                                    'privateNote' => !empty($invoiceRow['private_note']) ? $invoiceRow['private_note'] : null,
                                    'created_date' => convertUTCtoUserTZ($invoiceRow['created_date'], empty($response_brand['response'][0]['timezone']) ? 'Asia/Dhaka' : $response_brand['response'][0]['timezone'], "M d, Y"),
                                    'updated_date' => convertUTCtoUserTZ($invoiceRow['updated_date'], empty($response_brand['response'][0]['timezone']) ? 'Asia/Dhaka' : $response_brand['response'][0]['timezone'], "M d, Y"),

                                    'customer' => [
                                        'id' => $customer['id'] ?? null,
                                        'name' => $customer['name'] ?? null,
                                        'email' => $customer['email'] ?? null,
                                        'mobile' => $customer['mobile'] ?? null,
                                    ],

                                    'brandId' => $invoiceRow['brand_id'],
                                ];

                                $invoiceItems = [];
                                $subTotal = "0";
                                $totalDiscount = "0";
                                $totalVat = "0";

                                $params = [':invoice_id' => $invoiceRow['ref'], ':brand_id' => $invoiceRow['brand_id']];

                                $response_invoiceItem = CrudService::select($db_prefix . 'invoice_items', 'WHERE invoice_id = :invoice_id AND brand_id = :brand_id', '* FROM', $params);

                                if ($response_invoiceItem['status'] == true) {
                                    foreach ($response_invoiceItem['response'] as $row) {

                                        $amount = money_sanitize($row['amount']);
                                        $quantity = money_sanitize($row['quantity']);
                                        $discount = money_sanitize($row['discount']);
                                        $vat = money_sanitize($row['vat']);

                                        $lineTotal = money_add(money_sub(money_mul($amount, $quantity), $discount), $vat);

                                        $invoiceItems[] = [
                                            'description' => $row['description'],
                                            'unitPrice' => money_round($amount, 2),
                                            'quantity' => $quantity,
                                            'discount' => money_round($discount, 2),
                                            'vat' => money_round($vat, 2),
                                            'total' => money_round($lineTotal, 2),
                                        ];

                                        $subTotal = money_add($subTotal, money_mul($amount, $quantity));
                                        $totalDiscount = money_add($totalDiscount, $discount);
                                        $totalVat = money_add($totalVat, $vat);
                                    }
                                }

                                $shippingFee = money_sanitize($invoiceInfo['shippingFee']);

                                $grandTotal = money_add(money_add(money_sub($subTotal, $totalDiscount), $totalVat), $shippingFee);

                                $invoiceTotals = [
                                    'subTotal' => money_round($subTotal, 2),
                                    'discount' => money_round($totalDiscount, 2),
                                    'vat' => money_round($totalVat, 2),
                                    'shipping' => money_round($shippingFee, 2),
                                    'grandTotal' => money_round($grandTotal, 2),
                                ];

                                $brandRow = $response_brand['response'][0];

                                $brandInfo = [
                                    'id' => $brandRow['brand_id'],
                                    'name' => empty($brandRow['name']) ? $brandRow['identify_name'] : $brandRow['name'],
                                    'identifyName' => $brandRow['identify_name'],
                                    'logo' => !empty($brandRow['logo']) ? $brandRow['logo'] : 'https://help.OwnPay.com/storage/branding_media/8a5c6ee4-8eba-401d-bffb-c43006d5f65d.png',
                                    'favicon' => !empty($brandRow['favicon']) ? $brandRow['favicon'] : 'https://help.OwnPay.com/favicon/icon-144x144.png',

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
                                    'invoice' => $invoiceInfo,
                                    'items' => $invoiceItems,
                                    'totals' => $invoiceTotals,
                                    'brand' => $brandInfo,
                                    'options' => $options,
                                    'lang' => $lang,
                                ];

                                // Pass to theme to render checkout page
                                $theme->renderInvoice($pageData);
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
