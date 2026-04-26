<?php

declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Http\RequestContext;
use OwnPay\Service\AuthSessionService;
use OwnPay\Service\CrudService;
use OwnPay\Service\InputSanitizer;

/**
 * Extracts session initialization and cookie-based authentication
 * from the legacy adapter.php monolith.
 *
 * Handles:
 * - PHP session start
 * - CSRF token generation
 * - op_admin cookie validation (main login)
 * - op_2fa cookie validation (2FA stage)
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

        if (!file_exists(__DIR__ . '/../../op-config.php')) {
            return $this->buildContext($dbPrefix, $user, $brand, $permissions, $csrfToken, $isLoggedIn, $role);
        }

        // --- Main login path: op_admin cookie ---
        if (AuthSessionService::getCookie('op_admin') !== null) {
            $op_admin = InputSanitizer::html(AuthSessionService::getCookie('op_admin'));

            $this->cookieResponse = CrudService::select(
                $dbPrefix . 'sessions',
                "WHERE cookie= :cookie AND status= 'active'",
                '* FROM',
                [':cookie' => $op_admin],
            );

            if (($this->cookieResponse['status'] ?? false) == true) {
                $userId = $this->cookieResponse['response'][0]['user_id'];

                $this->userResponse = CrudService::select(
                    $dbPrefix . 'merchant_users',
                    'WHERE id= :id',
                    '* FROM',
                    [':id' => $userId],
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

                    $this->brandResponse = CrudService::select(
                        $dbPrefix . 'merchants',
                        'WHERE id = :id',
                        '* FROM',
                        [':id' => $merchantId],
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

                        AuthSessionService::setCookie('op_brand', (string) $merchantId);

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
        // --- 2FA stage path: op_2fa cookie ---
        elseif (AuthSessionService::getCookie('op_2fa') !== null) {
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
        $params = [':cookie' => AuthSessionService::getCookie('op_2fa'), ':status' => 'active'];
        $this->cookieResponse = CrudService::select(
            $dbPrefix . 'sessions',
            'WHERE cookie= :cookie AND status= :status',
            '* FROM',
            $params,
        );

        if (($this->cookieResponse['status'] ?? false) != true) {
            return;
        }

        $params = [
            ':user_id' => $this->cookieResponse['response'][0]['user_id'],
            ':two_fa_status' => 'enabled',
        ];
        $this->userResponse = CrudService::select(
            $dbPrefix . 'merchant_users',
            'WHERE id= :user_id AND two_fa_status= :two_fa_status',
            '* FROM',
            $params,
        );

        if (($this->userResponse['status'] ?? false) != true
            || ($this->userResponse['response'][0]['status'] ?? '') !== 'active'
        ) {
            return;
        }

        if (AuthSessionService::getCookie('op_brand') === null) {
            return;
        }

        $apBrand = InputSanitizer::html(AuthSessionService::getCookie('op_brand'));
        $params = [
            ':a_id' => $this->userResponse['response'][0]['a_id'] ?? $this->userResponse['response'][0]['public_id'] ?? '',
            ':status' => 'active',
            ':brand_id' => $apBrand,
        ];
        $this->permissionResponse = CrudService::select(
            $dbPrefix . 'permission',
            'WHERE a_id = :a_id AND status = :status AND brand_id = :brand_id',
            '* FROM',
            $params,
        );

        if (($this->permissionResponse['status'] ?? false) != true) {
            // Fallback: try any active permission
            $params = [
                ':a_id' => $this->userResponse['response'][0]['a_id'] ?? $this->userResponse['response'][0]['public_id'] ?? '',
                ':status' => 'active',
            ];
            $this->permissionResponse = CrudService::select(
                $dbPrefix . 'permission',
                'WHERE a_id = :a_id AND status = :status LIMIT 1',
                '* FROM',
                $params,
            );

            if (($this->permissionResponse['status'] ?? false) != true) {
                return;
            }
        }

        $params = [':brand_id' => $this->permissionResponse['response'][0]['brand_id']];
        $this->brandResponse = CrudService::select(
            $dbPrefix . 'brands',
            'WHERE brand_id = :brand_id',
            '* FROM',
            $params,
        );

        if (($this->brandResponse['status'] ?? false) == true) {
            AuthSessionService::setCookie('op_brand', $this->permissionResponse['response'][0]['brand_id']);
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
        $currencyData = CrudService::select(
            $dbPrefix . 'currency',
            'WHERE brand_id = :brand_id AND code = :code',
            '* FROM',
            $params,
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
            siteUrl: $GLOBALS['site_url'] ?? '',
            pathAdmin: $GLOBALS['path_admin'] ?? '',
            pathPayment: $GLOBALS['path_payment'] ?? '',
            pathInvoice: $GLOBALS['path_invoice'] ?? '',
            pathPaymentLink: $GLOBALS['path_payment_link'] ?? '',
            currencyCode: $this->currencyCode,
            currencySymbol: $this->currencySymbol,
            currencyRate: $this->currencyRate,
            demoMode: !empty($GLOBALS['op_demo_mode']),
            userResponse: $this->userResponse,
            brandResponse: $this->brandResponse,
            permissionResponse: $this->permissionResponse,
            cookieResponse: $this->cookieResponse,
        );
    }
}
