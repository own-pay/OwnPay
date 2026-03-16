<?php

declare(strict_types=1);

namespace AnirbanPay\Middleware;

use AnirbanPay\Http\RequestContext;

/**
 * Extracts session initialization and cookie-based authentication
 * from the legacy adapter.php monolith.
 *
 * Handles:
 * - PHP session start
 * - CSRF token generation
 * - ap_admin cookie validation (main login)
 * - ap_2fa cookie validation (2FA stage)
 * - User, brand, permission, currency loading
 */
final class SessionMiddleware
{
    /**
     * Raw auth state populated during handle().
     * Exposed so adapter.php can export to legacy globals during transition.
     */
    public array $cookieResponse = [];
    public array $userResponse = [];
    public array $brandResponse = [];
    public array $permissionResponse = [];
    public bool $is2fa = false;
    public string $currencyCode = '';
    public string $currencySymbol = '';
    public float $currencyRate = 1.0;

    public function handle(string $dbPrefix): RequestContext
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // CSRF token
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $csrfToken = $_SESSION['csrf_token'];

        $user = [];
        $brand = [];
        $permissions = [];
        $isLoggedIn = false;
        $role = '';

        if (!file_exists(__DIR__ . '/../../ap-config.php')) {
            return $this->buildContext($dbPrefix, $user, $brand, $permissions, $csrfToken, $isLoggedIn, $role);
        }

        // --- Main login path: ap_admin cookie ---
        if (getCookie('ap_admin') !== null) {
            $ap_admin = sanitize_html(getCookie('ap_admin'));

            $this->cookieResponse = json_decode(
                getData($dbPrefix . 'sessions', 'WHERE cookie= :cookie', '* FROM', [':cookie' => $ap_admin]),
                true
            );

            if (($this->cookieResponse['status'] ?? false) == true) {
                $userId = $this->cookieResponse['response'][0]['user_id'];

                $this->userResponse = json_decode(
                    getData($dbPrefix . 'merchant_users', 'WHERE id= :id', '* FROM', [':id' => $userId]),
                    true
                );

                if (($this->userResponse['status'] ?? false) == true
                    && ($this->userResponse['response'][0]['status'] ?? '') === 'active'
                ) {
                    $u = &$this->userResponse['response'][0];

                    // Map new schema fields to legacy variable names
                    $u['a_id'] = $u['public_id'];
                    $u['password'] = $u['password_hash'];
                    $u['2fa_status'] = ($u['two_fa_status'] === 'enabled') ? 'enable' : 'disable';
                    $u['2fa_secret'] = $u['two_fa_secret'] ?? null;

                    $merchantId = $u['merchant_id'];

                    $this->brandResponse = json_decode(
                        getData($dbPrefix . 'merchants', 'WHERE id = :id', '* FROM', [':id' => $merchantId]),
                        true
                    );

                    if (($this->brandResponse['status'] ?? false) == true) {
                        $this->brandResponse['response'][0]['brand_id'] = $merchantId;
                        $this->brandResponse['response'][0]['identify_name'] = $this->brandResponse['response'][0]['business_name'];

                        $u['role'] = 'admin';

                        // Build full-access permissions for admin
                        $dummyPermission = json_encode([
                            'dashboard' => ['read'],
                            'gateways' => ['read', 'create', 'edit', 'delete'],
                            'transaction' => ['read', 'create', 'edit', 'delete'],
                            'reports' => ['read'],
                            'customers' => ['read', 'create', 'edit', 'delete'],
                            'invoice' => ['read', 'create', 'edit', 'delete'],
                            'payment_link' => ['read', 'create', 'edit', 'delete'],
                            'brand_settings' => ['read', 'create', 'edit', 'delete'],
                            'sms_data' => ['read'],
                            'device' => ['read', 'create', 'edit', 'delete'],
                            'addons' => ['read'],
                            'domains' => ['read', 'create', 'edit', 'delete'],
                            'brands' => ['read', 'create', 'edit', 'delete'],
                            'staff_management' => ['read', 'create', 'edit', 'delete'],
                            'system_settings' => ['read', 'create', 'edit', 'delete'],
                        ]);

                        $this->permissionResponse = [
                            'status' => true,
                            'response' => [
                                ['brand_id' => $merchantId, 'permission' => $dummyPermission],
                            ],
                        ];

                        setsCookie('ap_brand', (string) $merchantId);

                        $isLoggedIn = true;
                        $user = $u;
                        $brand = $this->brandResponse['response'][0];
                        $permissions = json_decode($dummyPermission, true);
                        $role = 'admin';

                        // Load currency
                        $this->loadCurrency($dbPrefix, $brand);
                    }
                }
            }
        }
        // --- 2FA stage path: ap_2fa cookie ---
        elseif (getCookie('ap_2fa') !== null) {
            $this->handle2faStage($dbPrefix);
            if ($this->is2fa && !empty($this->userResponse['response'][0])) {
                $user = $this->userResponse['response'][0];
                $role = $user['role'] ?? '';
                if (!empty($this->brandResponse['response'][0])) {
                    $brand = $this->brandResponse['response'][0];
                }
                if (!empty($this->permissionResponse['response'][0]['permission'])) {
                    $permissions = json_decode($this->permissionResponse['response'][0]['permission'], true) ?: [];
                }
            }
        }

        return $this->buildContext($dbPrefix, $user, $brand, $permissions, $csrfToken, $isLoggedIn, $role);
    }

    private function handle2faStage(string $dbPrefix): void
    {
        $params = [':cookie' => getCookie('ap_2fa'), ':status' => 'active'];
        $this->cookieResponse = json_decode(
            getData($dbPrefix . 'sessions', 'WHERE cookie= :cookie AND status= :status', '* FROM', $params),
            true
        );

        if (($this->cookieResponse['status'] ?? false) != true) {
            return;
        }

        $params = [
            ':user_id' => $this->cookieResponse['response'][0]['user_id'],
            ':two_fa_status' => 'enabled',
        ];
        $this->userResponse = json_decode(
            getData($dbPrefix . 'merchant_users', 'WHERE id= :user_id AND two_fa_status= :two_fa_status', '* FROM', $params),
            true
        );

        if (($this->userResponse['status'] ?? false) != true
            || ($this->userResponse['response'][0]['status'] ?? '') !== 'active'
        ) {
            return;
        }

        if (getCookie('ap_brand') === null) {
            return;
        }

        $apBrand = sanitize_html(getCookie('ap_brand'));
        $params = [
            ':a_id' => $this->userResponse['response'][0]['a_id'] ?? $this->userResponse['response'][0]['public_id'] ?? '',
            ':status' => 'active',
            ':brand_id' => $apBrand,
        ];
        $this->permissionResponse = json_decode(
            getData($dbPrefix . 'permission', 'WHERE a_id = :a_id AND status = :status AND brand_id = :brand_id', '* FROM', $params),
            true
        );

        if (($this->permissionResponse['status'] ?? false) != true) {
            // Fallback: try any active permission
            $params = [
                ':a_id' => $this->userResponse['response'][0]['a_id'] ?? $this->userResponse['response'][0]['public_id'] ?? '',
                ':status' => 'active',
            ];
            $this->permissionResponse = json_decode(
                getData($dbPrefix . 'permission', 'WHERE a_id = :a_id AND status = :status LIMIT 1', '* FROM', $params),
                true
            );

            if (($this->permissionResponse['status'] ?? false) != true) {
                return;
            }
        }

        $params = [':brand_id' => $this->permissionResponse['response'][0]['brand_id']];
        $this->brandResponse = json_decode(
            getData($dbPrefix . 'brands', 'WHERE brand_id = :brand_id', '* FROM', $params),
            true
        );

        if (($this->brandResponse['status'] ?? false) == true) {
            setsCookie('ap_brand', $this->permissionResponse['response'][0]['brand_id']);
            $this->is2fa = true;
        }
    }

    private function loadCurrency(string $dbPrefix, array $brand): void
    {
        $this->currencyCode = $brand['currency_code'] ?? '';
        $this->currencySymbol = $brand['currency_code'] ?? '';
        $this->currencyRate = 1.0;

        if (empty($this->currencyCode)) {
            return;
        }

        $params = [':brand_id' => $brand['brand_id'], ':code' => $this->currencyCode];
        $currencyData = json_decode(
            getData($dbPrefix . 'currency', 'WHERE brand_id = :brand_id AND code = :code', '* FROM', $params),
            true
        );

        if (($currencyData['status'] ?? false) == true) {
            $this->currencySymbol = $currencyData['response'][0]['symbol'] ?? $this->currencyCode;
        }
    }

    private function buildContext(
        string $dbPrefix,
        array $user,
        array $brand,
        array $permissions,
        string $csrfToken,
        bool $isLoggedIn,
        string $role,
    ): RequestContext {
        return new RequestContext(
            dbPrefix: $dbPrefix,
            user: $user,
            brand: $brand,
            permissions: $permissions,
            csrfToken: $csrfToken,
            isLoggedIn: $isLoggedIn,
            role: $role,
        );
    }
}
