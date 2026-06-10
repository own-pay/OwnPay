<?php
declare(strict_types=1);

/**
 * OwnPay Landing Page DonateController
 * File: app/Controller/DonateController.php
 */

require_once ROOT_PATH . '/app/Database.php';
require_once ROOT_PATH . '/app/Controller/Controller.php';

class DonateController extends Controller
{
    private function getEpsConfig(): array
    {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT `setting_key`, `setting_value` FROM `op_org_settings` WHERE `setting_key` LIKE 'eps_%'");
        $rows = $stmt->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        return [
            'merchant_id' => $settings['eps_merchant_id'] ?? getenv('DONATION_EPS_MERCHANT_ID') ?: 'f425637e-6f9a-4884-9c3f-9c1fb23cced6',
            'store_id'    => $settings['eps_store_id'] ?? getenv('DONATION_EPS_STORE_ID') ?: 'f61953d3-dd8c-4cea-8289-541addee29f8',
            'hash_key'    => $settings['eps_hash_key'] ?? getenv('DONATION_EPS_HASH_KEY') ?: 'FMUNISHOY2lWZXDH066builderpay',
            'username'    => $settings['eps_username'] ?? getenv('DONATION_EPS_USERNAME') ?: 'contact@builderhall.com',
            'password'    => $settings['eps_password'] ?? getenv('DONATION_EPS_PASSWORD') ?: '@Fatta32882@',
            'store_mode'  => $settings['eps_store_mode'] ?? getenv('DONATION_EPS_MODE') ?: 'live',
        ];
    }

    /**
     * Donation page.
     */
    public function index(): void
    {
        $this->startSession();
        $error = $_SESSION['donation_error'] ?? null;
        unset($_SESSION['donation_error']);

        $db = Database::getConnection();

        // Load stats
        $stmt = $db->query("SELECT COUNT(*) as count, SUM(amount) as total FROM `op_org_donations` WHERE `public_display` = 1");
        $stats = $stmt->fetch();

        $totalMonetarySponsors = (int)($stats['count'] ?? 0);
        $totalAmountBDT = (float)($stats['total'] ?? 0.0);

        // Fetch custom settings
        $stmt = $db->query("SELECT `setting_key`, `setting_value` FROM `op_org_settings`");
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        // Fetch regular active sponsors for marquee/list
        $stmt = $db->query("SELECT * FROM `op_org_sponsors` WHERE `active` = 1 ORDER BY `display_order` ASC");
        $sponsors = $stmt->fetchAll();

        $this->render('donate', [
            'error' => $error,
            'totalMonetarySponsors' => $totalMonetarySponsors,
            'totalAmountBDT' => $totalAmountBDT,
            'settings' => $settings,
            'sponsors' => $sponsors,
            'title' => 'Donate & Sponsor | OwnPay Supporter Center',
            'description' => 'Support the free, self-hosted, open-source payment ecosystem.'
        ]);
    }

    /**
     * Initiate payment callback.
     */
    public function initiate(): void
    {
        $this->startSession();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $_SESSION['donation_error'] = 'Invalid request method.';
            $this->redirect('/donate');
        }

        try {
            $originalAmount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
            $currency = trim((string)($_POST['currency'] ?? 'BDT'));
            if (!in_array($currency, ['BDT', 'USD', 'GBP', 'EUR'])) {
                $currency = 'BDT';
            }

            // Simple exchange rate converter to BDT
            $rates = ['BDT' => 1.0, 'USD' => 123.0, 'GBP' => 148.0, 'EUR' => 127.0];
            $rate = $rates[$currency];
            $amountBDT = round($originalAmount * $rate, 2);

            if ($originalAmount === false || $amountBDT < 10.00) {
                throw new InvalidArgumentException('Please enter a valid donation amount (minimum BDT 10 equivalent).');
            }

            $anonymous = filter_input(INPUT_POST, 'anonymous', FILTER_VALIDATE_BOOLEAN);
            $name = 'Anonymous';
            $phone = '';
            $email = trim((string)($_POST['email'] ?? ''));

            if (!$anonymous) {
                $name = trim((string)($_POST['name'] ?? ''));
                $phone = trim((string)($_POST['phone'] ?? ''));

                if (empty($name)) {
                    throw new InvalidArgumentException('Please enter your name, or choose to donate anonymously.');
                }
                if (empty($email)) {
                    throw new InvalidArgumentException('Please enter your email.');
                }
            }

            if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Please enter a valid email address.');
            }

            $apiConfig = $this->getEpsConfig();
            $baseUrl = ($apiConfig['store_mode'] === 'live') ? 'https://pgapi.eps.com.bd' : 'https://sandboxpgapi.eps.com.bd';

            $merchantTransactionId = 'OPDON' . date('ymd') . rand(1000, 9999);
            $customerOrderId = 'OPORD' . rand(10000, 99999);

            $token = $this->getEpsToken($baseUrl, $apiConfig);

            $hmac = hash_hmac('sha512', $merchantTransactionId, $apiConfig['hash_key'], true);
            $xHash = base64_encode($hmac);

            $callbackUrl = APP_URL . '/donate/callback';

            $payload = [
                'storeId'               => $apiConfig['store_id'],
                'CustomerOrderId'       => $customerOrderId,
                'merchantTransactionId' => $merchantTransactionId,
                'transactionTypeId'     => 1,
                'financialEntityId'     => 0,
                'transitionStatusId'    => 0,
                'totalAmount'           => $amountBDT,
                'ipAddress'             => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                'version'               => '1',
                'successUrl'            => $callbackUrl . '?status=success',
                'failUrl'               => $callbackUrl . '?status=fail',
                'cancelUrl'             => $callbackUrl . '?status=cancel',
                'customerName'          => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
                'customerEmail'         => !empty($email) ? $email : 'ping@ownpay.org',
                'CustomerAddress'       => 'Bangladesh',
                'CustomerCity'          => 'Dhaka',
                'CustomerPostcode'      => '1000',
                'CustomerCountry'       => 'BD',
                'CustomerPhone'         => htmlspecialchars($phone ?: '01700000000', ENT_QUOTES, 'UTF-8'),
                'ShippingMethod'        => 'NO',
                'NoOfItem'              => '1',
                'ProductName'           => 'OwnPay Community Donation',
                'ProductProfile'        => 'general',
                'ProductCategory'       => 'Donation',
                'ProductList'           => [
                    [
                        'ProductName'     => 'Community Donation',
                        'NoOfItem'        => '1',
                        'ProductProfile'  => 'general',
                        'ProductCategory' => 'Donation',
                        'ProductPrice'    => (string)$amountBDT
                    ]
                ]
            ];

            $ch = curl_init($baseUrl . '/v1/EPSEngine/InitializeEPS');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'x-hash: ' . $xHash,
                    'Authorization: Bearer ' . $token
                ],
                CURLOPT_POSTFIELDS => json_encode($payload),
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !$response) {
                throw new RuntimeException('EPS Gateway connection failure. Please try again.');
            }

            $data = json_decode((string) $response, true);
            if (!is_array($data) || empty($data['RedirectURL']) || !is_string($data['RedirectURL'])) {
                throw new RuntimeException('EPS initiation failed: ' . ($data['ErrorMessage'] ?? 'Unknown Error'));
            }

            $_SESSION['pending_donation'] = [
                'merchant_transaction_id' => $merchantTransactionId,
                'amount'                  => $amountBDT,
                'original_amount'         => $originalAmount,
                'original_currency'       => $currency,
                'name'                    => $name,
                'email'                   => $email,
                'phone'                   => $phone,
                'anonymous'               => $anonymous
            ];

            $this->redirect($data['RedirectURL']);

        } catch (Throwable $e) {
            $_SESSION['donation_error'] = $e->getMessage();
            $this->redirect('/donate');
        }
    }

    /**
     * EPS Gateway Redirect Callback.
     */
    public function callback(): void
    {
        $this->startSession();

        try {
            $statusParam = $_GET['status'] ?? '';
            $merchantTransactionId = $_GET['MerchantTransactionId'] ?? $_POST['MerchantTransactionId'] ?? '';

            if (empty($merchantTransactionId) && isset($_SESSION['pending_donation'])) {
                $merchantTransactionId = $_SESSION['pending_donation']['merchant_transaction_id'];
            }

            if ($statusParam !== 'success' || empty($merchantTransactionId)) {
                throw new RuntimeException('Payment was cancelled or failed.');
            }

            $apiConfig = $this->getEpsConfig();
            $baseUrl = ($apiConfig['store_mode'] === 'live') ? 'https://pgapi.eps.com.bd' : 'https://sandboxpgapi.eps.com.bd';

            $token = $this->getEpsToken($baseUrl, $apiConfig);

            $hmac = hash_hmac('sha512', (string)$merchantTransactionId, $apiConfig['hash_key'], true);
            $xHash = base64_encode($hmac);

            $url = $baseUrl . '/v1/EPSEngine/CheckMerchantTransactionStatus?merchantTransactionId=' . urlencode((string)$merchantTransactionId);

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => [
                    'x-hash: ' . $xHash,
                    'Authorization: Bearer ' . $token
                ]
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || !$response) {
                throw new RuntimeException('Failed to verify payment status with EPS.');
            }

            $data = json_decode((string) $response, true);
            $statusVal = isset($data['Status']) && is_scalar($data['Status']) ? (string) $data['Status'] : '';
            $paid = strtolower($statusVal) === 'success';

            if (!$paid) {
                throw new RuntimeException('Payment verification returned unsuccessful state: ' . ($data['ErrorMessage'] ?? 'Failed'));
            }

            $pending = $_SESSION['pending_donation'] ?? [];
            $uniqueId = 'OP-' . date('Ymd') . '-' . rand(1000, 9999);

            $db = Database::getConnection();

            // Resolve tier based on donation amount in BDT (exchange BDT equivalents)
            $amountBDT = $pending['amount'] ?? (float)($data['TotalAmount'] ?? 0.0);
            $tier = 'Community';
            if ($amountBDT >= 10000) {
                $tier = 'Gold';
            } elseif ($amountBDT >= 5000) {
                $tier = 'Silver';
            } elseif ($amountBDT >= 2500) {
                $tier = 'Bronze';
            }

            // Save to DB
            $stmt = $db->prepare("INSERT INTO `op_org_donations` (`donor_name`, `email`, `amount`, `currency`, `tier`, `message`, `created_at`, `public_display`, `unique_id`) 
                                   VALUES (?, ?, ?, ?, ?, NULL, NOW(), 1, ?)");
            $stmt->execute([
                $pending['name'] ?? 'Anonymous Support',
                $pending['email'] ?? '',
                $amountBDT,
                $pending['original_currency'] ?? 'BDT',
                $tier,
                $uniqueId
            ]);

            $_SESSION['completed_donation'] = [
                'amount'            => $amountBDT,
                'original_amount'   => $pending['original_amount'] ?? $amountBDT,
                'original_currency' => $pending['original_currency'] ?? 'BDT',
                'name'              => $pending['name'] ?? 'Anonymous Support',
                'email'             => $pending['email'] ?? '',
                'phone'             => $pending['phone'] ?? '',
                'anonymous'         => $pending['anonymous'] ?? true,
                'unique_id'         => $uniqueId,
                'tier'              => $tier
            ];

            unset($_SESSION['pending_donation']);

            $this->redirect('/donate?view=success_message');

        } catch (Throwable $e) {
            $_SESSION['donation_error'] = $e->getMessage();
            $this->redirect('/donate');
        }
    }

    /**
     * Submit Donation Message on Success.
     */
    public function submitMessage(): void
    {
        $this->startSession();
        $completed = $_SESSION['completed_donation'] ?? null;
        if (!$completed) {
            $this->redirect('/donate');
        }

        $message = trim((string)($_POST['message'] ?? ''));
        $customName = trim((string)($_POST['custom_name'] ?? ''));

        $displayName = !empty($customName) ? $customName : $completed['name'];

        $db = Database::getConnection();

        // Update donation message in DB
        $stmt = $db->prepare("UPDATE `op_org_donations` SET `donor_name` = ?, `message` = ? WHERE `unique_id` = ?");
        $stmt->execute([
            $displayName,
            !empty($message) ? $message : null,
            $completed['unique_id']
        ]);

        unset($_SESSION['completed_donation']);

        // Check if AJAX
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            $this->json(['success' => true]);
        }

        $this->redirect('/donors');
    }

    /**
     * Public Donors list.
     */
    public function donors(): void
    {
        $page = (int)($_GET['page'] ?? 1);
        if ($page < 1) $page = 1;
        $perPage = 20;
        $offset = ($page - 1) * $perPage;

        $db = Database::getConnection();

        // Total count
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM `op_org_donations` WHERE `public_display` = 1");
        $total = (int)$stmt->fetch()['cnt'];
        $totalPages = (int)ceil($total / $perPage);

        // Fetch paginated list
        $stmt = $db->prepare("SELECT * FROM `op_org_donations` WHERE `public_display` = 1 ORDER BY `created_at` DESC LIMIT ? OFFSET ?");
        $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->execute();
        $donors = $stmt->fetchAll();

        // Fetch settings for layout
        $stmt = $db->query("SELECT `setting_key`, `setting_value` FROM `op_org_settings`");
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        $this->render('donors', [
            'donors' => $donors,
            'page' => $page,
            'totalPages' => $totalPages,
            'settings' => $settings,
            'title' => 'Donor Hall of Fame | OwnPay',
            'description' => 'Sovereign financial backers of the OwnPay open-source payment ecosystem.'
        ]);
    }

    private function getEpsToken(string $baseUrl, array $credentials): string
    {
        $username = $credentials['username'];
        $password = $credentials['password'];
        $hashKey = $credentials['hash_key'];

        $hmac = hash_hmac('sha512', $username, $hashKey, true);
        $xHash = base64_encode($hmac);

        $ch = curl_init($baseUrl . '/v1/Auth/GetToken');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-hash: ' . $xHash
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'userName' => $username,
                'password' => $password
            ]),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new RuntimeException('EPS Token generation error. Code: ' . $httpCode);
        }

        $data = json_decode((string) $response, true);
        if (!is_array($data) || empty($data['token']) || !is_string($data['token'])) {
            throw new RuntimeException('EPS Token acquisition failed.');
        }

        return $data['token'];
    }
}
