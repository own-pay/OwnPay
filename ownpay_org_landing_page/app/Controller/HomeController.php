<?php
declare(strict_types=1);

/**
 * OwnPay Landing Page HomeController
 * File: app/Controller/HomeController.php
 */

require_once ROOT_PATH . '/app/Database.php';
require_once ROOT_PATH . '/app/Controller/Controller.php';

class HomeController extends Controller
{
    /**
     * Helper to load settings from DB.
     */
    private function getSettings(): array
    {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT `setting_key`, `setting_value` FROM `op_org_settings`");
        $rows = $stmt->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    /**
     * Homepage renderer.
     */
    public function index(): void
    {
        $db = Database::getConnection();

        // Fetch settings
        $settings = $this->getSettings();

        // Fetch active sponsors
        $stmt = $db->prepare("SELECT * FROM `op_org_sponsors` WHERE `active` = 1 ORDER BY `display_order` ASC");
        $stmt->execute();
        $sponsors = $stmt->fetchAll();

        // Fetch active contributors
        $stmt = $db->prepare("SELECT * FROM `op_org_contributors` WHERE `active` = 1 ORDER BY `display_order` ASC");
        $stmt->execute();
        $contributors = $stmt->fetchAll();

        // Separate Elite/Gold vs lower tier sponsors
        $eliteSponsors = [];
        $regularSponsors = [];
        foreach ($sponsors as $s) {
            if (strcasecmp((string)$s['tier'], 'gold') === 0 || strcasecmp((string)$s['tier'], 'elite') === 0) {
                $eliteSponsors[] = $s;
            } else {
                $regularSponsors[] = $s;
            }
        }

        $this->render('home', [
            'settings' => $settings,
            'eliteSponsors' => $eliteSponsors,
            'regularSponsors' => $regularSponsors,
            'contributors' => $contributors,
            'title' => $settings['site_name'] . ' | ' . $settings['site_tagline'],
            'description' => $settings['hero_subheadline']
        ]);
    }

    /**
     * Privacy Policy page renderer.
     */
    public function privacy(): void
    {
        $settings = $this->getSettings();
        $this->render('privacy', [
            'settings' => $settings,
            'title' => 'Privacy Policy | ' . ($settings['site_name'] ?? 'OwnPay'),
            'description' => 'Our transparent, tracker-free privacy policy.'
        ]);
    }

    /**
     * Architecture page renderer.
     */
    public function architecture(): void
    {
        $settings = $this->getSettings();
        $this->render('architecture', [
            'settings' => $settings,
            'title' => 'Architecture Deep Dive | ' . ($settings['site_name'] ?? 'OwnPay'),
            'description' => 'Understand OwnPay\'s custom service-oriented PHP 8.2+ architecture.'
        ]);
    }

    /**
     * Security page renderer.
     */
    public function security(): void
    {
        $settings = $this->getSettings();
        $this->render('security', [
            'settings' => $settings,
            'title' => 'Security Posture & Disclosures | ' . ($settings['site_name'] ?? 'OwnPay'),
            'description' => 'Understand our pre-release audit status, disclosure guidelines, and reporting security practices.'
        ]);
    }

    /**
     * Robots.txt handler (Dynamic output).
     */
    public function robots(): void
    {
        header('Content-Type: text/plain; charset=utf-8');
        echo "User-agent: *\n";
        echo "Allow: /\n";
        echo "Disallow: /admin/\n";
        echo "Sitemap: " . APP_URL . "/sitemap.xml\n";
        exit;
    }

    /**
     * Sitemap.xml handler.
     */
    public function sitemap(): void
    {
        header('Content-Type: application/xml; charset=utf-8');
        
        $urls = [
            ['loc' => APP_URL . '/', 'changefreq' => 'daily', 'priority' => '1.0'],
            ['loc' => APP_URL . '/donate', 'changefreq' => 'weekly', 'priority' => '0.8'],
            ['loc' => APP_URL . '/donors', 'changefreq' => 'daily', 'priority' => '0.8'],
            ['loc' => APP_URL . '/sponsors', 'changefreq' => 'weekly', 'priority' => '0.7'],
            ['loc' => APP_URL . '/privacy-policy', 'changefreq' => 'monthly', 'priority' => '0.5'],
            ['loc' => APP_URL . '/architecture', 'changefreq' => 'monthly', 'priority' => '0.6'],
            ['loc' => APP_URL . '/security', 'changefreq' => 'monthly', 'priority' => '0.6'],
        ];

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $url) {
            echo "  <url>\n";
            echo "    <loc>" . htmlspecialchars($url['loc']) . "</loc>\n";
            echo "    <lastmod>" . date('Y-m-d') . "</lastmod>\n";
            echo "    <changefreq>" . $url['changefreq'] . "</changefreq>\n";
            echo "    <priority>" . $url['priority'] . "</priority>\n";
            echo "  </url>\n";
        }
        echo '</urlset>';
        exit;
    }

    /**
     * API: Get Sponsor Details by Slug.
     */
    public function sponsorInfo(): void
    {
        $slug = trim((string)($_GET['slug'] ?? ''));
        if (empty($slug)) {
            $this->json(['success' => false, 'message' => 'Missing slug.'], 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM `op_org_sponsors` WHERE `slug` = ? AND `active` = 1 LIMIT 1");
        $stmt->execute([$slug]);
        $sponsor = $stmt->fetch();

        if (!$sponsor) {
            $this->json(['success' => false, 'message' => 'Sponsor not found.'], 404);
        }

        $this->json([
            'success' => true,
            'sponsor' => $sponsor
        ]);
    }

    /**
     * API: Cache GitHub Stars count reported by client.
     */
    public function syncStars(): void
    {
        $raw = file_get_contents('php://input');
        $body = json_decode($raw, true);

        if (!is_array($body) || !isset($body['stars'])) {
            $this->json(['success' => false, 'message' => 'Invalid body.'], 400);
        }

        $stars = (int) $body['stars'];
        if ($stars <= 0) {
            $this->json(['success' => false, 'message' => 'Invalid count.'], 400);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("INSERT INTO `op_org_settings` (`setting_key`, `setting_value`) VALUES ('github_stars_cached', ?), ('github_stars_last_updated', ?) 
                               ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)");
        $stmt->execute([(string)$stars, date('Y-m-d H:i:s')]);

        $this->json(['success' => true]);
    }
}
