<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Http\RequestContext;
use OwnPay\Service\Auth\AuthSessionService;
use OwnPay\Service\System\CrudService;

class AuthController
{
    /**
     * Process a successful login: set cookies, create session, generate 2FA secret if needed.
     */
    private static function processSuccessfulLogin(array $user, string $password, string $hashColumn, RequestContext $ctx): void
    {
        $db_prefix = $ctx->dbPrefix;
        $path_admin = $ctx->pathAdmin;
        $new_csrf_token = $ctx->csrfToken;

        // Transparently rehash to Argon2id if needed
        if (password_needs_rehash($user[$hashColumn], PASSWORD_ARGON2ID)) {
            $rehashed = password_hash($password, PASSWORD_ARGON2ID);
            CrudService::update($db_prefix . 'merchant_users', [$hashColumn], [$rehashed], 'id = :id', [':id' => $user['id']]);
        }

        if ($user['status'] !== 'active') {
            echo json_encode(['status' => 'false', 'title' => 'Login Failed', 'message' => 'Your account has been suspended. Please contact with your admin.', 'csrf_token' => $new_csrf_token]);
            return;
        }

        session_regenerate_id(true);

        $cookie = bin2hex(random_bytes(16));
        $userInfo = getUserDeviceInfo();

        if ($user['two_fa_status'] === 'enabled') {
            AuthSessionService::setCookie('op_2fa', $cookie);
            $target = '2fa';
        } else {
            AuthSessionService::setCookie('op_admin', $cookie);
            $target = $path_admin . '/dashboard';
        }

        // Generate 2FA secret if not set
        if ($user['two_fa_secret'] === null || $user['two_fa_secret'] === '' || empty($user['two_fa_secret'])) {
            $ga = new \OwnPay\Security\Authenticator();
            $secret = $ga->createSecret();
            CrudService::update($db_prefix . 'merchant_users', ['two_fa_secret'], [$secret], 'id = :id', [':id' => $user['id']]);
        }

        // Create session record - mapping to V2 `sessions` table schema
        $columns = ['cookie', 'user_id', 'merchant_id', 'role_id', 'status', 'browser', 'device', 'ip', 'created_at'];
        $values = [
            $cookie,
            $user['id'],
            $user['merchant_id'],
            $user['role_id'],
            'active',
            $userInfo['browser'] ?? '',
            $userInfo['device'] ?? '',
            $userInfo['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''),
            getCurrentDatetime('Y-m-d H:i:s')
        ];
        CrudService::insert($db_prefix . 'sessions', $columns, $values);

        echo json_encode(['status' => 'true', 'target' => $target, 'csrf_token' => $new_csrf_token]);
    }

    public static function handle(string $action, ?RequestContext $ctx = null): void
    {
        $ctx ??= $GLOBALS['requestContext'] ?? throw new \RuntimeException('RequestContext not available');
        $db_prefix = $ctx->dbPrefix;
        $path_admin = $ctx->pathAdmin;
        $new_csrf_token = $ctx->csrfToken;
        $global_user_login = $ctx->isLoggedIn;
        $global_user_response = $ctx->userResponse;
        $op_admin = $ctx->isAdmin();
        $global_two_fector_validate = $GLOBALS['global_two_fector_validate'] ?? false;
        $op_demo_mode = $ctx->demoMode;
        $global_response_brand = $ctx->brandResponse;
        $global_cookie_response = $ctx->cookieResponse;
        $global_user_2fa = $GLOBALS['global_user_2fa'] ?? false;

        $request = \OwnPay\Http\Request::createFromGlobals();

        if ($action === 'login') {
            // Rate limit: 5 login attempts per minute per IP
            $rateLimiter = new \OwnPay\Middleware\RateLimiterMiddleware();
            $ipKey = 'login_ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            // F11: SHA-256-derived bucket id (was CRC32 — collision risk per audit)
            $rateResult = $rateLimiter->check((int) hexdec(substr(hash('sha256', $ipKey), 0, 8)), '', 'POST', 5);
            if (!$rateResult['allowed']) {
                echo json_encode(['status' => 'false', 'title' => 'Too Many Attempts', 'message' => 'Please try again in ' . $rateResult['retryAfter'] . ' seconds.', 'csrf_token' => $new_csrf_token]);
                return;
            }

            $email_username = $request->post('username', '');
            $password = $request->post('password', '');

            if ($email_username === '' || $password === '') {
                echo json_encode(['status' => 'false', 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
            } else {
                if (filter_var($email_username, FILTER_VALIDATE_EMAIL)) {
                    $params = [':email' => $email_username];
                    $sql_email_username = 'email = :email';
                } else {
                    $params = [':username' => $email_username];
                    $sql_email_username = 'username = :username';
                }

                $response = CrudService::select($db_prefix . 'merchant_users', 'WHERE ' . $sql_email_username, '* FROM', $params);

                if ($response['status'] == true) {
                    $user = $response['response'][0];
                    if (password_verify($password, $user['password_hash'])) {
                        self::processSuccessfulLogin($user, $password, 'password_hash', $ctx);
                    } elseif (password_verify($password, $user['temp_password'])) {
                        self::processSuccessfulLogin($user, $password, 'temp_password', $ctx);
                    } else {
                        echo json_encode(['status' => 'false', 'title' => 'Login Failed', 'message' => 'The email or password you entered is incorrect.', 'csrf_token' => $new_csrf_token]);
                    }
                } else {
                    echo json_encode(['status' => 'false', 'title' => 'Login Failed', 'message' => 'The email or password you entered is incorrect.', 'csrf_token' => $new_csrf_token]);
                }
            }
        }


        elseif ($action === '2fa-verify') {
            $code_one = $request->post('code_one', '');
            $code_two = $request->post('code_two', '');
            $code_three = $request->post('code_three', '');
            $code_four = $request->post('code_four', '');
            $code_five = $request->post('code_five', '');
            $code_six = $request->post('code_six', '');

            if ($code_one == "" || $code_two == "" || $code_three == "" || $code_four == "" || $code_five == "" || $code_six == "") {
                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
            } else {
                if ($global_user_2fa == true) {
                    $params = [':id' => $global_user_response['response'][0]['id']];

                    $response = CrudService::select($db_prefix . 'merchant_users', 'WHERE id = :id', '* FROM', $params);

                    if ($response['status'] == true) {
                        $ga = new \OwnPay\Security\Authenticator();
                        $row = $response['response'][0];
                        $code = $code_one . $code_two . $code_three . $code_four . $code_five . $code_six;

                        // F6: replay-guarded TOTP — same window cannot be reused
                        $matchedWindow = $ga->verifyCodeWithReplayGuard(
                            $row['two_fa_secret'],
                            $code,
                            (int) ($row['last_otp_window'] ?? 0),
                            2
                        );

                        if ($matchedWindow > 0) {
                            // Persist consumed window before issuing session
                            CrudService::update(
                                $db_prefix . 'merchant_users',
                                ['last_otp_window'],
                                [$matchedWindow],
                                'id = :id',
                                [':id' => $row['id']]
                            );

                            AuthSessionService::destroySession();

                            AuthSessionService::setCookie('op_brand', $global_response_brand['response'][0]['brand_id']);
                            AuthSessionService::setCookie('op_admin', $global_cookie_response['response'][0]['cookie']);

                            echo json_encode(['status' => "true", 'target' => $path_admin . '/dashboard', 'csrf_token' => $new_csrf_token]);
                        } else {
                            echo json_encode(['status' => "false", 'title' => 'Verification Failed', 'message' => 'The code you entered is incorrect or has already been used. Please try again.', 'csrf_token' => $new_csrf_token]);
                        }
                    } else {
                        echo json_encode(['status' => "false", 'title' => 'Login Failed', 'message' => 'You do not have access to this account. Please check your credentials.', 'csrf_token' => $new_csrf_token]);
                    }
                } else {
                    echo json_encode(['status' => "false", 'title' => 'Oops! Something went wrong', 'message' => 'Your request could not be processed. Please try again.', 'csrf_token' => $new_csrf_token]);
                }
            }
        }


        elseif ($action === 'forgot-password') {
            $email_address = $request->post('email-address', '');

            if ($email_address == "") {
                echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
            } else {
                // F15: rate-limit forgot-password by (IP + email) at 3 per minute.
                // Mitigates email-enumeration timing oracle and mail-server abuse.
                $rateLimiter = new \OwnPay\Middleware\RateLimiterMiddleware();
                $forgotKey = 'forgot:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . ':' . strtolower(trim($email_address));
                $rateResult = $rateLimiter->check(
                    (int) hexdec(substr(hash('sha256', $forgotKey), 0, 8)),
                    '',
                    'POST',
                    3
                );
                if (!$rateResult['allowed']) {
                    // Same response shape as success — do not leak rate-limit-vs-not-found
                    echo json_encode(['status' => "true", 'title' => 'Password Reset', 'message' => 'If the email exists, a reset link has been sent. Please check your inbox.', 'csrf_token' => $new_csrf_token]);
                    return;
                }
                if (filter_var($email_address, FILTER_VALIDATE_EMAIL)) {
                    $params = [':email' => $email_address, ':status' => 'active'];

                    $response = CrudService::select($db_prefix . 'merchant_users', 'WHERE email = :email AND status = :status', '* FROM', $params);

                    if ($response['status'] == true) {

                        // Note: The previous logic relied on a `reset_limit` column which does not exist in `merchant_users`.
                        // For now we will allow reset if we found the user.
                        $new_temp_password = generateStrongPassword(8);
                        $temp_password = password_hash($new_temp_password, PASSWORD_ARGON2ID);

                        $columns = ['temp_password'];
                        $values = [$temp_password];
                        $condition = "id = :id";
                        $whereParams = [':id' => $response['response'][0]['id']];

                        CrudService::update($db_prefix . 'merchant_users', $columns, $values, $condition, $whereParams);

                        $action_data = [
                            'full_name' => $response['response'][0]['full_name'],
                            'new_password' => $new_temp_password,
                            'email' => $response['response'][0]['email'],
                        ];

                        if (function_exists('do_action')) {
                            do_action('forgot.password', $action_data);
                        }

                        echo json_encode(['status' => "true", 'title' => 'We have emailed your new password.', 'message' => "If your account doesn't exist, you will not receive the email.", 'csrf_token' => $new_csrf_token]);

                    } else {
                        echo json_encode(['status' => "true", 'title' => 'We have emailed your new password.', 'message' => "If your account doesn't exist, you will not receive the email.", 'csrf_token' => $new_csrf_token]);
                    }
                } else {
                    echo json_encode(['status' => "false", 'title' => 'Invalid Email', 'message' => 'Please enter a valid email address.', 'csrf_token' => $new_csrf_token]);
                }
            }
        }


        elseif ($action === 'set-default-brand') {
            if ($global_user_login == true) {
                $brand_id = $request->post('brand_id', '');

                if ($brand_id == "") {
                    echo json_encode(['status' => "false", 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                } else {
                    $params = [':a_id' => $global_user_response['response'][0]['a_id'], ':status' => 'active', ':brand_id' => $brand_id];

                    $response = CrudService::select($db_prefix . 'permission', 'WHERE a_id = :a_id AND status = :status AND brand_id = :brand_id', '* FROM', $params);
                    if ($response['status'] == true) {
                        AuthSessionService::setCookie('op_brand', $brand_id);

                        echo json_encode(['status' => "true", 'csrf_token' => $new_csrf_token]);
                    } else {
                        echo json_encode(['status' => "false", 'title' => 'Brand Access Failed', 'message' => 'You don’t have permission to manage brands. Contact your admin.', 'csrf_token' => $new_csrf_token]);
                    }
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        elseif ($action === 'my-account-profile-information') {
            if ($global_user_login == true) {
                if (!empty($op_demo_mode)) {
                    echo json_encode(['status' => 'false', 'title' => 'Demo Restriction', 'message' => 'This feature is disabled in the demo version.', 'csrf_token' => $new_csrf_token]);
                } else {
                    $fullname = $request->post('fullname', '');
                    $username = $request->post('username', '');
                    $email_address = $request->post('email-address', '');
                    $password = $request->post('password', '');

                    if ($fullname === '' || $username === '' || $email_address === '') {
                        echo json_encode(['status' => 'false', 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                    } else {
                        if (filter_var($email_address, FILTER_VALIDATE_EMAIL)) {
                            if ($global_two_fector_validate == false) {
                                echo json_encode(['status' => 'false', 'title' => 'Verification Failed', 'message' => 'The code/password you entered is incorrect. Please try again.', 'csrf_token' => $new_csrf_token]);
                                exit();
                            }

                            if ($fullname === '') {
                                $fullname = $global_user_response['response'][0]['full_name'];
                            }

                            // Check username uniqueness against merchant_users
                            if ($username !== $global_user_response['response'][0]['username']) {
                                $params = [':username' => $username];
                                $response = CrudService::select($db_prefix . 'merchant_users', 'WHERE username = :username', '* FROM', $params);
                                if ($response['status'] == true) {
                                    echo json_encode(['status' => 'false', 'title' => 'Duplicate Username', 'message' => 'Username already exists.', 'csrf_token' => $new_csrf_token]);
                                    exit();
                                }
                            }

                            // Check email uniqueness against merchant_users
                            if ($email_address !== $global_user_response['response'][0]['email']) {
                                $params = [':email' => $email_address];
                                $response = CrudService::select($db_prefix . 'merchant_users', 'WHERE email = :email', '* FROM', $params);
                                if ($response['status'] == true) {
                                    echo json_encode(['status' => 'false', 'title' => 'Duplicate Email', 'message' => 'Email address already exists.', 'csrf_token' => $new_csrf_token]);
                                    exit();
                                }
                            }

                            if ($password === '') {
                                $password_hash = $global_user_response['response'][0]['password_hash'];
                                $temp_password = $global_user_response['response'][0]['temp_password'];
                            } else {
                                $new_temp_password = generateStrongPassword(8);
                                $password_hash = password_hash($password, PASSWORD_ARGON2ID);
                                $temp_password = password_hash($new_temp_password, PASSWORD_ARGON2ID);
                            }

                            $columns = ['full_name', 'username', 'email', 'password_hash', 'temp_password', 'updated_date'];
                            $values = [$fullname, $username, $email_address, $password_hash, $temp_password, getCurrentDatetime('Y-m-d H:i:s')];
                            $condition = 'id = :id';
                            $whereParams = [':id' => $global_user_response['response'][0]['id']];

                            CrudService::update($db_prefix . 'merchant_users', $columns, $values, $condition, $whereParams);

                            echo json_encode(['status' => 'true', 'title' => 'Profile Updated', 'message' => 'Your profile information has been updated successfully.', 'csrf_token' => $new_csrf_token]);
                        } else {
                            echo json_encode(['status' => 'false', 'title' => 'Invalid Email', 'message' => 'Please enter a valid email address.', 'csrf_token' => $new_csrf_token]);
                        }
                    }
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        elseif ($action === 'my-account-account-browser-sessions') {
            if ($global_user_login == true) {
                if (!empty($op_demo_mode)) {
                    echo json_encode(['status' => 'false', 'title' => 'Demo Restriction', 'message' => 'This feature is disabled in the demo version.', 'csrf_token' => $new_csrf_token]);
                } else {
                    if ($global_two_fector_validate == false) {
                        echo json_encode(['status' => 'false', 'title' => 'Verification Failed', 'message' => 'The code/password you entered is incorrect. Please try again.', 'csrf_token' => $new_csrf_token]);
                        exit();
                    }

                    $columns = ['status', 'updated_date'];
                    $values = ['expired', getCurrentDatetime('Y-m-d H:i:s')];
                    $condition = 'user_id = :user_id AND cookie != :op_admin';
                    $whereParams = [':user_id' => $global_user_response['response'][0]['id'], ':op_admin' => $op_admin];

                    CrudService::update($db_prefix . 'sessions', $columns, $values, $condition, $whereParams);

                    echo json_encode(['status' => 'true', 'title' => 'Logged Out Successfully', 'message' => 'You have been logged out of all other browser sessions.', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        elseif ($action === 'my-account-account-two-factor-authentication') {
            if ($global_user_login == true) {
                if (!empty($op_demo_mode)) {
                    echo json_encode(['status' => 'false', 'title' => 'Demo Restriction', 'message' => 'This feature is disabled in the demo version.', 'csrf_token' => $new_csrf_token]);
                } else {
                    $auth_code = $request->post('auth-code', '');

                    if ($auth_code === '') {
                        echo json_encode(['status' => 'false', 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                    } else {
                        $ga = new \OwnPay\Security\Authenticator();

                        // Use mapped column names from adapter.php
                        $check = $ga->verifyCode($global_user_response['response'][0]['2fa_secret'], $auth_code, 2);

                        if ($check) {
                            // Toggle: current mapped value is 'enable'/'disable' (from adapter mapping)
                            if ($global_user_response['response'][0]['2fa_status'] === 'enable') {
                                $fa_status = 'disabled';
                            } else {
                                $fa_status = 'enabled';
                            }

                            $columns = ['two_fa_status', 'updated_date'];
                            $values = [$fa_status, getCurrentDatetime('Y-m-d H:i:s')];
                            $condition = 'id = :id';
                            $whereParams = [':id' => $global_user_response['response'][0]['id']];

                            CrudService::update($db_prefix . 'merchant_users', $columns, $values, $condition, $whereParams);

                            if ($fa_status === 'disabled') {
                                echo json_encode(['status' => 'true', 'title' => 'Two-Factor Authentication Disabled', 'message' => 'Two-factor authentication has been successfully disabled for your account.', 'csrf_token' => $new_csrf_token]);
                            } else {
                                echo json_encode(['status' => 'true', 'title' => 'Two-Factor Authentication Enabled', 'message' => 'Two-factor authentication has been successfully enabled for your account.', 'csrf_token' => $new_csrf_token]);
                            }
                        } else {
                            echo json_encode(['status' => 'false', 'title' => 'Verification Failed', 'message' => 'The code you entered is incorrect. Please try again.', 'csrf_token' => $new_csrf_token]);
                            exit();
                        }
                    }
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        elseif ($action === 'activities-list') {
            if ($global_user_login == true) {
                $search_input = $request->post('search_input', '');
                $show_limit = $request->post('show_limit', 5);

                /* Filters */
                $filter_status = $request->post('filter_status', '');
                $filter_start = $request->post('filter_start', '');
                $filter_end = $request->post('filter_end', '');

                $where = [];
                $params_act = [':user_id' => $global_user_response['response'][0]['id']];

                if ($filter_start !== '') {
                    $where[] = "created_date >= :filter_start";
                    $params_act[':filter_start'] = "{$filter_start} 00:00:00";
                }

                if ($filter_end !== '') {
                    $where[] = "created_date <= :filter_end";
                    $params_act[':filter_end'] = "{$filter_end} 23:59:59";
                }

                if ($filter_status !== '') {
                    $where[] = "status = :filter_status";
                    $params_act[':filter_status'] = $filter_status;
                }

                $where_sql = $where ? implode(' AND ', $where) . ' AND ' : '';
                /* Filters */

                $pag = \OwnPay\Service\System\PaginationService::resolve($request->post('page', 1), $request->post('show_limit'));
                $page = $pag['page'];
                $show_limit_val = $pag['perPage'];
                $offset = $pag['offset'];

                $sql_query = '';

                if ($search_input !== '') {
                    $sql_query .= " AND ( browser LIKE :search OR device LIKE :search OR ip LIKE :search)";
                    $params_act[':search'] = "%$search_input%";
                }

                $sql_limit = '';
                if ($pag['isAll']) {

                } else {
                    $sql_limit = ' LIMIT ' . (int) $offset . ', ' . (int) $show_limit_val;
                }

                $response_result = CrudService::select($db_prefix . 'sessions', 'WHERE ' . $where_sql . ' user_id = :user_id ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_act);
                if ($response_result['status'] == true) {
                    $response = [];

                    foreach ($response_result['response'] as $row) {
                        $isequal = '';
                        if ($row['cookie'] == $op_admin) {
                            $isequal = 'matched';
                        }

                        $response[] = [
                            "id" => $row['id'],
                            "browser" => $row['browser'],
                            "device" => $row['device'],
                            "ip" => $row['ip'],
                            "status" => $row['status'],
                            "isequal" => $isequal,
                            "created_date" => convertUTCtoUserTZ($row['created_date'], ($global_response_brand['response'][0]['timezone'] === null || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                            "updated_date" => convertUTCtoUserTZ($row['updated_date'], ($global_response_brand['response'][0]['timezone'] === null || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                        ];
                    }

                    $count_params = $params_act;
                    unset($count_params[':search']); // remove search for count; re-add if needed
                    if ($search_input !== '') {
                        $count_params[':search'] = "%$search_input%";
                    }
                    $count_data = CrudService::select($db_prefix . 'sessions', 'WHERE ' . $where_sql . ' user_id = :user_id ' . $sql_query, '* FROM', $count_params);

                    $total_records = count($count_data['response'] ?? []);
                    $pagHtml = \OwnPay\Service\System\PaginationService::render($page, $total_records, $show_limit_val, $offset);
                    $pagination = $pagHtml['pagination'];
                    $datatableInfo = $pagHtml['datatableInfo'];

                    echo json_encode(['status' => "true", 'response' => $response, 'datatableInfo' => $datatableInfo, 'pagination' => $pagination, 'csrf_token' => $new_csrf_token]);
                } else {
                    echo json_encode(['status' => "false", 'title' => 'Nothing Here Yet', 'message' => 'No data is available at the moment.', 'csrf_token' => $new_csrf_token]);
                    exit();
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        if (in_array($action, ["staff-management-list", "staff-bulk-action", "staff-delete", "staff-create", "staff-update", "staff-permissions", "staff-permission-bulk-action", "staff-permission-delete", "staff-brand-add", "staff-update-permission"])) {
            \OwnPay\Controller\Admin\StaffController::handle($action);
            exit;
        }

        if (in_array($action, ["create-new-brand", "all-brand-list", "brand-bulk-action", "brand-delete", "edit-brand"])) {
            \OwnPay\Controller\Admin\BrandController::handle($action, $ctx);
            exit;
        }

        if (in_array($action, ["all-domain-list", "domains-info-byID", "create-domains", "domains-edit", "domains-delete", "domain-bulk-action"])) {
            \OwnPay\Controller\Admin\DomainController::handle($action, $ctx);
            exit;
        }

    }
}
