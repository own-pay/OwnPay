<?php
declare(strict_types=1);

namespace AnirbanPay\Controller;

class AuthController
{
    /**
     * Process a successful login: set cookies, create session, generate 2FA secret if needed.
     */
    private static function processSuccessfulLogin(array $user, string $password, string $hashColumn): void
    {
        global $db_prefix, $path_admin, $new_csrf_token;

        // Transparently rehash to Argon2id if needed
        if (password_needs_rehash($user[$hashColumn], PASSWORD_ARGON2ID)) {
            $rehashed = password_hash($password, PASSWORD_ARGON2ID);
            updateData($db_prefix . 'merchant_users', [$hashColumn], [$rehashed], 'id = :id', [':id' => $user['id']]);
        }

        if ($user['status'] !== 'active') {
            echo json_encode(['status' => 'false', 'title' => 'Login Failed', 'message' => 'Your account has been suspended. Please contact with your admin.', 'csrf_token' => $new_csrf_token]);
            return;
        }

        session_regenerate_id(true);

        $cookie = bin2hex(random_bytes(16));
        $userInfo = getUserDeviceInfo();

        if ($user['two_fa_status'] === 'enabled') {
            setsCookie('ap_2fa', $cookie);
            $target = '2fa';
        } else {
            setsCookie('ap_admin', $cookie);
            $target = $path_admin . '/dashboard';
        }

        // Generate 2FA secret if not set
        if ($user['two_fa_secret'] === '--' || empty($user['two_fa_secret'])) {
            $ga = new \AnirbanPay\Security\Authenticator();
            $secret = $ga->createSecret();
            updateData($db_prefix . 'merchant_users', ['two_fa_secret'], [$secret], 'id = :id', [':id' => $user['id']]);
        }

        // Create session record - mapping to V2 `sessions` table schema
        $columns = ['cookie', 'user_id', 'merchant_id', 'role_id', 'created_at'];
        $values = [
            $cookie,
            $user['id'],
            $user['merchant_id'],
            $user['role_id'],
            getCurrentDatetime('Y-m-d H:i:s')
        ];
        insertData($db_prefix . 'sessions', $columns, $values);

        echo json_encode(['status' => 'true', 'target' => $target, 'session_token' => $cookie, 'csrf_token' => $new_csrf_token]);
    }

    public static function handle(string $action): void
    {
        global $db_prefix, $path_admin, $new_csrf_token, $global_user_login, $global_user_response, $ap_admin, $global_two_fector_validate, $ap_demo_mode, $global_response_brand, $global_cookie_response, $global_user_2fa;

        $request = \AnirbanPay\Http\Request::createFromGlobals();

        if ($action === 'login') {
            // Rate limit: 5 login attempts per minute per IP
            $rateLimiter = new \AnirbanPay\Middleware\RateLimiterMiddleware();
            $ipKey = 'login_ip:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            $rateResult = $rateLimiter->check(crc32($ipKey), '', 'POST', 5);
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

                $response = json_decode(getData($db_prefix . 'merchant_users', 'WHERE ' . $sql_email_username, '* FROM', $params), true);

                if ($response['status'] == true) {
                    $user = $response['response'][0];
                    if (password_verify($password, $user['password_hash'])) {
                        self::processSuccessfulLogin($user, $password, 'password_hash');
                    } elseif (password_verify($password, $user['temp_password'])) {
                        self::processSuccessfulLogin($user, $password, 'temp_password');
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

                    $response = json_decode(getData($db_prefix . 'merchant_users', 'WHERE id = :id', '* FROM', $params), true);

                    if ($response['status'] == true) {
                        $ga = new \AnirbanPay\Security\Authenticator();

                        $check = $ga->verifyCode($response['response'][0]['two_fa_secret'], $code_one . $code_two . $code_three . $code_four . $code_five . $code_six, 2);

                        if ($check) {
                            logoutCookie();

                            setsCookie('ap_brand', $global_response_brand['response'][0]['brand_id']);
                            setsCookie('ap_admin', $global_cookie_response['response'][0]['cookie']);

                            echo json_encode(['status' => "true", 'target' => $path_admin . '/dashboard', 'session_token' => $global_cookie_response['response'][0]['cookie'], 'csrf_token' => $new_csrf_token]);
                        } else {
                            echo json_encode(['status' => "false", 'title' => 'Verification Failed', 'message' => 'The code you entered is incorrect. Please try again.', 'csrf_token' => $new_csrf_token]);
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
                if (filter_var($email_address, FILTER_VALIDATE_EMAIL)) {
                    $params = [':email' => $email_address, ':status' => 'active'];

                    $response = json_decode(getData($db_prefix . 'merchant_users', 'WHERE email = :email AND status = :status', '* FROM', $params), true);

                    if ($response['status'] == true) {

                        // Note: The previous logic relied on a `reset_limit` column which does not exist in `merchant_users`.
                        // For now we will allow reset if we found the user.
                        $new_temp_password = generateStrongPassword(8);
                        $temp_password = password_hash($new_temp_password, PASSWORD_ARGON2ID);

                        $columns = ['temp_password'];
                        $values = [$temp_password];
                        $condition = "id = :id";
                        $whereParams = [':id' => $response['response'][0]['id']];

                        updateData($db_prefix . 'merchant_users', $columns, $values, $condition, $whereParams);

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

                    $response = json_decode(getData($db_prefix . 'permission', 'WHERE a_id = :a_id AND status = :status AND brand_id = :brand_id', '* FROM', $params), true);
                    if ($response['status'] == true) {
                        setsCookie('ap_brand', $brand_id);

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
                if (!empty($ap_demo_mode)) {
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
                                $response = json_decode(getData($db_prefix . 'merchant_users', 'WHERE username = :username', '* FROM', $params), true);
                                if ($response['status'] == true) {
                                    echo json_encode(['status' => 'false', 'title' => 'Duplicate Username', 'message' => 'Username already exists.', 'csrf_token' => $new_csrf_token]);
                                    exit();
                                }
                            }

                            // Check email uniqueness against merchant_users
                            if ($email_address !== $global_user_response['response'][0]['email']) {
                                $params = [':email' => $email_address];
                                $response = json_decode(getData($db_prefix . 'merchant_users', 'WHERE email = :email', '* FROM', $params), true);
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

                            updateData($db_prefix . 'merchant_users', $columns, $values, $condition, $whereParams);

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
                if (!empty($ap_demo_mode)) {
                    echo json_encode(['status' => 'false', 'title' => 'Demo Restriction', 'message' => 'This feature is disabled in the demo version.', 'csrf_token' => $new_csrf_token]);
                } else {
                    if ($global_two_fector_validate == false) {
                        echo json_encode(['status' => 'false', 'title' => 'Verification Failed', 'message' => 'The code/password you entered is incorrect. Please try again.', 'csrf_token' => $new_csrf_token]);
                        exit();
                    }

                    $columns = ['status', 'updated_date'];
                    $values = ['expired', getCurrentDatetime('Y-m-d H:i:s')];
                    $condition = 'user_id = :user_id AND cookie != :ap_admin';
                    $whereParams = [':user_id' => $global_user_response['response'][0]['id'], ':ap_admin' => $ap_admin];

                    updateData($db_prefix . 'sessions', $columns, $values, $condition, $whereParams);

                    echo json_encode(['status' => 'true', 'title' => 'Logged Out Successfully', 'message' => 'You have been logged out of all other browser sessions.', 'csrf_token' => $new_csrf_token]);
                }
            } else {
                echo json_encode(['status' => 'false', 'title' => 'Request Failed', 'message' => 'Invalid request', 'csrf_token' => $new_csrf_token]);
            }
        }

        elseif ($action === 'my-account-account-two-factor-authentication') {
            if ($global_user_login == true) {
                if (!empty($ap_demo_mode)) {
                    echo json_encode(['status' => 'false', 'title' => 'Demo Restriction', 'message' => 'This feature is disabled in the demo version.', 'csrf_token' => $new_csrf_token]);
                } else {
                    $auth_code = $request->post('auth-code', '');

                    if ($auth_code === '') {
                        echo json_encode(['status' => 'false', 'title' => 'Incomplete Information', 'message' => 'Please fill in all required fields before proceeding.', 'csrf_token' => $new_csrf_token]);
                    } else {
                        $ga = new \AnirbanPay\Security\Authenticator();

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

                            updateData($db_prefix . 'merchant_users', $columns, $values, $condition, $whereParams);

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

                $page = max(1, (int) $request->post('page', 1));
                $show_limit_val = ($request->post('show_limit') == '') ? 999999 : (int) $request->post('show_limit');
                $offset = ($page - 1) * $show_limit_val;

                $sql_query = '';

                if ($search_input !== '') {
                    $sql_query .= " AND ( browser LIKE :search OR device LIKE :search OR ip LIKE :search)";
                    $params_act[':search'] = "%$search_input%";
                }

                $sql_limit = '';
                if ($show_limit == 'all') {

                } else {
                    $sql_limit = ' LIMIT ' . (int) $offset . ', ' . (int) $show_limit;
                }

                $response_result = json_decode(getData($db_prefix . 'sessions', 'WHERE ' . $where_sql . ' user_id = :user_id ' . $sql_query . ' ORDER BY 1 DESC ' . $sql_limit, '* FROM', $params_act), true);
                if ($response_result['status'] == true) {
                    $response = [];

                    foreach ($response_result['response'] as $row) {
                        $isequal = '';
                        if ($row['cookie'] == $ap_admin) {
                            $isequal = 'matched';
                        }

                        $response[] = [
                            "id" => $row['id'],
                            "browser" => $row['browser'],
                            "device" => $row['device'],
                            "ip" => $row['ip'],
                            "status" => $row['status'],
                            "isequal" => $isequal,
                            "created_date" => convertUTCtoUserTZ($row['created_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A"),
                            "updated_date" => convertUTCtoUserTZ($row['updated_date'], ($global_response_brand['response'][0]['timezone'] === '--' || $global_response_brand['response'][0]['timezone'] === '') ? 'Asia/Dhaka' : $global_response_brand['response'][0]['timezone'], "M d, Y h:i A")
                        ];
                    }

                    $count_params = $params_act;
                    unset($count_params[':search']); // remove search for count; re-add if needed
                    if ($search_input !== '') {
                        $count_params[':search'] = "%$search_input%";
                    }
                    $count_data = json_decode(getData($db_prefix . 'sessions', 'WHERE ' . $where_sql . ' user_id = :user_id ' . $sql_query, '* FROM', $count_params), true);

                    $total_records = count($count_data['response'] ?? []);
                    $total_pages = ceil($total_records / $show_limit);

                    $pagination = '<ul class="pagination m-0 ms-auto">';

                    // Prev button
                    $pagination .= '<li class="page-item' . ($page <= 1 ? ' disabled' : '') . '">
                            <button class="page-link" ' . ($page > 1 ? 'data-page="' . ($page - 1) . '"' : '') . '>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-1">
                                    <path d="M15 6l-6 6l6 6"></path>
                                </svg>
                            </button>
                        </li>';

                    // Page numbers
                    for ($i = 1; $i <= $total_pages; $i++) {
                        $pagination .= '<li class="page-item' . ($i == $page ? ' active' : '') . '">
                                <button class="page-link" data-page="' . $i . '">' . $i . '</button>
                            </li>';
                    }

                    // Next button
                    $pagination .= '<li class="page-item' . ($page >= $total_pages ? ' disabled' : '') . '">
                            <button class="page-link" ' . ($page < $total_pages ? 'data-page="' . ($page + 1) . '"' : '') . '>
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="icon icon-1">
                                    <path d="M9 6l6 6l-6 6"></path>
                                </svg>
                            </button>
                        </li>';

                    $pagination .= '</ul>';

                    $start = ($offset + 1);
                    $end = min($offset + $show_limit, $total_records);

                    $datatableInfo = "Showing <strong>$start to $end</strong> of <strong>$total_records entries</strong>";

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
            \AnirbanPay\Controller\StaffController::handle($action);
            exit;
        }

        if (in_array($action, ["create-new-brand", "all-brand-list", "brand-bulk-action", "brand-delete", "edit-brand"])) {
            \AnirbanPay\Controller\BrandController::handle($action);
            exit;
        }

        if (in_array($action, ["all-domain-list", "domains-info-byID", "create-domains", "domains-edit", "domains-delete", "domain-bulk-action"])) {
            \AnirbanPay\Controller\DomainController::handle($action);
            exit;
        }

    }
}
