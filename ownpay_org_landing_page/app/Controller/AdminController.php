<?php
declare(strict_types=1);

/**
 * OwnPay Landing Page AdminController
 * File: app/Controller/AdminController.php
 */

require_once ROOT_PATH . '/app/Database.php';
require_once ROOT_PATH . '/app/Controller/Controller.php';

class AdminController extends Controller
{
    private const LOGIN_LIMIT = 5;
    private const LOGIN_LIMIT_WINDOW_SECONDS = 900; // 15 mins

    /**
     * Start session & check if authenticated.
     */
    private function checkAuth(): void
    {
        $this->startSession();
        if (empty($_SESSION['admin_user_id'])) {
            $this->redirect('/admin/login');
        }

        // Session inactivity timeout (2 hours)
        $now = time();
        if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity'] > 7200)) {
            $this->logoutSession();
            $_SESSION['login_error'] = 'Session expired due to inactivity.';
            $this->redirect('/admin/login');
        }
        $_SESSION['last_activity'] = $now;
    }

    private function logoutSession(): void
    {
        $db = Database::getConnection();
        $sessId = session_id();
        $stmt = $db->prepare("DELETE FROM `op_org_admin_sessions` WHERE `id` = ?");
        $stmt->execute([$sessId]);

        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }

    /**
     * Audit logger helper.
     */
    private function logAction(string $action, string $details = ''): void
    {
        $db = Database::getConnection();
        $userId = $_SESSION['admin_user_id'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $stmt = $db->prepare("INSERT INTO `op_org_audit_log` (`user_id`, `action`, `details`, `ip_address`, `created_at`) 
                               VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([$userId, $action, $details, $ip]);
    }

    /**
     * Rate limiter for Login attempts by IP.
     */
    private function isLoginRateLimited(PDO $db, string $ip): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::LOGIN_LIMIT_WINDOW_SECONDS);
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM `op_org_audit_log` 
                               WHERE `action` = 'login_failure' AND `ip_address` = ? AND `created_at` >= ?");
        $stmt->execute([$ip, $since]);
        $failures = (int) $stmt->fetch()['cnt'];

        return $failures >= self::LOGIN_LIMIT;
    }

    /**
     * Admin Login page.
     */
    public function login(): void
    {
        $this->startSession();
        if (!empty($_SESSION['admin_user_id'])) {
            $this->redirect('/admin/dashboard');
        }

        $error = $_SESSION['login_error'] ?? null;
        unset($_SESSION['login_error']);

        $db = Database::getConnection();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrf()) {
                $_SESSION['login_error'] = 'CSRF validation failed.';
                $this->redirect('/admin/login');
            }

            if ($this->isLoginRateLimited($db, $ip)) {
                $_SESSION['login_error'] = 'Too many login failures. Please try again in 15 minutes.';
                $this->redirect('/admin/login');
            }

            $username = trim((string)($_POST['username'] ?? ''));
            $password = trim((string)($_POST['password'] ?? ''));

            // Preemptively fetch user to prevent enumeration timing details
            $stmt = $db->prepare("SELECT * FROM `op_org_admin_users` WHERE `username` = ? LIMIT 1");
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, (string)$user['password_hash'])) {
                // Login Success
                $_SESSION['admin_user_id'] = $user['id'];
                $_SESSION['admin_username'] = $user['username'];
                $_SESSION['last_activity'] = time();

                // Save session in DB
                $sessId = session_id();
                $stmt = $db->prepare("INSERT INTO `op_org_admin_sessions` (`id`, `user_id`, `ip_address`, `user_agent`, `last_activity`) 
                                       VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE `last_activity` = VALUES(`last_activity`)");
                $stmt->execute([
                    $sessId,
                    $user['id'],
                    $ip,
                    $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                    time()
                ]);

                $this->logAction('login_success', 'User logged in successfully.');
                $this->redirect('/admin/dashboard');
            } else {
                // Login Failure
                // Log failed attempt for rate limiting
                $stmt = $db->prepare("INSERT INTO `op_org_audit_log` (`user_id`, `action`, `details`, `ip_address`, `created_at`) 
                                       VALUES (NULL, 'login_failure', ?, ?, NOW())");
                $stmt->execute([$username, $ip]);

                $_SESSION['login_error'] = 'Invalid username or password.';
                $this->redirect('/admin/login');
            }
        }

        $this->render('admin/login', [
            'error' => $error,
            'csrfToken' => $this->csrfToken(),
            'title' => 'Admin Login | OwnPay'
        ]);
    }

    /**
     * Admin Dashboard.
     */
    public function dashboard(): void
    {
        $this->checkAuth();
        $db = Database::getConnection();

        // 1. Stats
        $subscribersCount = (int)$db->query("SELECT COUNT(*) FROM `op_org_subscribers`")->fetchColumn();
        $subscribersToday = (int)$db->query("SELECT COUNT(*) FROM `op_org_subscribers` WHERE DATE(`subscribed_at`) = CURDATE()")->fetchColumn();
        
        $donationsCount = (int)$db->query("SELECT COUNT(*) FROM `op_org_donations`")->fetchColumn();
        $donationsTotalBDT = (float)$db->query("SELECT SUM(amount) FROM `op_org_donations`")->fetchColumn();

        $sponsorsCount = (int)$db->query("SELECT COUNT(*) FROM `op_org_sponsors` WHERE `active` = 1")->fetchColumn();
        $contributorsCount = (int)$db->query("SELECT COUNT(*) FROM `op_org_contributors` WHERE `active` = 1")->fetchColumn();

        // 2. Lists
        $recentSubscribers = $db->query("SELECT * FROM `op_org_subscribers` ORDER BY `subscribed_at` DESC LIMIT 10")->fetchAll();
        $recentDonations = $db->query("SELECT * FROM `op_org_donations` ORDER BY `created_at` DESC LIMIT 5")->fetchAll();

        // 3. System checks
        $smtpStatus = 'Not Configured';
        $smtpHost = $db->query("SELECT `setting_value` FROM `op_org_settings` WHERE `setting_key` = 'smtp_host'")->fetchColumn();
        if (!empty($smtpHost)) {
            $smtpStatus = 'Configured';
        }

        $mailerliteStatus = 'Not Configured';
        $mlKey = $db->query("SELECT `setting_value` FROM `op_org_settings` WHERE `setting_key` = 'mailerlite_api_key'")->fetchColumn();
        if (!empty($mlKey)) {
            $mailerliteStatus = 'Configured';
        }

        $sitemapTime = $db->query("SELECT `setting_value` FROM `op_org_settings` WHERE `setting_key` = 'sitemap_last_generated'")->fetchColumn();
        $lastSitemap = $sitemapTime ? date('F j, Y H:i:s', strtotime((string)$sitemapTime)) : 'Never';

        $this->render('admin/dashboard', [
            'subscribersCount' => $subscribersCount,
            'subscribersToday' => $subscribersToday,
            'donationsCount' => $donationsCount,
            'donationsTotalBDT' => $donationsTotalBDT,
            'sponsorsCount' => $sponsorsCount,
            'contributorsCount' => $contributorsCount,
            'recentSubscribers' => $recentSubscribers,
            'recentDonations' => $recentDonations,
            'smtpStatus' => $smtpStatus,
            'mailerliteStatus' => $mailerliteStatus,
            'lastSitemap' => $lastSitemap,
            'title' => 'Dashboard | OwnPay Admin'
        ]);
    }

    /**
     * Admin Log Out.
     */
    public function logout(): void
    {
        $this->startSession();
        $this->logAction('logout', 'User logged out.');
        $this->logoutSession();
        $this->redirect('/admin/login');
    }

    /**
     * Subscribers list / sync / export.
     */
    public function subscribers(): void
    {
        $this->checkAuth();
        $db = Database::getConnection();

        // Check for actions
        $action = $_GET['action'] ?? '';
        
        if ($action === 'export') {
            $this->exportSubscribersCsv($db);
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrf()) {
                $_SESSION['admin_error'] = 'CSRF validation failed.';
                $this->redirect('/admin/subscribers');
            }

            $bulkAction = $_POST['bulk_action'] ?? '';
            $selected = $_POST['selected_emails'] ?? [];

            if (!empty($selected) && is_array($selected)) {
                if ($bulkAction === 'delete') {
                    $placeholders = implode(',', array_fill(0, count($selected), '?'));
                    $stmt = $db->prepare("DELETE FROM `op_org_subscribers` WHERE `email` IN ($placeholders)");
                    $stmt->execute($selected);
                    $this->logAction('bulk_delete_subscribers', implode(', ', $selected));
                } elseif ($bulkAction === 'sync') {
                    // Force sync selected (Simulated or triggered)
                    foreach ($selected as $email) {
                        $this->syncSubscriberToMailerLite($db, $email);
                    }
                    $this->logAction('bulk_sync_subscribers', implode(', ', $selected));
                }
            }
            $this->redirect('/admin/subscribers');
        }

        // Search & Pagination
        $search = trim((string)($_GET['q'] ?? ''));
        $page = (int)($_GET['page'] ?? 1);
        if ($page < 1) $page = 1;
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        if (!empty($search)) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM `op_org_subscribers` WHERE `email` LIKE ?");
            $stmt->execute(['%' . $search . '%']);
            $total = (int) $stmt->fetchColumn();

            $stmt = $db->prepare("SELECT * FROM `op_org_subscribers` WHERE `email` LIKE ? ORDER BY `subscribed_at` DESC LIMIT ? OFFSET ?");
            $stmt->bindValue(1, '%' . $search . '%', PDO::PARAM_STR);
            $stmt->bindValue(2, $perPage, PDO::PARAM_INT);
            $stmt->bindValue(3, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $subscribers = $stmt->fetchAll();
        } else {
            $total = (int)$db->query("SELECT COUNT(*) FROM `op_org_subscribers`")->fetchColumn();
            $stmt = $db->prepare("SELECT * FROM `op_org_subscribers` ORDER BY `subscribed_at` DESC LIMIT ? OFFSET ?");
            $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
            $stmt->bindValue(2, $offset, PDO::PARAM_INT);
            $stmt->execute();
            $subscribers = $stmt->fetchAll();
        }

        $totalPages = (int)ceil($total / $perPage);

        $this->render('admin/subscribers', [
            'subscribers' => $subscribers,
            'search' => $search,
            'page' => $page,
            'totalPages' => $totalPages,
            'csrfToken' => $this->csrfToken(),
            'title' => 'Manage Subscribers | OwnPay'
        ]);
    }

    private function exportSubscribersCsv(PDO $db): void
    {
        $this->logAction('export_subscribers_csv');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=subscribers_' . date('Ymd_His') . '.csv');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Email', 'Subscribed At', 'MailerLite Synced', 'MailerLite ID', 'Source']);

        $stmt = $db->query("SELECT `email`, `subscribed_at`, `mailerlite_synced`, `mailerlite_id`, `source` FROM `op_org_subscribers` ORDER BY `subscribed_at` DESC");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    private function syncSubscriberToMailerLite(PDO $db, string $email): void
    {
        // Fetch Mailerlite Settings
        $stmt = $db->query("SELECT `setting_value` FROM `op_org_settings` WHERE `setting_key` = 'mailerlite_api_key'");
        $apiKey = $stmt->fetchColumn();

        $stmt = $db->query("SELECT `setting_value` FROM `op_org_settings` WHERE `setting_key` = 'mailerlite_group_id'");
        $groupId = $stmt->fetchColumn();

        if (empty($apiKey) || empty($groupId)) {
            return;
        }

        $url = "https://api.mailerlite.com/api/v2/groups/{$groupId}/subscribers";
        $payload = [
            'email' => $email,
            'resubscribe' => true
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-MailerLite-ApiKey: ' . $apiKey
            ],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);

        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 || $code === 201) {
            $resDec = json_decode((string)$res, true);
            $mlId = isset($resDec['id']) ? (string)$resDec['id'] : '';
            
            $stmt = $db->prepare("UPDATE `op_org_subscribers` SET `mailerlite_synced` = 1, `mailerlite_id` = ? WHERE `email` = ?");
            $stmt->execute([$mlId, $email]);
        }
    }

    /**
     * Donations Module.
     */
    public function donations(): void
    {
        $this->checkAuth();
        $db = Database::getConnection();

        // Handle delete/toggle display
        $action = $_GET['action'] ?? '';
        $id = (int)($_GET['id'] ?? 0);

        if ($id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrf()) {
                $_SESSION['admin_error'] = 'CSRF validation failed.';
                $this->redirect('/admin/donations');
            }

            if ($action === 'toggle') {
                $stmt = $db->prepare("UPDATE `op_org_donations` SET `public_display` = NOT `public_display` WHERE `id` = ?");
                $stmt->execute([$id]);
                $this->logAction('toggle_donation_visibility', "ID: {$id}");
            } elseif ($action === 'delete') {
                $stmt = $db->prepare("DELETE FROM `op_org_donations` WHERE `id` = ?");
                $stmt->execute([$id]);
                $this->logAction('delete_donation', "ID: {$id}");
            }
            $this->redirect('/admin/donations');
        }

        // Fetch donations
        $donations = $db->query("SELECT * FROM `op_org_donations` ORDER BY `created_at` DESC")->fetchAll();

        // Aggregated stats
        $stmt = $db->query("SELECT COUNT(*) as count, SUM(amount) as total FROM `op_org_donations`");
        $stats = $stmt->fetch();

        $this->render('admin/donations', [
            'donations' => $donations,
            'stats' => $stats,
            'csrfToken' => $this->csrfToken(),
            'title' => 'Manage Donations | OwnPay'
        ]);
    }

    /**
     * Sponsors Module (Full CRUD).
     */
    public function sponsors(): void
    {
        $this->checkAuth();
        $db = Database::getConnection();

        $action = $_GET['action'] ?? 'list';
        $id = (int)($_GET['id'] ?? 0);

        if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrf()) {
                $_SESSION['admin_error'] = 'CSRF validation failed.';
                $this->redirect('/admin/sponsors');
            }

            $name = trim((string)($_POST['name'] ?? ''));
            $slug = trim((string)($_POST['slug'] ?? ''));
            $websiteUrl = trim((string)($_POST['website_url'] ?? ''));
            $nofollow = isset($_POST['nofollow']) ? 1 : 0;
            $description = trim((string)($_POST['description'] ?? ''));
            $tier = trim((string)($_POST['tier'] ?? 'Community'));
            $displayOrder = (int)($_POST['display_order'] ?? 0);
            $active = isset($_POST['active']) ? 1 : 0;

            // Handle Logo File Upload (randomized and validated extension)
            $logoPath = $this->handleLogoUpload('logo');

            if (empty($name) || empty($slug) || empty($logoPath) || empty($websiteUrl)) {
                $_SESSION['admin_error'] = 'Please enter all required fields and upload a valid logo.';
                $this->redirect('/admin/sponsors?action=create');
            }

            $stmt = $db->prepare("INSERT INTO `op_org_sponsors` (`name`, `slug`, `logo_path`, `website_url`, `nofollow`, `description`, `tier`, `display_order`, `active`) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $slug, $logoPath, $websiteUrl, $nofollow, $description, $tier, $displayOrder, $active]);

            $this->logAction('create_sponsor', $name);
            $this->redirect('/admin/sponsors');
        }

        if ($action === 'edit' && $id > 0) {
            $stmt = $db->prepare("SELECT * FROM `op_org_sponsors` WHERE `id` = ? LIMIT 1");
            $stmt->execute([$id]);
            $sponsor = $stmt->fetch();

            if (!$sponsor) {
                $_SESSION['admin_error'] = 'Sponsor not found.';
                $this->redirect('/admin/sponsors');
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                if (!$this->verifyCsrf()) {
                    $_SESSION['admin_error'] = 'CSRF validation failed.';
                    $this->redirect("/admin/sponsors?action=edit&id={$id}");
                }

                $name = trim((string)($_POST['name'] ?? ''));
                $slug = trim((string)($_POST['slug'] ?? ''));
                $websiteUrl = trim((string)($_POST['website_url'] ?? ''));
                $nofollow = isset($_POST['nofollow']) ? 1 : 0;
                $description = trim((string)($_POST['description'] ?? ''));
                $tier = trim((string)($_POST['tier'] ?? 'Community'));
                $displayOrder = (int)($_POST['display_order'] ?? 0);
                $active = isset($_POST['active']) ? 1 : 0;

                $logoPath = $sponsor['logo_path'];
                if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    $logoPath = $this->handleLogoUpload('logo');
                }

                if (empty($name) || empty($slug) || empty($logoPath) || empty($websiteUrl)) {
                    $_SESSION['admin_error'] = 'Please enter all required fields.';
                    $this->redirect("/admin/sponsors?action=edit&id={$id}");
                }

                $stmt = $db->prepare("UPDATE `op_org_sponsors` SET `name` = ?, `slug` = ?, `logo_path` = ?, `website_url` = ?, `nofollow` = ?, `description` = ?, `tier` = ?, `display_order` = ?, `active` = ? WHERE `id` = ?");
                $stmt->execute([$name, $slug, $logoPath, $websiteUrl, $nofollow, $description, $tier, $displayOrder, $active, $id]);

                $this->logAction('edit_sponsor', $name);
                $this->redirect('/admin/sponsors');
            }

            $this->render('admin/sponsors_edit', [
                'sponsor' => $sponsor,
                'csrfToken' => $this->csrfToken(),
                'title' => 'Edit Sponsor | OwnPay'
            ]);
            return;
        }

        if ($action === 'delete' && $id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrf()) {
                $_SESSION['admin_error'] = 'CSRF validation failed.';
                $this->redirect('/admin/sponsors');
            }

            $stmt = $db->prepare("DELETE FROM `op_org_sponsors` WHERE `id` = ?");
            $stmt->execute([$id]);

            $this->logAction('delete_sponsor', "ID: {$id}");
            $this->redirect('/admin/sponsors');
        }

        // Fetch sponsors
        $sponsors = $db->query("SELECT * FROM `op_org_sponsors` ORDER BY `display_order` ASC")->fetchAll();

        $error = $_SESSION['admin_error'] ?? null;
        unset($_SESSION['admin_error']);

        $this->render('admin/sponsors', [
            'sponsors' => $sponsors,
            'action' => $action,
            'error' => $error,
            'csrfToken' => $this->csrfToken(),
            'title' => 'Manage Sponsors | OwnPay'
        ]);
    }

    private function handleLogoUpload(string $inputName): ?string
    {
        if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $file = $_FILES[$inputName];

        // 1. Validate MIME type server-side strictly
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        
        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg'
        ];

        if (!isset($allowedMimes[$mime])) {
            return null; // Invalid MIME type
        }

        $ext = $allowedMimes[$mime];

        // 2. Validate max size (2MB)
        if ($file['size'] > 2 * 1024 * 1024) {
            return null;
        }

        // 3. Store with randomized filename inside public folder
        $uploadsDir = ROOT_PATH . '/public/uploads/sponsors';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }

        $randomName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = $uploadsDir . '/' . $randomName;

        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            return 'uploads/sponsors/' . $randomName;
        }

        return null;
    }

    /**
     * Contributors Module (Full CRUD).
     */
    public function contributors(): void
    {
        $this->checkAuth();
        $db = Database::getConnection();

        $action = $_GET['action'] ?? 'list';
        $id = (int)($_GET['id'] ?? 0);

        if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrf()) {
                $_SESSION['admin_error'] = 'CSRF validation failed.';
                $this->redirect('/admin/contributors');
            }

            $name = trim((string)($_POST['name'] ?? ''));
            $role = trim((string)($_POST['role'] ?? ''));
            $profileUrl = trim((string)($_POST['profile_url'] ?? ''));
            $description = trim((string)($_POST['description'] ?? ''));
            $displayOrder = (int)($_POST['display_order'] ?? 0);
            $active = isset($_POST['active']) ? 1 : 0;

            // Handle Avatar File Upload
            $avatarPath = $this->handleAvatarUpload('avatar');

            if (empty($name) || empty($role)) {
                $_SESSION['admin_error'] = 'Name and Role are required.';
                $this->redirect('/admin/contributors?action=create');
            }

            $stmt = $db->prepare("INSERT INTO `op_org_contributors` (`name`, `role`, `description`, `profile_url`, `avatar_path`, `display_order`, `active`) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $role, $description, $profileUrl, $avatarPath, $displayOrder, $active]);

            $this->logAction('create_contributor', $name);
            $this->redirect('/admin/contributors');
        }

        if ($action === 'edit' && $id > 0) {
            $stmt = $db->prepare("SELECT * FROM `op_org_contributors` WHERE `id` = ? LIMIT 1");
            $stmt->execute([$id]);
            $contributor = $stmt->fetch();

            if (!$contributor) {
                $_SESSION['admin_error'] = 'Contributor not found.';
                $this->redirect('/admin/contributors');
            }

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                if (!$this->verifyCsrf()) {
                    $_SESSION['admin_error'] = 'CSRF validation failed.';
                    $this->redirect("/admin/contributors?action=edit&id={$id}");
                }

                $name = trim((string)($_POST['name'] ?? ''));
                $role = trim((string)($_POST['role'] ?? ''));
                $profileUrl = trim((string)($_POST['profile_url'] ?? ''));
                $description = trim((string)($_POST['description'] ?? ''));
                $displayOrder = (int)($_POST['display_order'] ?? 0);
                $active = isset($_POST['active']) ? 1 : 0;

                $avatarPath = $contributor['avatar_path'];
                if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                    $avatarPath = $this->handleAvatarUpload('avatar');
                }

                if (empty($name) || empty($role)) {
                    $_SESSION['admin_error'] = 'Name and Role are required.';
                    $this->redirect("/admin/contributors?action=edit&id={$id}");
                }

                $stmt = $db->prepare("UPDATE `op_org_contributors` SET `name` = ?, `role` = ?, `description` = ?, `profile_url` = ?, `avatar_path` = ?, `display_order` = ?, `active` = ? WHERE `id` = ?");
                $stmt->execute([$name, $role, $description, $profileUrl, $avatarPath, $displayOrder, $active, $id]);

                $this->logAction('edit_contributor', $name);
                $this->redirect('/admin/contributors');
            }

            $this->render('admin/contributors_edit', [
                'contributor' => $contributor,
                'csrfToken' => $this->csrfToken(),
                'title' => 'Edit Contributor | OwnPay'
            ]);
            return;
        }

        if ($action === 'delete' && $id > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrf()) {
                $_SESSION['admin_error'] = 'CSRF validation failed.';
                $this->redirect('/admin/contributors');
            }

            $stmt = $db->prepare("DELETE FROM `op_org_contributors` WHERE `id` = ?");
            $stmt->execute([$id]);

            $this->logAction('delete_contributor', "ID: {$id}");
            $this->redirect('/admin/contributors');
        }

        // Fetch contributors
        $contributors = $db->query("SELECT * FROM `op_org_contributors` ORDER BY `display_order` ASC")->fetchAll();

        $error = $_SESSION['admin_error'] ?? null;
        unset($_SESSION['admin_error']);

        $this->render('admin/contributors', [
            'contributors' => $contributors,
            'action' => $action,
            'error' => $error,
            'csrfToken' => $this->csrfToken(),
            'title' => 'Manage Contributors | OwnPay'
        ]);
    }

    private function handleAvatarUpload(string $inputName): ?string
    {
        if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $file = $_FILES[$inputName];

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        
        $allowedMimes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($allowedMimes[$mime])) {
            return null;
        }

        $ext = $allowedMimes[$mime];

        if ($file['size'] > 2 * 1024 * 1024) {
            return null;
        }

        $uploadsDir = ROOT_PATH . '/public/uploads/contributors';
        if (!is_dir($uploadsDir)) {
            mkdir($uploadsDir, 0755, true);
        }

        $randomName = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = $uploadsDir . '/' . $randomName;

        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            return 'uploads/contributors/' . $randomName;
        }

        return null;
    }

    /**
     * Settings / CMS.
     */
    public function settings(): void
    {
        $this->checkAuth();
        $db = Database::getConnection();

        $error = $_SESSION['admin_error'] ?? null;
        $success = $_SESSION['admin_success'] ?? null;
        unset($_SESSION['admin_error'], $_SESSION['admin_success']);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrf()) {
                $_SESSION['admin_error'] = 'CSRF validation failed.';
                $this->redirect('/admin/settings');
            }

            // Save key/value settings
            $stmt = $db->prepare("INSERT INTO `op_org_settings` (`setting_key`, `setting_value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)");

            foreach ($_POST as $key => $val) {
                if ($key === 'csrf_token' || $key === '_method') continue;

                $val = trim((string)$val);

                // Handle SMTP Password Encrypt
                if ($key === 'smtp_password' && !empty($val)) {
                    $encryptedPassword = ConfigEncryptor::encrypt($val);
                    $stmt->execute(['smtp_password_enc', $encryptedPassword]);
                    continue;
                } elseif ($key === 'smtp_password') {
                    // Do not overwrite existing password if empty
                    continue;
                }

                $stmt->execute([$key, $val]);
            }

            $this->logAction('update_settings');
            $_SESSION['admin_success'] = 'Settings updated successfully.';
            $this->redirect('/admin/settings');
        }

        // Fetch settings
        $stmt = $db->query("SELECT * FROM `op_org_settings`");
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        $this->render('admin/settings', [
            'settings' => $settings,
            'error' => $error,
            'success' => $success,
            'csrfToken' => $this->csrfToken(),
            'title' => 'Settings CMS | OwnPay'
        ]);
    }

    /**
     * Audit Log.
     */
    public function auditLog(): void
    {
        $this->checkAuth();
        $db = Database::getConnection();

        // Handle export CSV
        $action = $_GET['action'] ?? '';
        if ($action === 'export') {
            $this->exportAuditLogCsv($db);
            return;
        }

        $logs = $db->query("SELECT l.*, u.username FROM `op_org_audit_log` l 
                            LEFT JOIN `op_org_admin_users` u ON l.user_id = u.id 
                            ORDER BY l.created_at DESC")->fetchAll();

        $this->render('admin/audit_log', [
            'logs' => $logs,
            'title' => 'Audit Log | OwnPay Admin'
        ]);
    }

    private function exportAuditLogCsv(PDO $db): void
    {
        $this->logAction('export_audit_log_csv');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=audit_log_' . date('Ymd_His') . '.csv');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['ID', 'User ID', 'Username', 'Action', 'Details', 'IP Address', 'Created At']);

        $stmt = $db->query("SELECT l.id, l.user_id, u.username, l.action, l.details, l.ip_address, l.created_at FROM `op_org_audit_log` l 
                            LEFT JOIN `op_org_admin_users` u ON l.user_id = u.id 
                            ORDER BY l.created_at DESC");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit;
    }

    /**
     * Trigger sitemap regeneration.
     */
    public function sitemap(): void
    {
        $this->checkAuth();
        $this->startSession();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->verifyCsrf()) {
                $_SESSION['admin_error'] = 'CSRF validation failed.';
                $this->redirect('/admin/dashboard');
            }

            try {
                // Trigger sitemap regeneration locally by writing to the public path
                $sitemapFile = ROOT_PATH . '/public/sitemap.xml';
                $callbackUrl = APP_URL . '/sitemap.xml';

                // Fetch xml content from local sitemap endpoint
                $ch = curl_init($callbackUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 10,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0
                ]);
                $xml = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode !== 200 || empty($xml)) {
                    throw new RuntimeException("Could not fetch sitemap XML content. HTTP Code: {$httpCode}");
                }

                file_put_contents($sitemapFile, $xml);

                // Update settings timestamp
                $db = Database::getConnection();
                $stmt = $db->prepare("INSERT INTO `op_org_settings` (`setting_key`, `setting_value`) VALUES ('sitemap_last_generated', ?) ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)");
                $stmt->execute([date('Y-m-d H:i:s')]);

                $this->logAction('regenerate_sitemap', 'Successfully regenerated sitemap.xml');
                $_SESSION['admin_success'] = 'Sitemap regenerated successfully.';
            } catch (Throwable $e) {
                $_SESSION['admin_error'] = 'Sitemap regeneration failed: ' . $e->getMessage();
            }

            $this->redirect('/admin/dashboard');
        }
    }
}
