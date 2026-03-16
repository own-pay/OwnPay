<?php
declare(strict_types=1);

namespace AnirbanPay\Controller;

use AnirbanPay\Http\RequestContext;

/**
 * Companion API Controller
 *
 * Handles companion (mobile) app endpoints (action-companion):
 * - login: OTP-based device login
 * - account-information: Account info + SMS data listing
 * - sms-transmit-bulk: Bulk SMS ingest with MFS verification
 * - sms-transmit-sender: Fetch sender whitelist
 * - delete-sms-data: Delete SMS data by category
 */
class CompanionApiController
{
    public static function handle(string $action, RequestContext $ctx): void
    {
        $controller = new self();

        switch ($action) {
            case 'login':
                $controller->login($ctx);
                break;
            case 'account-information':
                $controller->accountInformation($ctx);
                break;
            case 'sms-transmit-bulk':
                $controller->smsTransmitBulk($ctx);
                break;
            case 'sms-transmit-sender':
                $controller->smsTransmitSender($ctx);
                break;
            case 'delete-sms-data':
                $controller->deleteSmsData($ctx);
                break;
        }
    }

    private function login(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $ap_demo_mode = $ctx->demoMode;

        if (!empty($ap_demo_mode)) {
            echo json_encode(['status' => "false", 'title' => 'Demo Restriction', 'message' => 'This feature is disabled in the demo version.']);
            return;
        }

        $request = \AnirbanPay\Http\Request::createFromGlobals();
        $onetimepassword = $request->post('onetimepassword', '');
        $name = $request->post('name', '');
        $model = $request->post('model', '');
        $android_level = $request->post('android_level', '');
        $app_version = $request->post('app_version', '');

        if ($onetimepassword == "") {
            echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.']);
        } else {
            $params = [':otp' => $onetimepassword];

            $response = json_decode(getData($db_prefix . 'device', 'WHERE otp = :otp', '* FROM', $params), true);
            if ($response['status'] == true) {
                $otp_new = generateItemID();

                $columns = ['otp', 'status', 'name', 'model', 'android_level', 'app_version', 'updated_date'];
                $values = [$otp_new, 'used', $name, $model, $android_level, $app_version, getCurrentDatetime('Y-m-d H:i:s')];

                $params_device_upd = [':id' => $response['response'][0]['id']];
                updateData($db_prefix . 'device', $columns, $values, 'id = :id', $params_device_upd);

                echo json_encode(['status' => "true", 'token' => $otp_new]);
            } else {
                echo json_encode(['status' => "false", 'title' => 'Invalid Credentials', 'message' => 'Please enter the correct credentials or scan the QR code again.']);
            }
        }
    }

    private function accountInformation(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $ap_demo_mode = $ctx->demoMode;

        if (!empty($ap_demo_mode)) {
            echo json_encode(['status' => "false", 'title' => 'Demo Restriction', 'message' => 'This feature is disabled in the demo version.']);
            return;
        }

        $request = \AnirbanPay\Http\Request::createFromGlobals();
        $token = $request->post('token', '');

        if ($token == "") {
            echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.']);
        } else {
            $params = [':otp' => $token, ':status' => 'used'];

            $response = json_decode(getData($db_prefix . 'device', 'WHERE otp = :otp AND status = :status', '* FROM', $params), true);
            if ($response['status'] == true) {
                $params = [':cookie' => $response['response'][0]['d_id']];

                $responseLog = json_decode(getData($db_prefix . 'browser_log', 'WHERE cookie = :cookie', '* FROM', $params), true);
                if ($responseLog['status'] == true) {
                    $params = [':a_id' => $responseLog['response'][0]['a_id']];

                    $responseAdmin = json_decode(getData($db_prefix . 'admin', 'WHERE a_id = :a_id', '* FROM', $params), true);
                    if ($responseAdmin['status'] == true) {

                        $params_sms = [':device_id' => $response['response'][0]['device_id']];
                        $response_result = json_decode(getData($db_prefix . 'sms_data', ' WHERE source = "app" AND device_id = :device_id AND status NOT IN ("awaiting-review") ORDER BY 1 DESC', '* FROM', $params_sms), true);

                        if ($response_result['status'] == true) {

                            $responseData = [
                                'status' => 'true',
                                'fullname' => $responseAdmin['response'][0]['full_name'] ?? '',
                                'email' => $responseAdmin['response'][0]['email'] ?? '',
                                'stored_count' => 0,
                                'used_count' => 0,
                                'error_count' => 0,
                                'stored' => [],
                                'used' => [],
                                'error' => []
                            ];

                            foreach ($response_result['response'] as $row) {
                                $json_status = ($row['status'] === 'approved') ? 'stored' : $row['status'];

                                $item = [
                                    'id' => $row['id'],
                                    'sender' => $row['sender'],
                                    'message' => $row['message'],
                                    'reason' => $row['reason'],
                                    'simslot' => $row['simslot'],
                                    'timestamp' => convertUTCtoUserTZ($row['created_date'], (get_env('geneal-application-settings-default_timezone') === '--' || get_env('geneal-application-settings-default_timezone') === '') ? 'Asia/Dhaka' : get_env('geneal-application-settings-default_timezone'), "M d, Y h:i A"),
                                    'status' => $json_status
                                ];

                                switch ($row['status']) {
                                    case 'approved':
                                    case 'awaiting-review':
                                        $responseData['stored'][] = $item;
                                        $responseData['stored_count']++;
                                        break;

                                    case 'used':
                                        $responseData['used'][] = $item;
                                        $responseData['used_count']++;
                                        break;

                                    case 'error':
                                        $responseData['error'][] = $item;
                                        $responseData['error_count']++;
                                        break;
                                }
                            }

                            echo json_encode($responseData);
                        } else {
                            $responseData = [
                                'status' => 'true',
                                'fullname' => $responseAdmin['response'][0]['full_name'] ?? '',
                                'email' => $responseAdmin['response'][0]['email'] ?? '',
                                'stored_count' => 0,
                                'used_count' => 0,
                                'error_count' => 0,
                                'stored' => [],
                                'used' => [],
                                'error' => []
                            ];

                            echo json_encode($responseData);
                        }
                    } else {
                        echo json_encode(['status' => "false", 'title' => 'Authentication Failed', 'message' => 'Please try again or scan the QR code again.']);
                    }
                } else {
                    echo json_encode(['status' => "false", 'title' => 'Authentication Failed', 'message' => 'Please try again or scan the QR code again.']);
                }
            } else {
                echo json_encode(['status' => "false", 'title' => 'Authentication Failed', 'message' => 'Please try again or scan the QR code again.']);
            }
        }
    }

    private function smsTransmitBulk(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $ap_demo_mode = $ctx->demoMode;

        if (!empty($ap_demo_mode)) {
            echo json_encode(['status' => "false", 'title' => 'Demo Restriction', 'message' => 'This feature is disabled in the demo version.']);
            return;
        }

        $request = \AnirbanPay\Http\Request::createFromGlobals();
        $token = $request->post('token', '');
        $sms_list_raw = $request->post('sms_list', '');

        if ($token == "") {
            echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.']);
        } else {
            $params = [':otp' => $token, ':status' => 'used'];

            $response = json_decode(getData($db_prefix . 'device', 'WHERE otp = :otp AND status = :status', '* FROM', $params), true);
            if ($response['status'] == true) {
                $sms_list = json_decode($sms_list_raw, true);

                foreach ($sms_list as $sms) {
                    $id = trim((string) escape_string($sms['id'] ?? ''));
                    $sender = strtolower((string) trim(escape_string($sms['sender'] ?? '')));
                    $message = trim((string) escape_string($sms['message'] ?? ''));
                    $simslot = trim((string) escape_string($sms['simSlot'] ?? ''));
                    $timestamp = trim((string) escape_string($sms['timestamp'] ?? ''));

                    $status = 'approved';
                    $reason = '--';

                    $device_id = $response['response'][0]['device_id'];

                    $senderInfo = senderWhitelist($sender);
                    if ($senderInfo) {
                        $sender_key = $senderInfo['provider_key'];
                        $currency = $senderInfo['currency'];
                        $balance_verify = $senderInfo['balance_verify'];
                    } else {
                        $sender_key = '--';
                        $currency = '--';
                        $balance_verify = '--';
                    }

                    $result = MFSMessageVerified($sender_key, $message);

                    if ($result === false) {
                        $status = 'error';
                        $reason = 'Invalid or unknown message. Code 101';

                        $columns = ['source', 'device_id', 'sender', 'simslot', 'status', 'message', 'reason', 'created_date', 'updated_date'];
                        $values = ['app', $device_id, $sender, $simslot, $status, $message, $reason, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                        insertData($db_prefix . 'sms_data', $columns, $values);

                        $params_device_upd = [':id' => $response['response'][0]['id']];
                        updateData($db_prefix . 'device', $columns, $values, 'id = :id', $params_device_upd);

                        echo json_encode(['status' => "false", 'title' => 'Invalid or unknown MFS message', 'message' => 'Please fill in all required fields before proceeding.']);
                    } else {
                        $type = escape_string($result['type'] ?? '');
                        $amount = escape_string($result['amount'] ?? '0');
                        $balance = escape_string($result['balance'] ?? '0');
                        $phone_number = escape_string($result['sender'] ?? '');
                        $transaction_id = escape_string($result['trxid'] ?? '');
                        $datetime = escape_string($result['datetime'] ?? '');

                        if ($type == "" || $amount == "" || $phone_number == "" || $transaction_id == "") {
                            $status = 'error';
                            $reason = 'Invalid or unknown message. Code 102';

                            $columns = ['source', 'device_id', 'sender', 'simslot', 'status', 'message', 'reason', 'created_date', 'updated_date'];
                            $values = ['app', $device_id, $sender, $simslot, $status, $message, $reason, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                            insertData($db_prefix . 'sms_data', $columns, $values);

                            $params_device_upd = [':id' => $response['response'][0]['id']];
                            updateData($db_prefix . 'device', $columns, $values, 'id = :id', $params_device_upd);

                            echo json_encode(['status' => "false", 'title' => 'Invalid or unknown MFS message', 'message' => 'Please fill in all required fields before proceeding.']);
                            exit();
                        }

                        $params = [':sender_key' => $sender_key, ':trx_id' => $transaction_id];

                        $responseSmsData = json_decode(getData($db_prefix . 'sms_data', 'WHERE sender_key = :sender_key AND trx_id = :trx_id', '* FROM', $params), true);
                        if ($responseSmsData['status'] == false) {
                            if ($balance_verify == "false") {
                                $status = 'approved';
                                $reason = '--';

                                $columns = ['source', 'device_id', 'sender', 'sender_key', 'simslot', 'number', 'amount', 'currency', 'trx_id', 'balance', 'type', 'status', 'message', 'reason', 'created_date', 'updated_date'];
                                $values = ['app', $device_id, $sender, $sender_key, $simslot, $phone_number, money_sanitize($amount), $currency, $transaction_id, money_sanitize($balance), $type, $status, $message, $reason, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                                insertData($db_prefix . 'sms_data', $columns, $values);

                                $params_device_upd = [':id' => $response['response'][0]['id']];
                                updateData($db_prefix . 'device', $columns, $values, 'id = :id', $params_device_upd);

                                echo json_encode(['status' => 'true', 'title' => 'SMS Data Created', 'message' => 'The sms data has been created successfully.']);
                            } else {
                                $params = [':device_id' => $device_id, ':sender_key' => $sender_key, ':type' => $type];

                                $response_balance_verification = json_decode(getData($db_prefix . 'balance_verification', 'WHERE device_id = :device_id AND sender_key = :sender_key AND type = :type', '* FROM', $params), true);
                                if ($response_balance_verification['status'] == true) {
                                    if ($response_balance_verification['response'][0]['status'] == "active") {
                                        if ($simslot == 1) {
                                            $bsimslot = 'Sim1';
                                        } else {
                                            $bsimslot = 'Sim2';
                                        }

                                        $expected_balance = money_add($response_balance_verification['response'][0]['current_balance'], $amount);

                                        if ($expected_balance == $balance) {
                                            if ($response_balance_verification['response'][0]['simslot'] !== "Any") {
                                                if ($response_balance_verification['response'][0]['simslot'] == $bsimslot) {
                                                    $status = 'approved';
                                                    $reason = '--';

                                                    $columns_bv = ['current_balance'];
                                                    $values_bv = [$balance];
                                                    $params_bv_upd = [':id' => $response_balance_verification['response'][0]['id']];
                                                    updateData($db_prefix . 'balance_verification', $columns_bv, $values_bv, 'id = :id', $params_bv_upd);
                                                } else {
                                                    $status = 'awaiting-review';
                                                    $reason = 'SIM slot and expected slot do not match. Recorded: ' . $bsimslot . '; Expected: ' . $response_balance_verification['response'][0]['simslot'];
                                                }
                                            } else {
                                                $status = 'approved';
                                                $reason = '--';

                                                $columns_bv = ['current_balance'];
                                                $values_bv = [$balance];
                                                $params_bv_upd = [':id' => $response_balance_verification['response'][0]['id']];
                                                updateData($db_prefix . 'balance_verification', $columns_bv, $values_bv, 'id = :id', $params_bv_upd);
                                            }

                                            $columns = ['source', 'device_id', 'sender', 'sender_key', 'simslot', 'number', 'amount', 'currency', 'trx_id', 'balance', 'type', 'status', 'message', 'reason', 'created_date', 'updated_date'];
                                            $values = ['app', $device_id, $sender, $sender_key, $simslot, $phone_number, money_sanitize($amount), $currency, $transaction_id, money_sanitize($balance), $type, $status, $message, $reason, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                                            insertData($db_prefix . 'sms_data', $columns, $values);

                                            $params_device_upd = [':id' => $response['response'][0]['id']];
                                            updateData($db_prefix . 'device', $columns, $values, 'id = :id', $params_device_upd);

                                            echo json_encode(['status' => 'true', 'title' => 'SMS Data Created', 'message' => 'The sms data has been created successfully.']);
                                        } else {
                                            $reasons = [];

                                            $status = 'awaiting-review';
                                            $reasons[] = 'SMS balance and expected balance do not match. Recorded SMS balance: ' . money_round($balance) . '; Expected balance: ' . money_round($expected_balance);

                                            if ($response_balance_verification['response'][0]['simslot'] !== "Any") {
                                                if ($response_balance_verification['response'][0]['simslot'] == $bsimslot) {

                                                } else {
                                                    $status = 'awaiting-review';
                                                    $reasons[] = 'SIM slot and expected slot do not match. Recorded: ' . $bsimslot . '; Expected: ' . $response_balance_verification['response'][0]['simslot'];
                                                }
                                            }

                                            $reason = implode(' | ', $reasons);

                                            $columns = ['source', 'device_id', 'sender', 'sender_key', 'simslot', 'number', 'amount', 'currency', 'trx_id', 'balance', 'type', 'status', 'message', 'reason', 'created_date', 'updated_date'];
                                            $values = ['app', $device_id, $sender, $sender_key, $simslot, $phone_number, money_sanitize($amount), $currency, $transaction_id, money_sanitize($balance), $type, $status, $message, $reason, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                                            insertData($db_prefix . 'sms_data', $columns, $values);

                                            $params_device_upd = [':id' => $response['response'][0]['id']];
                                            updateData($db_prefix . 'device', $columns, $values, 'id = :id', $params_device_upd);

                                            reconcileByLongestChain($device_id, $sender_key, $type);

                                            echo json_encode(['status' => 'true', 'title' => 'SMS Data Created', 'message' => 'The sms data has been created successfully.']);
                                        }
                                    } else {
                                        $status = 'approved';
                                        $reason = '--';

                                        $columns = ['source', 'device_id', 'sender', 'sender_key', 'simslot', 'number', 'amount', 'currency', 'trx_id', 'balance', 'type', 'status', 'message', 'reason', 'created_date', 'updated_date'];
                                        $values = ['app', $device_id, $sender, $sender_key, $simslot, $phone_number, money_sanitize($amount), $currency, $transaction_id, money_sanitize($balance), $type, $status, $message, $reason, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                                        insertData($db_prefix . 'sms_data', $columns, $values);

                                        $params_device_upd = [':id' => $response['response'][0]['id']];
                                        updateData($db_prefix . 'device', $columns, $values, 'id = :id', $params_device_upd);

                                        echo json_encode(['status' => 'true', 'title' => 'SMS Data Created', 'message' => 'The sms data has been created successfully.']);
                                    }
                                } else {
                                    $status = 'approved';
                                    $reason = '--';

                                    $columns = ['source', 'device_id', 'sender', 'sender_key', 'simslot', 'number', 'amount', 'currency', 'trx_id', 'balance', 'type', 'status', 'message', 'reason', 'created_date', 'updated_date'];
                                    $values = ['app', $device_id, $sender, $sender_key, $simslot, $phone_number, money_sanitize($amount), $currency, $transaction_id, money_sanitize($balance), $type, $status, $message, $reason, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                                    insertData($db_prefix . 'sms_data', $columns, $values);

                                    $params_device_upd = [':id' => $response['response'][0]['id']];
                                    updateData($db_prefix . 'device', $columns, $values, 'id = :id', $params_device_upd);

                                    echo json_encode(['status' => 'true', 'title' => 'SMS Data Created', 'message' => 'The sms data has been created successfully.']);
                                }
                            }
                        } else {
                            $status = 'error';
                            $reason = 'Duplicate message. Code 103';

                            $columns = ['source', 'device_id', 'sender', 'simslot', 'status', 'message', 'reason', 'created_date', 'updated_date'];
                            $values = ['app', $device_id, $sender, $simslot, $status, $message, $reason, getCurrentDatetime('Y-m-d H:i:s'), getCurrentDatetime('Y-m-d H:i:s')];

                            insertData($db_prefix . 'sms_data', $columns, $values);

                            $params_device_upd = [':id' => $response['response'][0]['id']];
                            updateData($db_prefix . 'device', $columns, $values, 'id = :id', $params_device_upd);

                            echo json_encode(['status' => 'false', 'title' => 'Duplicate Transaction', 'message' => 'The provided Transaction ID already exists in our system.']);
                        }
                    }
                }
            } else {
                echo json_encode(['status' => "false", 'title' => 'Authentication Failed', 'message' => 'Please try again or scan the QR code again.']);
            }
        }
    }

    private function smsTransmitSender(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $ap_demo_mode = $ctx->demoMode;

        if (!empty($ap_demo_mode)) {
            echo json_encode(['status' => "false", 'title' => 'Demo Restriction', 'message' => 'This feature is disabled in the demo version.']);
            return;
        }

        $request = \AnirbanPay\Http\Request::createFromGlobals();
        $token = $request->post('token', '');

        if ($token == "") {
            echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.']);
        } else {
            $params = [':otp' => $token, ':status' => 'used'];

            $response = json_decode(getData($db_prefix . 'device', 'WHERE otp = :otp AND status = :status', '* FROM', $params), true);
            if ($response['status'] == true) {
                $senders = senderWhitelist(null, null, 'senders');

                echo json_encode(["status" => "true", "senders" => $senders], JSON_PRETTY_PRINT);
            } else {
                echo json_encode(['status' => "false", 'title' => 'Authentication Failed', 'message' => 'Please try again or scan the QR code again.']);
            }
        }
    }

    private function deleteSmsData(RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $ap_demo_mode = $ctx->demoMode;

        if (!empty($ap_demo_mode)) {
            echo json_encode(['status' => "false", 'title' => 'Demo Restriction', 'message' => 'This feature is disabled in the demo version.']);
            return;
        }

        $request = \AnirbanPay\Http\Request::createFromGlobals();
        $token = $request->post('token', '');
        $stored = $request->post('stored', '');
        $used = $request->post('used', '');
        $error = $request->post('error', '');

        if ($token == "") {
            echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.']);
        } else {
            $params = [':otp' => $token, ':status' => 'used'];

            $response = json_decode(getData($db_prefix . 'device', 'WHERE otp = :otp AND status = :status', '* FROM', $params), true);
            if ($response['status'] == true) {
                $params_del = [':device_id' => $response['response'][0]['device_id']];
                if ($stored == "yes") {
                    deleteData($db_prefix . 'sms_data', "device_id = :device_id AND status = 'approved'", $params_del);
                    deleteData($db_prefix . 'sms_data', "device_id = :device_id AND status = 'awaiting-review'", $params_del);
                }

                if ($used == "yes") {
                    deleteData($db_prefix . 'sms_data', "device_id = :device_id AND status = 'used'", $params_del);
                }

                if ($error == "yes") {
                    deleteData($db_prefix . 'sms_data', "device_id = :device_id AND status = 'error'", $params_del);
                }

                echo json_encode(['status' => "true", 'title' => 'Deletion Successful', 'message' => 'The selected data has been deleted successfully.']);
            } else {
                echo json_encode(['status' => "false", 'title' => 'Authentication Failed', 'message' => 'Please try again or scan the QR code again.']);
            }
        }
    }
}
