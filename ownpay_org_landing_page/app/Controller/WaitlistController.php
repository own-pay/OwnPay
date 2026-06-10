<?php
declare(strict_types=1);

/**
 * OwnPay Landing Page WaitlistController
 * File: app/Controller/WaitlistController.php
 */

require_once ROOT_PATH . '/app/Database.php';
require_once ROOT_PATH . '/app/Controller/Controller.php';

class WaitlistController extends Controller
{
    private const RATE_LIMIT_PER_IP = 3;
    private const RATE_LIMIT_WINDOW_SECONDS = 3600;
    private const MAX_SUBSCRIBERS = 50000;

    /**
     * Handle Waitlist Submissions.
     */
    public function subscribe(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json(['success' => false, 'message' => 'Method not allowed.'], 405);
        }

        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);

        if (!is_array($body) || !isset($body['email'])) {
            $this->json(['success' => false, 'message' => 'Invalid request.'], 400);
        }

        $email = trim((string) $body['email']);
        if (empty($email)) {
            $this->json(['success' => false, 'message' => 'Email address is required.'], 400);
        }

        if (strlen($email) > 254) {
            $this->json(['success' => false, 'message' => 'Email address is too long.'], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->json(['success' => false, 'message' => 'Please enter a valid email address.'], 400);
        }

        $email = strtolower($email);
        $ip = $this->getClientIp();

        $db = Database::getConnection();

        // 1. Rate Limit Check
        if ($this->isRateLimited($db, $ip)) {
            $this->json(['success' => false, 'message' => 'Too many submissions from this IP. Please try again in an hour.'], 429);
        }

        // 2. Count Check
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM `op_org_subscribers`");
        $count = (int) $stmt->fetch()['cnt'];
        if ($count >= self::MAX_SUBSCRIBERS) {
            $this->json(['success' => false, 'message' => 'The waitlist is currently full.'], 400);
        }

        // 3. Duplicate Check
        $stmt = $db->prepare("SELECT id FROM `op_org_subscribers` WHERE `email` = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $this->json(['success' => false, 'message' => 'This email is already on the waitlist!'], 200);
        }

        // 4. Insert Subscriber
        $stmt = $db->prepare("INSERT INTO `op_org_subscribers` (`email`, `subscribed_at`, `mailerlite_synced`, `source`) VALUES (?, NOW(), 0, 'landing_page')");
        $stmt->execute([$email]);

        // 5. Log Action for Audit and Rate Limiting
        $stmt = $db->prepare("INSERT INTO `op_org_audit_log` (`user_id`, `action`, `details`, `ip_address`, `created_at`) VALUES (NULL, 'waitlist_subscribe', ?, ?, NOW())");
        $stmt->execute([$email, $ip]);

        // 6. Trigger background/direct MailerLite sync if configured
        $this->syncToMailerLite($email);

        $this->json(['success' => true, 'message' => "You're on the list! We'll notify you at launch."]);
    }

    private function getClientIp(): string
    {
        $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = trim(explode(',', $_SERVER[$h])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    private function isRateLimited(PDO $db, string $ip): bool
    {
        $since = date('Y-m-d H:i:s', time() - self::RATE_LIMIT_WINDOW_SECONDS);
        $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM `op_org_audit_log` 
                               WHERE `action` = 'waitlist_subscribe' AND `ip_address` = ? AND `created_at` >= ?");
        $stmt->execute([$ip, $since]);
        $attempts = (int) $stmt->fetch()['cnt'];

        return $attempts >= self::RATE_LIMIT_PER_IP;
    }

    /**
     * Synchronize subscriber email directly to MailerLite.
     */
    private function syncToMailerLite(string $email): void
    {
        $db = Database::getConnection();

        // Get group ID and API Key from Settings
        $stmt = $db->query("SELECT `setting_key`, `setting_value` FROM `op_org_settings` WHERE `setting_key` IN ('mailerlite_api_key', 'mailerlite_group_id')");
        $rows = $stmt->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }

        $apiKey = $settings['mailerlite_api_key'] ?? '';
        $groupId = $settings['mailerlite_group_id'] ?? '';

        if (empty($apiKey) || empty($groupId)) {
            return; // Not configured yet
        }

        // Call MailerLite API (v2 or v3)
        // Let's use MailerLite Classic v2 API for simplicity
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
            
            // Mark synced in DB
            $stmt = $db->prepare("UPDATE `op_org_subscribers` SET `mailerlite_synced` = 1, `mailerlite_id` = ? WHERE `email` = ?");
            $stmt->execute([$mlId, $email]);
        }
    }
}
