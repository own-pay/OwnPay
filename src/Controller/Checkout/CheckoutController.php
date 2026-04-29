<?php
declare(strict_types=1);

namespace OwnPay\Controller\Checkout;

use OwnPay\Http\RequestContext;
use OwnPay\Service\System\CrudService;
use OwnPay\Service\System\EnvironmentService;

/**
 * Checkout Controller
 *
 * Handles public-facing checkout actions (action-v2):
 * - invoice: Create transaction from invoice
 * - payment-link: Create transaction from payment link
 * - payment-link-default: Create transaction from default payment link
 */
class CheckoutController
{
    public static function handle(string $action, ?RequestContext $ctx = null): void
    {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');
        $controller = new self();

        switch ($action) {
            case 'invoice':
                $controller->processInvoice($ctx);
                break;
            case 'payment-link':
                $controller->processPaymentLink($ctx);
                break;
            case 'payment-link-default':
                $controller->processPaymentLinkDefault($ctx);
                break;
        }
    }

    private function processInvoice(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $site_url = $ctx->siteUrl;
        $path_payment = $ctx->pathPayment;
        $path_invoice = $ctx->pathInvoice;

        $request = \OwnPay\Http\Request::createFromGlobals();

        $itemid = $request->post('itemid', '');

        if ($itemid == "") {
            echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.']);
        } else {
            $params = [':invoiceID' => $itemid, ':status' => 'unpaid'];

            $response = CrudService::select($db_prefix . 'invoice', 'WHERE ref = :invoiceID AND status = :status', '* FROM', $params);
            if ($response['status'] == true) {
                $invoiceRow = $response['response'][0];

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
                        $vatRate = money_sanitize($row['vat']);

                        $grossAmount = money_mul($amount, $quantity);

                        $netAmount = money_sub($grossAmount, $discount);

                        $vatAmount = money_div(money_mul($netAmount, $vatRate), "100");

                        $lineTotal = money_add($netAmount, $vatAmount);

                        $invoiceItems[] = [
                            'description' => $row['description'],
                            'unitPrice' => money_round($amount, 2),
                            'quantity' => $quantity,
                            'discount' => money_round($discount, 2),
                            'vat' => money_round($vatAmount, 2),
                            'total' => money_round($lineTotal, 2),
                        ];

                        $subTotal = money_add($subTotal, $grossAmount);
                        $totalDiscount = money_add($totalDiscount, $discount);
                        $totalVat = money_add($totalVat, $vatAmount);
                    }
                }

                $customerInfo = json_decode($invoiceRow['customer_info'], true);

                $customer_name = $customerInfo['name'] ?? '';
                $customer_email = $customerInfo['email'] ?? '';
                $customer_mobile = $customerInfo['mobile'] ?? '';

                $source_info = '[{ "label": "Invoice Id", "value": "' . $itemid . '" }]';
                $metadata = '{"invoice_id": "' . $itemid . '"}';

                $amount = money_add(money_add(money_sub($subTotal, $totalDiscount), $totalVat), money_sanitize($invoiceRow['shipping']));

                $currency = $invoiceRow['currency'];

                $return_url = $site_url . $path_invoice . '/' . $itemid;
                $webhook_url = $site_url . $path_invoice . '/webhook';

                $payment_id = generateItemID(27, 27);

                $columns = ['brand_id', 'source', 'ref', 'customer_info', 'amount', 'currency', 'source_info', 'metadata', 'return_url', 'webhook_url', 'created_date', 'updated_date'];
                $values = [$invoiceRow['brand_id'], 'invoice', $payment_id, '{ "name": "' . $customer_name . '", "email": "' . $customer_email . '", "mobile": "' . $customer_mobile . '" }', money_sanitize($amount), $currency, $source_info, $metadata, $return_url, $webhook_url, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                CrudService::insert($db_prefix . 'transaction', $columns, $values);

                echo json_encode(['status' => "true", 'redirect' => $site_url . $path_payment . '/' . $payment_id]);
            } else {
                echo json_encode(['status' => "false", 'title' => 'Invalid Invoice ID', 'message' => 'Please fill in all required fields before proceeding.']);
            }
        }
    }

    private function processPaymentLink(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $site_url = $ctx->siteUrl;
        $path_payment = $ctx->pathPayment;

        $request = \OwnPay\Http\Request::createFromGlobals();

        $itemid = $request->post('itemid', '');

        if ($itemid == "") {
            echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.']);
        } else {
            $params = [':ref' => $itemid];

            $response_payment_link = CrudService::select($db_prefix . 'payment_link', 'WHERE ref = :ref', '* FROM', $params);
            if ($response_payment_link['status'] == true) {
                $paymentRow = $response_payment_link['response'][0];

                if ($paymentRow['quantity'] > 0) {
                    $columns = ['quantity'];
                    $values = [$paymentRow['quantity'] - 1];
                    $params_link = [':ref' => $paymentRow['ref']];
                    CrudService::update($db_prefix . 'payment_link', $columns, $values, 'ref = :ref', $params_link);
                } else {
                    echo json_encode(['status' => "false", 'title' => 'Product Not Available', 'message' => 'Cannot generate payment link because the product is out of stock.']);
                    exit();
                }

                if (empty($paymentRow['expired_date'])) {
                    $status = $paymentRow['status'];
                } else {
                    if (isExpired($paymentRow['expired_date'])) {
                        $status = 'expired';
                    } else {
                        $status = $paymentRow['status'];
                    }
                }

                if ($status !== "active") {
                    echo json_encode(['status' => "false", 'title' => 'Product Not Active', 'message' => 'This payment link cannot be generated because the product is currently inactive.']);
                    exit();
                }

                $form_data = [];

                $customFields = [];

                $params = [':paymentLinkID' => $paymentRow['ref']];

                $response_PaymentLinkItem = CrudService::select($db_prefix . 'payment_link_field', 'WHERE paymentLinkID = :paymentLinkID', '* FROM', $params);
                if ($response_PaymentLinkItem['status'] == true) {
                    foreach ($response_PaymentLinkItem['response'] as $row) {
                        $Inputoptions = [];
                        if ($row['formType'] === 'select' && ($row['value'] !== null && $row['value'] !== '') || $row['formType'] === 'file' && ($row['value'] !== null && $row['value'] !== '')) {
                            $Inputoptions = array_map('trim', explode(',', $row['value']));
                        }

                        $customFields[] = [
                            'type' => $row['formType'],
                            'name' => strtolower(preg_replace('/[^a-z0-9_]/i', '_', $row['fieldName'])),
                            'label' => $row['fieldName'],
                            'options' => $Inputoptions,
                            'required' => $row['required'],
                        ];
                    }
                }

                foreach ($customFields as $field) {
                    $name = $field['name'];
                    $label = $field['label'];
                    $type = $field['type'];

                    if ($type === 'file' && isset($_FILES[$name]) && $_FILES[$name]['error'] === 0) {
                        $max_file_size = 5 * 1024 * 1024;

                        $mediaUpload = json_decode(uploadImage($_FILES[$name] ?? null, $max_file_size), true);
                        if ($mediaUpload['status'] == true) {
                            $url = $site_url . 'media/storage/' . $mediaUpload['file'];

                            $form_data[] = [
                                'label' => $label,
                                'value' => $url
                            ];
                        }
                    } elseif ($type === 'checkbox') {

                        $val = $request->post($name);
                        $value = $val !== null
                            ? implode(', ', (array) $val)
                            : '';

                        $form_data[] = [
                            'label' => $label,
                            'value' => $value
                        ];
                    } elseif ($request->post($name) !== null) {

                        $val = $request->post($name);
                        $value = is_array($val)
                            ? implode(', ', $val)
                            : trim((string) $val);

                        $form_data[] = [
                            'label' => $label,
                            'value' => $value
                        ];
                    }
                }

                $customer_name = trim($request->post('full-name', ''));
                $customer_email = trim($request->post('email-address', ''));
                $customer_mobile = trim($request->post('mobile-number', ''));

                $source_info = json_encode($form_data);
                $metadata = '{"paymentLink_id": "' . $itemid . '"}';

                $currency = $paymentRow['currency'];

                $payment_id = generateItemID(27, 27);

                $columns = ['brand_id', 'source', 'ref', 'customer_info', 'amount', 'currency', 'source_info', 'metadata', 'created_date', 'updated_date'];
                $values = [$paymentRow['brand_id'], 'payment-link', $payment_id, '{ "name": "' . $customer_name . '", "email": "' . $customer_email . '", "mobile": "' . $customer_mobile . '" }', money_sanitize($paymentRow['amount']), $currency, $source_info, $metadata, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                CrudService::insert($db_prefix . 'transaction', $columns, $values);

                echo json_encode(['status' => "true", 'redirect' => $site_url . $path_payment . '/' . $payment_id]);
            } else {
                echo json_encode(['status' => "false", 'title' => 'Invalid Payment Link ID', 'message' => 'Please fill in all required fields before proceeding.']);
            }
        }
    }

    private function processPaymentLinkDefault(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $site_url = $ctx->siteUrl;
        $path_payment = $ctx->pathPayment;

        $request = \OwnPay\Http\Request::createFromGlobals();

        $itemid = $request->post('itemid', '');

        if ($itemid == "") {
            echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.']);
        } else {
            $params = [':brand_id' => $itemid];

            $response_brand = CrudService::select($db_prefix . 'brands', 'WHERE brand_id = :brand_id', '* FROM', $params);
            if ($response_brand['status'] == true) {
                $brandRow = $response_brand['response'][0];

                $customer_name = trim($request->post('full-name', ''));
                $customer_email = trim($request->post('email-address', ''));
                $customer_mobile = trim($request->post('mobile-number', ''));

                $metadata = '{"paymentLink_id": "' . $itemid . '"}';

                $amount = trim($request->post('amount', ''));
                $currency = (($v = EnvironmentService::get('payment-link-default-currency', $response_brand['response'][0]['brand_id'])) && $v !== null && $v !== '') ? $v : $brandRow['currency_code'];

                $payment_id = generateItemID(27, 27);

                $columns = ['brand_id', 'source', 'ref', 'customer_info', 'amount', 'currency', 'created_date', 'updated_date'];
                $values = [$brandRow['brand_id'], 'payment-link-default', $payment_id, '{ "name": "' . $customer_name . '", "email": "' . $customer_email . '", "mobile": "' . $customer_mobile . '" }', money_sanitize($amount), $currency, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                CrudService::insert($db_prefix . 'transaction', $columns, $values);

                echo json_encode(['status' => "true", 'redirect' => $site_url . $path_payment . '/' . $payment_id]);
            } else {
                echo json_encode(['status' => "false", 'title' => 'Invalid Payment Link ID', 'message' => 'Please fill in all required fields before proceeding.']);
            }
        }
    }
}
