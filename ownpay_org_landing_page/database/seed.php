<?php
declare(strict_types=1);

try {
    require_once dirname(__DIR__) . '/config/config.php';

    $pdo = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // 1. Seed Admin User
    $username = 'admin';
    $password = 'admin123';
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    $stmt = $pdo->prepare("INSERT INTO `op_org_admin_users` (`username`, `password_hash`, `created_at`) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE `password_hash` = VALUES(`password_hash`)");
    $stmt->execute([$username, $hash]);

    // 2. Seed Sponsors
    $sponsors = [
        [
            'name' => 'Namepart',
            'slug' => 'namepart',
            'logo_path' => 'assets/img/sponsors/namepart_logo.png',
            'website_url' => 'https://namepart.com',
            'nofollow' => 1,
            'description' => 'Namepart sponsored our core ledger development, cryptographic security hardening, and license auditing runway.',
            'tier' => 'Gold',
            'display_order' => 1,
            'active' => 1
        ],
        [
            'name' => 'FlexoHost',
            'slug' => 'flexohost',
            'logo_path' => 'assets/img/sponsors/FlexoHost_logo.webp',
            'website_url' => 'https://www.flexohost.com',
            'nofollow' => 1,
            'description' => 'FlexoHost sponsored our platform domain and hosting infrastructure for comprehensive testing and deployment, allowing high-performance white-label routing.',
            'tier' => 'Gold',
            'display_order' => 2,
            'active' => 1
        ],
        [
            'name' => 'Bangla Hoster',
            'slug' => 'banglahoster',
            'logo_path' => 'assets/img/sponsors/banglahoster.svg',
            'website_url' => 'https://banglahoster.net',
            'nofollow' => 1,
            'description' => 'Bangla Hoster provided software licenses for our server and system infrastructure management.',
            'tier' => 'Silver',
            'display_order' => 3,
            'active' => 1
        ],
        [
            'name' => 'HostSire24',
            'slug' => 'hostsire24',
            'logo_path' => 'assets/img/sponsors/hostsite24.png',
            'website_url' => 'https://hostsite24.com',
            'nofollow' => 1,
            'description' => 'HostSire24 sponsored testing servers for staging deployment, API validation, and load testing.',
            'tier' => 'Silver',
            'display_order' => 4,
            'active' => 1
        ],
        [
            'name' => 'Hostazy',
            'slug' => 'hostazy',
            'logo_path' => 'assets/img/sponsors/hostazy.png',
            'website_url' => 'https://hostazy.com.bd/',
            'nofollow' => 1,
            'description' => 'Hostazy provided auxiliary server infrastructure and domain routing services during development.',
            'tier' => 'Community',
            'display_order' => 5,
            'active' => 1
        ]
    ];

    $stmt = $pdo->prepare("INSERT INTO `op_org_sponsors` (`name`, `slug`, `logo_path`, `website_url`, `nofollow`, `description`, `tier`, `display_order`, `active`) 
                           VALUES (:name, :slug, :logo_path, :website_url, :nofollow, :description, :tier, :display_order, :active)
                           ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `logo_path` = VALUES(`logo_path`), `website_url` = VALUES(`website_url`), `description` = VALUES(`description`), `tier` = VALUES(`tier`), `display_order` = VALUES(`display_order`), `active` = VALUES(`active`)");
    foreach ($sponsors as $s) {
        $stmt->execute($s);
    }

    // 3. Seed Contributors
    $contributors = [
        [
            'name' => 'Fattain Naime',
            'role' => 'Lead Developer & Creator',
            'description' => 'Designed the core PHP SOA architecture, reflection container, and white-label custom domain resolver middleware.',
            'profile_url' => 'https://iamnaime.info.bd',
            'avatar_path' => 'assets/img/contributors/fattain-naime.jpg',
            'display_order' => 1,
            'active' => 1
        ],
        [
            'name' => 'Tahira Akter Hira',
            'role' => 'Logo & Brand Design',
            'description' => 'Crafted the premium visual identity, logo, typography layout, and user-experience styling of OwnPay.',
            'profile_url' => 'https://www.linkedin.com/in/tahera-akter-180223259',
            'avatar_path' => 'assets/img/contributors/hira.jpeg',
            'display_order' => 2,
            'active' => 1
        ],
        [
            'name' => 'Abdullah Bin Ziad',
            'role' => 'Name Concept & Advisor',
            'description' => 'Suggested the "OwnPay" name to communicate ownership, and sponsored active testing VPS servers.',
            'profile_url' => 'https://www.facebook.com/share/1ADTWT5QdE/',
            'avatar_path' => 'assets/img/contributors/ziad.jpg',
            'display_order' => 3,
            'active' => 1
        ]
    ];

    $pdo->exec("TRUNCATE TABLE `op_org_contributors`");
    $stmt = $pdo->prepare("INSERT INTO `op_org_contributors` (`name`, `role`, `description`, `profile_url`, `avatar_path`, `display_order`, `active`) 
                           VALUES (:name, :role, :description, :profile_url, :avatar_path, :display_order, :active)");
    foreach ($contributors as $c) {
        $stmt->execute($c);
    }

    // 4. Seed Default CMS Settings
    $settings = [
        'hero_badge' => 'Open Source · Self-Hosted · Payment Infrastructure',
        'hero_headline' => 'Self-Hosted Payments. Zero Platform Tax.',
        'hero_subheadline' => 'OwnPay is the enterprise-grade, open-source payment gateway automation platform. Automate transactions on your own server, maintain absolute data privacy, and extend features via a universal plugin engine.',
        'hero_cta_subscribe' => 'Join Waitlist',
        'hero_cta_github' => 'Star on GitHub',
        'hero_cta_sponsor' => 'Become a Sponsor',
        'github_repo_url' => 'https://github.com/own-pay/ownpay',
        'mailerlite_group_id' => '11223344',
        'site_name' => 'OwnPay',
        'site_tagline' => 'Enterprise Self-Hosted Payment Infrastructure',
        'contact_email' => 'ping@ownpay.org',
        'social_github' => 'https://github.com/own-pay',
        'social_twitter' => 'https://twitter.com/ownpay',
        'social_discord' => 'https://discord.gg/ownpay',
        'google_analytics_id' => '',
        'announcement_bar_enabled' => '1',
        'announcement_bar_text' => '🚀 Public Beta v0.1.0 release is coming soon! Star the repo to get early launch alerts.',
        'smtp_host' => 'smtp.mailtrap.io',
        'smtp_port' => '2525',
        'smtp_username' => 'test_user',
        'smtp_password_enc' => '', // AES-256 encrypted password
        'smtp_from_name' => 'OwnPay Support',
        'smtp_from_email' => 'ping@ownpay.org',
        'github_stars_cached' => '142',
        'github_stars_last_updated' => date('Y-m-d H:i:s')
    ];

    $stmt = $pdo->prepare("INSERT INTO `op_org_settings` (`setting_key`, `setting_value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)");
    foreach ($settings as $k => $v) {
        $stmt->execute([$k, $v]);
    }

    // 5. Seed initial donors
    $donors = [
        [
            'donor_name' => 'Rahamat Karim',
            'email' => 'rahamat@example.com',
            'amount' => 45.00,
            'currency' => 'USD',
            'tier' => 'Silver',
            'message' => 'Love this project! Sovereign hosting of payments is exactly what the dev community needs.',
            'created_at' => date('Y-m-d H:i:s', strtotime('-10 days')),
            'public_display' => 1,
            'unique_id' => 'OP-DON-20260601-3892'
        ],
        [
            'donor_name' => 'Tasnim Islam',
            'email' => 'tasnim@example.com',
            'amount' => 25.00,
            'currency' => 'USD',
            'tier' => 'Bronze',
            'message' => 'Supporting docs and security auditing. Keep up the awesome work!',
            'created_at' => date('Y-m-d H:i:s', strtotime('-5 days')),
            'public_display' => 1,
            'unique_id' => 'OP-DON-20260605-7281'
        ],
        [
            'donor_name' => 'Yeasin Arafat',
            'email' => 'yeasin@example.com',
            'amount' => 15.00,
            'currency' => 'USD',
            'tier' => 'Community',
            'message' => 'Small contribution to help with the domain and testing servers.',
            'created_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
            'public_display' => 1,
            'unique_id' => 'OP-DON-20260609-1092'
        ]
    ];

    $pdo->exec("TRUNCATE TABLE `op_org_donations`");
    $stmt = $pdo->prepare("INSERT INTO `op_org_donations` (`donor_name`, `email`, `amount`, `currency`, `tier`, `message`, `created_at`, `public_display`, `unique_id`) 
                           VALUES (:donor_name, :email, :amount, :currency, :tier, :message, :created_at, :public_display, :unique_id)");
    foreach ($donors as $d) {
        $stmt->execute($d);
    }

    echo "Database seeded successfully!\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Seeding Error: " . $e->getMessage() . "\n");
    exit(1);
}
