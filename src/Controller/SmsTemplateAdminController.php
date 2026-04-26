<?php
declare(strict_types=1);

namespace OwnPay\Controller;

use OwnPay\Http\RequestContext;
use OwnPay\Service\CrudService;
use OwnPay\Service\InputSanitizer;
use OwnPay\Service\PermissionGuard;
use OwnPay\Repository\SmsTemplateRepository;
use OwnPay\Repository\SmsDataRepository;
use OwnPay\Service\SmsRegexParser;
use OwnPay\Service\SmsHeuristicParser;

/**
 * SmsTemplateAdminController — Legacy-style action handler for SMS template
 * management and unparsed SMS queue review.
 *
 * Dispatched via adapter.php through opFetch() POST requests.
 * Follows the same pattern as DeviceController and SmsDataController.
 */
class SmsTemplateAdminController
{
    public static function handle(string $action, RequestContext $ctx): void
    {
        $global_response_brand = $ctx->brandResponse;
        $new_csrf_token = $ctx->csrfToken;
        $db_prefix = $ctx->dbPrefix;

        $request = \OwnPay\Http\Request::createFromGlobals();

        // ─── SMS Template CRUD ──────────────────────────────────────────

        if ($action == "sms-template-list") {
            if ($ctx->isLoggedIn) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'sms_data')) { return; }

                $search_input = $request->post('search_input', '');

                $pag = \OwnPay\Service\PaginationService::resolve($request->post('page', '1'), $request->post('show_limit'));
                $page = $pag['page'];
                $show_limit_val = $pag['perPage'];
                $offset = $pag['offset'];

                try {
                    $pdo = \OwnPay\Core\Database::getInstance()->getPdo();

                    $where = '1=1';
                    $params = [];

                    if ($search_input !== '') {
                        $where .= " AND (provider_name LIKE :search OR sender_pattern LIKE :search OR description LIKE :search)";
                        $params[':search'] = "%{$search_input}%";
                    }

                    // Count total
                    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM op_sms_templates WHERE {$where}");
                    $countStmt->execute($params);
                    $total_records = (int) $countStmt->fetchColumn();

                    // Fetch page
                    $sql = "SELECT * FROM op_sms_templates WHERE {$where} ORDER BY priority ASC, id DESC";
                    if (!$pag['isAll']) {
                        $sql .= " LIMIT {$offset}, {$show_limit_val}";
                    }
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                    $tz = $global_response_brand['response'][0]['timezone'] ?? 'Asia/Dhaka';

                    $response = [];
                    foreach ($rows as $row) {
                        $response[] = [
                            'id'               => $row['id'],
                            'provider_name'    => $row['provider_name'],
                            'sender_pattern'   => $row['sender_pattern'],
                            'regex_pattern'    => $row['regex_pattern'],
                            'transaction_type' => $row['transaction_type'],
                            'priority'         => (int) $row['priority'],
                            'is_active'        => (int) $row['is_active'],
                            'description'      => $row['description'] ?? '',
                            'created_at'       => isset($row['created_at']) ? convertUTCtoUserTZ($row['created_at'], $tz, "M d, Y h:i A") : '',
                        ];
                    }

                    $pagHtml = \OwnPay\Service\PaginationService::render($page, $total_records, $show_limit_val, $offset);

                    echo json_encode([
                        'status'         => 'true',
                        'response'       => $response,
                        'datatableInfo'  => $pagHtml['datatableInfo'],
                        'pagination'     => $pagHtml['pagination'],
                        'csrf_token'     => $new_csrf_token,
                    ]);
                } catch (\Throwable $e) {
                    echo json_encode(['status' => 'false', 'title' => 'Error', 'message' => 'Failed to load templates.', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "sms-template-info-byID") {
            if ($ctx->isLoggedIn) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'sms_data')) { return; }

                $itemId = (int) $request->post('ItemID', '0');
                $repo = new SmsTemplateRepository();
                $template = $repo->findById($itemId);

                if ($template) {
                    echo json_encode([
                        'status'           => 'true',
                        'id'               => $template['id'],
                        'provider_name'    => $template['provider_name'],
                        'sender_pattern'   => $template['sender_pattern'],
                        'regex_pattern'    => $template['regex_pattern'],
                        'transaction_type' => $template['transaction_type'],
                        'priority'         => $template['priority'],
                        'is_active'        => $template['is_active'],
                        'description'      => $template['description'] ?? '',
                        'csrf_token'       => $new_csrf_token,
                    ]);
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Not Found', 'message' => 'Template not found.', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "sms-template-create") {
            if ($ctx->isLoggedIn) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'sms_data')) { return; }
                if (PermissionGuard::denyUnlessHas($ctx, 'sms_data', 'create')) { return; }

                $provider_name    = InputSanitizer::trim($request->post('provider_name', ''));
                $sender_pattern   = InputSanitizer::trim($request->post('sender_pattern', ''));
                $regex_pattern    = $request->post('regex_pattern', ''); // Don't trim regex
                $transaction_type = InputSanitizer::trim($request->post('transaction_type', 'credit'));
                $priority         = (int) $request->post('priority', '100');
                $is_active        = (int) $request->post('is_active', '1');
                $description      = InputSanitizer::trim($request->post('description', ''));

                if ($provider_name === '' || $sender_pattern === '' || $regex_pattern === '') {
                    echo json_encode(['status' => 'false', 'title' => 'Incomplete Information', 'message' => 'Provider Name, Sender Pattern, and Regex Pattern are required.', 'csrf_token' => $new_csrf_token]);
                    return;
                }

                // Validate regex
                set_error_handler(fn() => true);
                $regexValid = @preg_match($regex_pattern, '');
                restore_error_handler();
                if ($regexValid === false) {
                    echo json_encode(['status' => 'false', 'title' => 'Invalid Regex', 'message' => 'The regex pattern is not valid: ' . preg_last_error_msg(), 'csrf_token' => $new_csrf_token]);
                    return;
                }

                $repo = new SmsTemplateRepository();
                $repo->create([
                    'provider_name'    => $provider_name,
                    'sender_pattern'   => $sender_pattern,
                    'regex_pattern'    => $regex_pattern,
                    'transaction_type' => $transaction_type,
                    'priority'         => $priority,
                    'is_active'        => $is_active,
                    'description'      => $description,
                ]);

                echo json_encode(['status' => 'true', 'title' => 'Template Created', 'message' => 'The SMS template has been created successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "sms-template-edit") {
            if ($ctx->isLoggedIn) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'sms_data')) { return; }
                if (PermissionGuard::denyUnlessHas($ctx, 'sms_data', 'edit')) { return; }

                $itemId           = (int) $request->post('itemid', '0');
                $provider_name    = InputSanitizer::trim($request->post('provider_name', ''));
                $sender_pattern   = InputSanitizer::trim($request->post('sender_pattern', ''));
                $regex_pattern    = $request->post('regex_pattern', '');
                $transaction_type = InputSanitizer::trim($request->post('transaction_type', 'credit'));
                $priority         = (int) $request->post('priority', '100');
                $is_active        = (int) $request->post('is_active', '1');
                $description      = InputSanitizer::trim($request->post('description', ''));

                if ($itemId <= 0) {
                    echo json_encode(['status' => 'false', 'title' => 'Invalid Request', 'message' => 'Template ID is required.', 'csrf_token' => $new_csrf_token]);
                    return;
                }

                $repo = new SmsTemplateRepository();
                $existing = $repo->findById($itemId);
                if (!$existing) {
                    echo json_encode(['status' => 'false', 'title' => 'Not Found', 'message' => 'Template not found.', 'csrf_token' => $new_csrf_token]);
                    return;
                }

                if ($provider_name === '' || $sender_pattern === '' || $regex_pattern === '') {
                    echo json_encode(['status' => 'false', 'title' => 'Incomplete Information', 'message' => 'Provider Name, Sender Pattern, and Regex Pattern are required.', 'csrf_token' => $new_csrf_token]);
                    return;
                }

                // Validate regex
                set_error_handler(fn() => true);
                $regexValid = @preg_match($regex_pattern, '');
                restore_error_handler();
                if ($regexValid === false) {
                    echo json_encode(['status' => 'false', 'title' => 'Invalid Regex', 'message' => 'The regex pattern is not valid: ' . preg_last_error_msg(), 'csrf_token' => $new_csrf_token]);
                    return;
                }

                $repo->update($itemId, [
                    'provider_name'    => $provider_name,
                    'sender_pattern'   => $sender_pattern,
                    'regex_pattern'    => $regex_pattern,
                    'transaction_type' => $transaction_type,
                    'priority'         => $priority,
                    'is_active'        => $is_active,
                    'description'      => $description,
                ]);

                echo json_encode(['status' => 'true', 'title' => 'Template Updated', 'message' => 'The SMS template has been updated successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "sms-template-delete") {
            if ($ctx->isLoggedIn) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'sms_data')) { return; }
                if (PermissionGuard::denyUnlessHas($ctx, 'sms_data', 'delete')) { return; }

                $itemId = (int) $request->post('ItemID', '0');

                $repo = new SmsTemplateRepository();
                $existing = $repo->findById($itemId);
                if (!$existing) {
                    echo json_encode(['status' => 'false', 'title' => 'Not Found', 'message' => 'Template not found.', 'csrf_token' => $new_csrf_token]);
                    return;
                }

                $repo->delete($itemId);

                echo json_encode(['status' => 'true', 'title' => 'Template Deleted', 'message' => 'The SMS template has been deleted successfully.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "sms-template-test-regex") {
            if ($ctx->isLoggedIn) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'sms_data')) { return; }

                $regex_pattern    = $request->post('regex_pattern', '');
                $sample_text      = $request->post('sample_text', '');
                $transaction_type = InputSanitizer::trim($request->post('transaction_type', 'credit'));

                if ($regex_pattern === '' || $sample_text === '') {
                    echo json_encode(['status' => 'false', 'title' => 'Incomplete', 'message' => 'Both regex pattern and sample text are required.', 'csrf_token' => $new_csrf_token]);
                    return;
                }

                // Validate regex
                set_error_handler(fn() => true);
                $regexValid = @preg_match($regex_pattern, '');
                restore_error_handler();
                if ($regexValid === false) {
                    echo json_encode(['status' => 'false', 'title' => 'Invalid Regex', 'message' => 'The regex pattern is not valid: ' . preg_last_error_msg(), 'csrf_token' => $new_csrf_token]);
                    return;
                }

                // Run the regex parser
                $parser = new SmsRegexParser();
                $templates = [[
                    'id'               => 0,
                    'sender_pattern'   => 'test',
                    'regex_pattern'    => $regex_pattern,
                    'transaction_type' => $transaction_type,
                ]];

                $result = $parser->parse($sample_text, $templates);

                // Also capture raw regex matches for display
                $rawMatches = [];
                if (preg_match($regex_pattern, $sample_text, $matches)) {
                    foreach ($matches as $k => $v) {
                        if (is_string($k)) {
                            $rawMatches[$k] = $v;
                        }
                    }
                }

                echo json_encode([
                    'status'        => 'true',
                    'matched'       => $result !== null,
                    'parsed_result' => $result,
                    'raw_captures'  => $rawMatches,
                    'csrf_token'    => $new_csrf_token,
                ]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        // ─── Unparsed SMS Queue ─────────────────────────────────────────

        if ($action == "sms-queue-list") {
            if ($ctx->isLoggedIn) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'sms_data')) { return; }

                $brandId = (int) ($global_response_brand['response'][0]['brand_id'] ?? 0);

                $pag = \OwnPay\Service\PaginationService::resolve($request->post('page', '1'), $request->post('show_limit'));
                $page = $pag['page'];
                $show_limit_val = $pag['perPage'];
                $offset = $pag['offset'];

                try {
                    $pdo = \OwnPay\Core\Database::getInstance()->getPdo();

                    $where = "brand_id = :bid AND (status = 'admin_review' OR parse_method = 'unparsed')";
                    $params = [':bid' => $brandId];

                    $search_input = $request->post('search_input', '');
                    if ($search_input !== '') {
                        $where .= " AND (sender LIKE :search OR raw_message LIKE :search OR parsed_trx_id LIKE :search)";
                        $params[':search'] = "%{$search_input}%";
                    }

                    // Count
                    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM op_sms_parsed WHERE {$where}");
                    $countStmt->execute($params);
                    $total_records = (int) $countStmt->fetchColumn();

                    // Fetch
                    $sql = "SELECT id, device_uuid, sender, received_at, raw_message, parsed_amount, parsed_trx_id, parsed_sender, parsed_type, parse_method, parse_confidence, status, created_at
                            FROM op_sms_parsed WHERE {$where} ORDER BY received_at DESC";
                    if (!$pag['isAll']) {
                        $sql .= " LIMIT {$offset}, {$show_limit_val}";
                    }
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

                    $tz = $global_response_brand['response'][0]['timezone'] ?? 'Asia/Dhaka';

                    $response = [];
                    foreach ($rows as $row) {
                        $rawMsg = $row['raw_message'] ?? '';
                        $response[] = [
                            'id'               => $row['id'],
                            'device_uuid'      => $row['device_uuid'] ?? '',
                            'sender'           => $row['sender'],
                            'received_at'      => isset($row['received_at']) ? convertUTCtoUserTZ($row['received_at'], $tz, "M d, Y h:i A") : '',
                            'raw_message'      => mb_strlen($rawMsg) > 120 ? mb_substr($rawMsg, 0, 120) . '…' : $rawMsg,
                            'raw_message_full' => $rawMsg,
                            'parsed_amount'    => $row['parsed_amount'],
                            'parsed_trx_id'    => $row['parsed_trx_id'] ?? '',
                            'parsed_sender'    => $row['parsed_sender'] ?? '',
                            'parsed_type'      => $row['parsed_type'] ?? '',
                            'parse_method'     => $row['parse_method'] ?? 'unparsed',
                            'parse_confidence' => $row['parse_confidence'] ?? '',
                            'status'           => $row['status'],
                            'created_at'       => isset($row['created_at']) ? convertUTCtoUserTZ($row['created_at'], $tz, "M d, Y h:i A") : '',
                        ];
                    }

                    $pagHtml = \OwnPay\Service\PaginationService::render($page, $total_records, $show_limit_val, $offset);

                    echo json_encode([
                        'status'        => 'true',
                        'response'      => $response,
                        'datatableInfo' => $pagHtml['datatableInfo'],
                        'pagination'    => $pagHtml['pagination'],
                        'csrf_token'    => $new_csrf_token,
                    ]);
                } catch (\Throwable $e) {
                    echo json_encode(['status' => 'false', 'title' => 'Error', 'message' => 'Failed to load queue.', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "sms-queue-reprocess") {
            if ($ctx->isLoggedIn) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'sms_data')) { return; }
                if (PermissionGuard::denyUnlessHas($ctx, 'sms_data', 'edit')) { return; }

                $itemId = (int) $request->post('ItemID', '0');

                $dataRepo = new SmsDataRepository();
                $record = $dataRepo->findById($itemId);

                if (!$record) {
                    echo json_encode(['status' => 'false', 'title' => 'Not Found', 'message' => 'SMS record not found.', 'csrf_token' => $new_csrf_token]);
                    return;
                }

                $rawMessage = $record['raw_message'] ?? '';
                if (empty($rawMessage)) {
                    echo json_encode(['status' => 'false', 'title' => 'No Raw Message', 'message' => 'Raw message not available for re-parsing.', 'csrf_token' => $new_csrf_token]);
                    return;
                }

                // Tier 1: regex templates
                $templateRepo = new SmsTemplateRepository();
                $templates = $templateRepo->findBySender($record['sender'] ?? '');
                $regexParser = new SmsRegexParser();
                $parsed = $regexParser->parse($rawMessage, $templates);

                // Tier 2: heuristic fallback
                if ($parsed === null) {
                    $heuristicParser = new SmsHeuristicParser();
                    $parsed = $heuristicParser->parse($rawMessage);
                }

                if ($parsed === null) {
                    echo json_encode(['status' => 'false', 'title' => 'Parse Failed', 'message' => 'No parser could extract data from this message. Try adding a matching template first.', 'csrf_token' => $new_csrf_token]);
                    return;
                }

                $updateData = [
                    'parsed_amount'    => $parsed['parsed_amount'] ?? null,
                    'parsed_trx_id'    => $parsed['parsed_trx_id'] ?? null,
                    'parsed_sender'    => $parsed['parsed_sender'] ?? null,
                    'parsed_balance'   => $parsed['parsed_balance'] ?? null,
                    'parsed_type'      => $parsed['parsed_type'] ?? 'unknown',
                    'parse_method'     => $parsed['parse_method'] ?? 'unknown',
                    'template_id'      => $parsed['template_id'] ?? null,
                    'parse_confidence' => $parsed['parse_confidence'] ?? 'low',
                    'status'           => 'accepted',
                ];

                $dataRepo->updateParsedData($itemId, $updateData);

                echo json_encode([
                    'status'  => 'true',
                    'title'   => 'Reprocessed',
                    'message' => 'SMS has been successfully re-parsed. Amount: ' . ($updateData['parsed_amount'] ?? 'N/A') . ', Method: ' . $updateData['parse_method'],
                    'csrf_token' => $new_csrf_token,
                ]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if ($action == "sms-queue-resolve") {
            if ($ctx->isLoggedIn) {
                if (PermissionGuard::denyUnlessCanAccess($ctx, 'sms_data')) { return; }
                if (PermissionGuard::denyUnlessHas($ctx, 'sms_data', 'edit')) { return; }

                $itemId       = (int) $request->post('ItemID', '0');
                $amount       = $request->post('amount', '');
                $type         = InputSanitizer::trim($request->post('type', ''));
                $trx_id       = InputSanitizer::trim($request->post('trx_id', ''));
                $sender_number = InputSanitizer::trim($request->post('sender_number', ''));

                if ($itemId <= 0 || $amount === '' || $type === '') {
                    echo json_encode(['status' => 'false', 'title' => 'Incomplete', 'message' => 'Amount and transaction type are required.', 'csrf_token' => $new_csrf_token]);
                    return;
                }

                $dataRepo = new SmsDataRepository();
                $record = $dataRepo->findById($itemId);
                if (!$record) {
                    echo json_encode(['status' => 'false', 'title' => 'Not Found', 'message' => 'SMS record not found.', 'csrf_token' => $new_csrf_token]);
                    return;
                }

                $updateData = [
                    'parsed_amount'    => (float) $amount,
                    'parsed_type'      => $type,
                    'parsed_trx_id'    => $trx_id ?: null,
                    'parsed_sender'    => $sender_number ?: null,
                    'parse_method'     => 'manual',
                    'parse_confidence' => 'high',
                    'status'           => 'accepted',
                ];

                $dataRepo->updateParsedData($itemId, $updateData);

                echo json_encode(['status' => 'true', 'title' => 'Resolved', 'message' => 'The SMS record has been manually resolved.', 'csrf_token' => $new_csrf_token]);
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }
    }
}
