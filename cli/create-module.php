<?php
declare(strict_types=1);

/**
 * OwnPay Module Developer CLI Generator (Advanced Edition)
 *
 * Premium, interactive command-line utility to scaffold Gateway Plugins,
 * Addon Plugins, and Themes in full alignment with PSR-4 namespaces,
 * dynamic hook systems, double-entry ledgers, and ISO-27001 / PCI-DSS compliance.
 */

// Define project directories
$projectRoot = dirname(__DIR__);

// CLI Color Palette
define('C_RESET', "\033[0m");
define('C_BOLD', "\033[1m");
define('C_GREEN', "\033[32m");
define('C_RED', "\033[31m");
define('C_YELLOW', "\033[33m");
define('C_BLUE', "\033[34m");
define('C_CYAN', "\033[36m");
define('C_MAGENTA', "\033[35m");

/**
 * Prints a beautiful welcome banner to the terminal.
 *
 * Developer Guide: This highlights the tool branding and visual identity.
 *
 * @return void
 */
function printBanner(): void
{
    echo C_CYAN . C_BOLD;
    echo "====================================================================\n";
    echo "             OwnPay Module & Theme Scaffolder CLI                   \n";
    echo "====================================================================\n" . C_RESET;
    echo C_YELLOW . " Ready to engineer secure, white-label compliant core extensions. \n\n" . C_RESET;
}

/**
 * Sanitizes terminal input strings by removing dangerous symbols, Zero-Width spaces, and BOM markers.
 *
 * Developer Guide: Ensures CLI inputs do not contain trailing return carriages or invisible control characters.
 *
 * @param string $input Raw terminal input string.
 * @return string Cleaned and trimmed safe string.
 */
function cleanInputString(string $input): string
{
    if (str_starts_with($input, "\xEF\xBB\xBF")) {
        $input = substr($input, 3);
    }
    $input = (string) preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $input);
    $input = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $input);
    return trim($input);
}

/**
 * Prompts the developer for terminal input, showing optional default parameters.
 *
 * Developer Guide: Blocks execution until the developer submits a string or presses Enter to select the default.
 *
 * @param string $label Label description of the expected input.
 * @param string|null $default Optional default fallback option.
 * @return string Cleaned user input.
 */
function promptInput(string $label, ?string $default = null): string
{
    $formattedLabel = C_BOLD . $label . C_RESET;
    if ($default !== null) {
        $formattedLabel .= " [" . C_GREEN . $default . C_RESET . "]";
    }
    $formattedLabel .= ": ";
    echo $formattedLabel;
    
    $rawInput = fgets(STDIN);
    $input = cleanInputString($rawInput === false ? '' : $rawInput);
    
    if ($input === '' && $default !== null) {
        return $default;
    }
    return $input;
}

/**
 * Renders a yes/no terminal confirm prompt.
 *
 * Developer Guide: Used to verify choices and toggle config options.
 *
 * @param string $message The yes/no question.
 * @param bool $default Default choice if input is empty (True for Y, False for N).
 * @return bool True if confirmed, false otherwise.
 */
function confirmInput(string $message, bool $default = true): bool
{
    $choices = $default ? 'Y/n' : 'y/N';
    echo C_BOLD . "{$message}" . C_RESET . " (" . C_YELLOW . $choices . C_RESET . "): ";
    
    $rawInput = fgets(STDIN);
    $input = strtolower(cleanInputString($rawInput === false ? '' : $rawInput));
    
    if ($input === '') {
        return $default;
    }
    return $input === 'y' || $input === 'yes';
}

/**
 * Renders a list of options as a selectable CLI menu.
 *
 * Developer Guide: Used to present multiple choices numerically, ensuring inputs exist in the options array.
 *
 * @param string $message Menu instructions query.
 * @param array<int, string> $options Selectable key-value array configurations.
 * @param int $default The default index.
 * @return int Chosen option index key.
 */
function selectMenu(string $message, array $options, int $default = 1): int
{
    echo C_BOLD . $message . C_RESET . "\n";
    foreach ($options as $key => $val) {
        echo "  [" . C_GREEN . $key . C_RESET . "] {$val}\n";
    }
    while (true) {
        $input = promptInput("Select option", (string)$default);
        $choice = (int)$input;
        if (isset($options[$choice])) {
            return $choice;
        }
        echo C_RED . "Invalid selection. Please try again." . C_RESET . "\n";
    }
}

/**
 * Converts a standard string containing hyphens or spaces into StudlyCaps case.
 *
 * Developer Guide: Vital to auto-generate class names and PSR-4 namespaced directories dynamically.
 *
 * @param string $string Raw name or slug string.
 * @return string The StudlyCaps formatting output.
 */
function toStudlyCase(string $string): string
{
    return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $string)));
}

// -------------------------------------------------------------
// Interactive Configuration Capture
// -------------------------------------------------------------
printBanner();

$moduleTypeNum = selectMenu("What type of extension would you like to create?", [
    1 => "Gateway Plugin (Payment processor adapter)",
    2 => "Addon Plugin (General feature/notification integration)",
    3 => "Theme (Checkout and landing templates & assets)"
], 1);

$moduleTypeMap = [
    1 => 'gateway',
    2 => 'addon',
    3 => 'theme'
];
$moduleType = $moduleTypeMap[$moduleTypeNum];

echo "\n" . C_BLUE . "=== Basic Module Configuration ===" . C_RESET . "\n";

$defaultName = $moduleType === 'theme' ? 'Custom Dark Theme' : ($moduleType === 'gateway' ? 'Nagad Gateway' : 'Audit Alert System');
$name = promptInput("Module Name", $defaultName);

// Auto-generate slug from module name (no prompt, per requirements)
$slug = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
$slug = trim($slug, '-');
if ($slug === '') {
    $slug = 'custom-module-' . bin2hex(random_bytes(3));
}
echo "Auto-generated Slug: " . C_GREEN . $slug . C_RESET . "\n";

$description = promptInput("Description", "A secure high-performance extension for the OwnPay gateway network.");
$author = promptInput("Author Name", "OwnPay Developer Team");
$version = promptInput("Initial Version", "1.0.0");
$license = promptInput("License", "MIT");

// Gateway-specific category and configuration inputs
$gatewayCategory = 'global';
if ($moduleType === 'gateway') {
    echo "\n" . C_BLUE . "=== Gateway Category Selection ===" . C_RESET . "\n";
    $categoryNum = selectMenu("Select the Payment Gateway Category:", [
        1 => "mfs (Mobile Financial Services — e.g., bKash, Nagad, aamarpay)",
        2 => "bank (Card and standard internet banking gateways — e.g., sslcommerz)",
        3 => "global (Global API networks — e.g., Stripe, Binance Pay)",
        4 => "express (Express wallet buttons — e.g., Apple Pay, Google Pay)",
        5 => "other (Custom category)"
    ], 3);
    
    $categoryMap = [
        1 => 'mfs',
        2 => 'bank',
        3 => 'global',
        4 => 'express',
        5 => 'other'
    ];
    $gatewayCategory = $categoryMap[$categoryNum];
    if ($gatewayCategory === 'other') {
        $gatewayCategory = promptInput("Enter custom category slug", "international");
    }
}

// For Theme, ask if they want PHP Template or Twig Template (Default: PHP)
$themeEngine = 'php';
if ($moduleType === 'theme') {
    $engineChoice = selectMenu("Which template engine would you like to use for your checkout theme?", [
        1 => "PHP Template (Default — pure PHP view layouts)",
        2 => "Twig Template (Standard Auto-Escaped templates)"
    ], 1);
    $themeEngine = $engineChoice === 2 ? 'twig' : 'php';
}

// Define namespaces and destination directory
$targetDir = "";
$studlySlug = toStudlyCase($slug);
$namespace = "";

if ($moduleType === 'gateway') {
    $targetDir = $projectRoot . "/modules/gateways/{$slug}";
    $namespace = "OwnPay\\Modules\\Gateways\\{$studlySlug}";
} elseif ($moduleType === 'addon') {
    $targetDir = $projectRoot . "/modules/addons/{$slug}";
    $namespace = "OwnPay\\Modules\\Addons\\{$studlySlug}";
} else {
    $targetDir = $projectRoot . "/modules/themes/{$slug}";
    $namespace = "OwnPay\\Modules\\Themes\\{$studlySlug}";
}

if (file_exists($targetDir)) {
    echo C_RED . "\nError: Destination directory already exists at: " . $targetDir . C_RESET . "\n";
    exit(1);
}

// -------------------------------------------------------------
// Interactive Capability Picker (For Addon/Plugin)
// -------------------------------------------------------------
$capabilities = [];
if ($moduleType === 'gateway') {
    $capabilities = ['gateway'];
} elseif ($moduleType === 'theme') {
    $capabilities = ['theme'];
} else {
    echo "\n" . C_BLUE . "=== Capabilities Selection ===" . C_RESET . "\n";
    echo "Pick capabilities for your Addon (enter comma-separated numbers, e.g. 1,3):\n";
    $capOptions = [
        1 => "communication (SMS, Email, Telegram delivery providers)",
        2 => "notification (Admin notification desk panels)",
        3 => "webhook (Declares custom incoming webhook callback endpoints)",
        4 => "analytics (Financial reports, auditing, and log processors)",
        5 => "cron (Scheduled tasks and background microservices)",
        6 => "export (Report data export formatter adapters)",
        7 => "authentication (Single Sign-On and OAuth providers)",
        8 => "storage (External cloud storage bucket engines)",
        9 => "dashboard (Admin home widgets components)",
        10 => "db_read (Read-access to system data layers)",
        11 => "db_write (Write-access to system database layers)",
        12 => "file_read (Local filesystem read-access permissions)",
        13 => "file_write (Local filesystem write-access permissions)",
        14 => "http_outbound (Outbound cURL and network request permissions)",
        15 => "hooks (Registers events action or filter hooks)",
        16 => "checkout_ui (Injects checkout user interface widgets)"
    ];
    foreach ($capOptions as $key => $val) {
        echo "  [" . C_GREEN . $key . C_RESET . "] {$val}\n";
    }
    
    $capSelection = promptInput("Capabilities Selection", "1");
    $selectedNums = explode(',', $capSelection);
    
    $capMap = [
        1 => 'communication',
        2 => 'notification',
        3 => 'webhook',
        4 => 'analytics',
        5 => 'cron',
        6 => 'export',
        7 => 'authentication',
        8 => 'storage',
        9 => 'dashboard',
        10 => 'db_read',
        11 => 'db_write',
        12 => 'file_read',
        13 => 'file_write',
        14 => 'http_outbound',
        15 => 'hooks',
        16 => 'checkout_ui'
    ];
    
    foreach ($selectedNums as $num) {
        $num = (int)trim($num);
        if (isset($capMap[$num])) {
            $capabilities[] = $capMap[$num];
        }
    }
    
    if (empty($capabilities)) {
        $capabilities = ['addon'];
    }
}

// Create destination directories recursively
echo "\nCreating directory structure... ";
mkdir($targetDir, 0755, true);
mkdir($targetDir . '/assets', 0755, true);
if ($moduleType === 'theme') {
    mkdir($targetDir . '/templates', 0755, true);
    mkdir($targetDir . '/templates/checkout', 0755, true);
    mkdir($targetDir . '/assets/css', 0755, true);
    mkdir($targetDir . '/assets/js', 0755, true);
}
echo C_GREEN . "Done." . C_RESET . "\n";

// -------------------------------------------------------------
// Type-Specific Manifest.json Generation (Zero Blatant Fields)
// -------------------------------------------------------------
$entrypointName = $moduleType === 'gateway' ? "{$studlySlug}Gateway.php" : ($moduleType === 'theme' ? 'Theme.php' : 'Plugin.php');

$manifestData = [
    'name' => $name,
    'slug' => $slug,
    'version' => $version,
    'description' => $description,
    'author' => $author,
    'type' => $moduleType,
    'icon' => 'assets/icon.png', // Standard brand logo parameter
    'color' => '#6366f1',
    'entrypoint' => $entrypointName,
    'namespace' => $namespace,
    'capabilities' => $capabilities,
    'requires' => [
        'core' => '>=0.1.0',
        'php' => '>=8.2'
    ]
];

// Structural layout mappings based on strict plugin/gateway/theme boundaries
if ($moduleType === 'gateway') {
    $manifestData['category'] = $gatewayCategory;
    $manifestData['csp'] = [
        'script_src' => ["https://*.{$slug}.com"],
        'style_src' => ["https://*.{$slug}.com"],
        'frame_src' => ["https://*.{$slug}.com"],
        'connect_src' => ["https://api.{$slug}.com", "https://*.{$slug}.com"]
    ];
    $manifestData['permissions'] = ['gateway.process', 'gateway.refund'];
} elseif ($moduleType === 'addon') {
    // Addons have custom routes, hooks, and default merchant settings forms
    $manifestData['hooks'] = [
        'actions' => [
            'payment.transaction.completed' => 10,
            'payment.transaction.failed' => 10
        ],
        'filters' => []
    ];
    $manifestData['routes'] = [
        ['POST', "/plugins/{$slug}/webhook", 'handleWebhook']
    ];
    $manifestData['settings'] = [
        'enabled' => false,
        'service_url' => '',
        'api_token' => ''
    ];
} elseif ($moduleType === 'theme') {
    // Themes define default styling parameters and enqueued assets arrays
    $manifestData['settings'] = [
        'primary_color' => '#0F172A',
        'accent_color' => '#3B82F6',
        'custom_footer_note' => 'Secured via 256-bit SSL bank-grade encryption'
    ];
    $manifestData['assets'] = [
        'css' => ['checkout.css'],
        'js' => ['op-fetch.js', 'checkout.js']
    ];
}

echo "Generating type-specific manifest.json... ";
file_put_contents(
    $targetDir . '/manifest.json',
    json_encode($manifestData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
);
echo C_GREEN . "Done." . C_RESET . "\n";

// -------------------------------------------------------------
// Core PHP Entrypoint Generation (Using Raw Heredocs)
// -------------------------------------------------------------
echo "Generating {$entrypointName} Entrypoint... ";

$phpContent = "";

if ($moduleType === 'gateway') {
    $phpContent = <<<'EOT'
<?php
declare(strict_types=1);

namespace {{NAMESPACE}};

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * {{NAME}} Payment Gateway Integration.
 *
 * Scaffolded by the OwnPay developer CLI tool.
 * Enforces compliance with strict PCI-DSS, ISO-27001 guidelines, and OwnPay ledger constraints.
 *
 * SECURE DEVELOPMENT INVARIANTS (Developer Guide):
 * 1. Sanitized SQL: Always use parameterized queries for database persistence. NO raw string interpolation.
 * 2. Timing-Safe Verifications: Always verify API responses and webhook headers with timing-safe methods.
 * 3. Secure Webhooks: Never rely solely on request parameters for transaction statuses. Perform outbound
 *    direct querying back to the gateway server to confirm invoice states before updates.
 */
final class {{STUDLY_SLUG}}Gateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    /**
     * Returns static plugin metadata mapping parameters.
     *
     * Developer Guide: The kernel uses this to index plugin properties dynamically.
     *
     * @return array{name: string, slug: string, version: string, description: string, author: string, type: string}
     */
    public static function metadata(): array
    {
        return [
            'name'        => '{{NAME}}',
            'slug'        => '{{SLUG}}',
            'version'     => '{{VERSION}}',
            'description' => '{{DESCRIPTION}}',
            'author'      => '{{AUTHOR}}',
            'type'        => 'gateway',
        ];
    }

    /**
     * Returns the unique slug identifying the gateway.
     *
     * Developer Guide: Matches route queries to trigger payments.
     *
     * @return string
     */
    public function slug(): string { return '{{SLUG}}'; }

    /**
     * Returns the human-readable gateway brand name.
     *
     * Developer Guide: Displays on client-facing checkout list components.
     *
     * @return string
     */
    public function name(): string { return '{{NAME}}'; }

    /**
     * Returns the adapter release version.
     *
     * Developer Guide: Vital for self-update compatibility checks.
     *
     * @return string
     */
    public function version(): string { return '{{VERSION}}'; }

    /**
     * Returns the short description summary.
     *
     * @return string
     */
    public function description(): string { return '{{DESCRIPTION}}'; }

    /**
     * Declares the system capabilities exposed by the gateway plugin.
     *
     * Developer Guide: Must register GATEWAY capability.
     *
     * @return array<int, Capability>
     */
    public function capabilities(): array
    {
        return [Capability::GATEWAY];
    }

    /**
     * Registers hooks, filters, and dynamic routing actions during kernel boot.
     *
     * Developer Guide: Use to inject custom assets, checkout filters, or callback routing parameters.
     *
     * @param EventManager $events Central event system manager.
     * @param Container $container Central PSR-11 service container.
     * @return void
     */
    public function register(EventManager $events, Container $container): void
    {
        // Custom listeners or UI actions go here
    }

    /**
     * Boots services after core registration processes finish.
     *
     * @param Container $container Service container.
     * @return void
     */
    public function boot(Container $container): void {}

    /**
     * Cleans up resources when plugin is deactivated.
     *
     * @param Container $container Service container.
     * @return void
     */
    public function deactivate(Container $container): void {}

    /**
     * Destructively purges database configurations and credentials schemas.
     *
     * @param Container $container Service container.
     * @return void
     */
    public function uninstall(Container $container): void {}

    /**
     * Formulates config credentials fields rendered dynamically in the Brand setup panel.
     *
     * Developer Guide: Encrypted configurations are saved to settings repository automatically.
     *
     * @return array<int, array{name: string, label: string, type: string, required: bool, default?: mixed, options?: array<string, string>}>
     */
    public function fields(): array
    {
        return [
            ['name' => 'api_key', 'label' => 'Merchant ID / API Key', 'type' => 'text', 'required' => true],
            ['name' => 'secret_key', 'label' => 'API Secret Key', 'type' => 'password', 'required' => true],
            ['name' => 'webhook_secret', 'label' => 'Webhook Secret / Signature Token', 'type' => 'password', 'required' => false],
            ['name' => 'mode', 'label' => 'Gateway Environment', 'type' => 'select', 'options' => ['test' => 'Sandbox Mode', 'live' => 'Production Live'], 'required' => true, 'default' => 'test'],
        ];
    }

    /**
     * Initiates a payment session with the provider.
     *
     * Developer Guide: Triggered when customer completes selection and clicks "Pay Securely".
     *
     * @param array{amount: string, currency: string, trx_id: string, redirect_url: string, cancel_url: string} $params Transaction variables.
     * @param array{api_key: string, secret_key: string, webhook_secret?: string, mode: string} $credentials Decrypted brand credentials.
     * @return array{redirect_url: string|null, session_id: string|null} Direct redirection details.
     */
    public function initiate(array $params, array $credentials): array
    {
        $amount = $params['amount'];
        $currency = strtoupper($params['currency']);

        // Outbound request configuration example:
        // $mode = $credentials['mode'] ?? 'test';
        // $apiUrl = $mode === 'live' ? 'https://api.{{SLUG}}.com/v1/checkout' : 'https://sandbox.{{SLUG}}.com/v1/checkout';
        //
        // $ch = curl_init($apiUrl);
        // curl_setopt_array($ch, [
        //     CURLOPT_POST           => true,
        //     CURLOPT_RETURNTRANSFER => true,
        //     CURLOPT_POSTFIELDS     => http_build_query([
        //         'merchant_id'  => $credentials['api_key'],
        //         'amount'       => $amount,
        //         'currency'     => $currency,
        //         'reference'    => $params['trx_id'],
        //         'return_url'   => $params['redirect_url'],
        //         'cancel_url'   => $params['cancel_url'],
        //     ])
        // ]);
        // $response = curl_exec($ch);
        // ...

        return [
            'redirect_url' => 'https://sandbox.{{SLUG}}.com/pay/mock_checkout_session?ref=' . urlencode($params['trx_id']),
            'session_id'   => 'mock_session_' . bin2hex(random_bytes(8)),
        ];
    }

    /**
     * Verifies payment status securely.
     *
     * SECURITY RULE: Never trust callback parameters. Query the remote API server.
     *
     * @param array<string, mixed> $callbackData Incoming callback/IPN payload fields.
     * @param array{api_key: string, secret_key: string, webhook_secret?: string, mode: string} $credentials Decrypted credentials.
     * @return array{success: bool, gateway_trx_id: string, amount?: string|null, status: string, trx_id?: string}
     */
    public function verify(array $callbackData, array $credentials): array
    {
        $sessionId = $callbackData['session_id'] ?? '';
        if ($sessionId === '') {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'failed'];
        }

        // Always query gateway API directly:
        // $ch = curl_init('https://sandbox.{{SLUG}}.com/v1/payments/' . urlencode($sessionId));
        // ...

        $paymentSucceeded = true; // Replace with actual API validation result

        return [
            'success'        => $paymentSucceeded,
            'gateway_trx_id' => 'TXN_' . strtoupper(bin2hex(random_bytes(6))),
            'amount'         => $callbackData['amount'] ?? null,
            'status'         => $paymentSucceeded ? 'completed' : 'failed',
            'trx_id'         => $callbackData['trx_id'] ?? '',
        ];
    }

    /**
     * Validates incoming webhook signature authentications using timing-safe comparisons.
     *
     * @param string $rawBody Raw POST body payload string.
     * @param array<string, string> $headers Case-insensitive HTTP headers context.
     * @param array{api_key: string, secret_key: string, webhook_secret?: string, mode: string} $credentials Decrypted credentials.
     * @return bool True if authentic.
     */
    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        $webhookSecret = $credentials['webhook_secret'] ?? '';
        if ($webhookSecret === '') {
            return true;
        }

        $signature = $headers['Signature-Key'] ?? $headers['signature-key'] ?? '';
        if ($signature === '') {
            return false;
        }

        // Calculate expected HMAC-SHA256 signature
        $expected = hash_hmac('sha256', $rawBody, $webhookSecret);

        return hash_equals($expected, $signature);
    }

    /**
     * Reverses a processed payment capture transaction.
     *
     * @param string $gatewayTrxId Remote gateway capture transaction ID.
     * @param string $amount Value size to refund.
     * @param array{api_key: string, secret_key: string, webhook_secret?: string, mode: string} $credentials Decrypted credentials.
     * @return array{success: bool, refund_id: string|null, error: string|null}
     */
    public function refund(string $gatewayTrxId, string $amount, array $credentials): array
    {
        // Outbound cURL request to refund endpoint goes here
        // ...

        return [
            'success'   => true,
            'refund_id' => 'RFD_' . bin2hex(random_bytes(4)),
            'error'     => null,
        ];
    }

    /**
     * Checks support for specific dynamic gateway traits.
     *
     * @param string $feature Feature identifier (e.g. refund, recurring).
     * @return bool True if trait is supported.
     */
    public function supports(string $feature): bool
    {
        return match ($feature) {
            'refund'       => true,
            'recurring'    => false,
            'verification' => true,
            default        => false,
        };
    }
}
EOT;
} elseif ($moduleType === 'addon') {
    $capCases = [];
    foreach ($capabilities as $cap) {
        $capCases[] = "            Capability::" . strtoupper($cap) . ",";
    }
    $capCasesStr = implode("\n", $capCases);

    $phpContent = <<<EOT
<?php
declare(strict_types=1);

namespace {{NAMESPACE}};

use OwnPay\Container;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * {{NAME}} Addon Module.
 *
 * Scaffolded by the OwnPay developer CLI tool.
 * Aligned with PSR-4 structures and PSR-11 service injection layers.
 */
final class Plugin implements PluginInterface
{
    private array \$settings = [];
    private ?Container \$container = null;

    /**
     * Returns static plugin metadata mapping parameters.
     *
     * @return array{name: string, slug: string, version: string, description: string, author: string, type: string}
     */
    public static function metadata(): array
    {
        return [
            'name'        => '{{NAME}}',
            'slug'        => '{{SLUG}}',
            'version'     => '{{VERSION}}',
            'description' => '{{DESCRIPTION}}',
            'author'      => '{{AUTHOR}}',
            'type'        => 'addon',
        ];
    }

    /**
     * Declares the system capabilities exposed by the addon plugin.
     *
     * @return array<int, Capability>
     */
    public function capabilities(): array
    {
        return [
{$capCasesStr}
        ];
    }

    /**
     * Registers actions, filters, and transaction listeners on boot.
     *
     * @param EventManager \$events Central event manager.
     * @param Container \$container central PSR-11 container.
     * @return void
     */
    public function register(EventManager \$events, Container \$container): void
    {
        \$events->addAction('payment.transaction.completed', [\$this, 'onCompleted'], 15);
        \$events->addAction('payment.transaction.failed', [\$this, 'onFailed'], 15);
    }

    /**
     * Boots the plugin after basic registrations complete.
     *
     * @param Container \$container central PSR-11 container.
     * @return void
     */
    public function boot(Container \$container): void
    {
        \$this->container = \$container;
        
        // Fetch secure configurations from the settings repository
        if (\$container->has(\OwnPay\Repository\SettingsRepository::class)) {
            \$repo = \$container->get(\OwnPay\Repository\SettingsRepository::class);
            \$this->settings = \$repo->getGroup('plugin.{{SLUG}}');
        }
    }

    /**
     * Suspend/Deactivate operations gracefully.
     *
     * @param Container \$container central PSR-11 container.
     * @return void
     */
    public function deactivate(Container \$container): void {}

    /**
     * Destructively remove database settings groups and related schemas.
     *
     * @param Container \$container central PSR-11 container.
     * @return void
     */
    public function uninstall(Container \$container): void
    {
        if (\$container->has(\OwnPay\Repository\SettingsRepository::class)) {
            \$repo = \$container->get(\OwnPay\Repository\SettingsRepository::class);
            \$repo->deleteGroup('plugin.{{SLUG}}');
        }
    }

    /**
     * Formulates config settings fields rendered in the Admin Dashboard.
     *
     * @return array<int, array{name: string, label: string, type: string, required: bool, default?: mixed}>
     */
    public function fields(): array
    {
        return [
            [
                'name'    => 'enabled',
                'label'   => 'Enable Module',
                'type'    => 'toggle',
                'default' => '0',
            ],
            [
                'name'    => 'service_url',
                'label'   => 'Third-party API endpoint',
                'type'    => 'text',
                'default' => '',
                'help'    => 'Target address for external processing.',
            ],
            [
                'name'    => 'api_token',
                'label'   => 'Authentication Token',
                'type'    => 'password',
                'default' => '',
                'help'    => 'Confidential access token credentials.',
            ],
        ];
    }

    /**
     * Handles payment completion hook events.
     *
     * @param array \$txn Transaction record columns payload.
     * @return void
     */
    public function onCompleted(array \$txn): void
    {
        if (empty(\$this->settings['enabled']) || \$this->settings['enabled'] === '0') {
            return;
        }

        // Code transaction alerts or double-entry audit alerts here
    }

    /**
     * Handles payment failure hook events.
     *
     * @param array \$txn Transaction record.
     * @return void
     */
    public function onFailed(array \$txn): void
    {
        if (empty(\$this->settings['enabled']) || \$this->settings['enabled'] === '0') {
            return;
        }
    }

    /**
     * Exposes a webhook callback handler route endpoint.
     *
     * @param Request \$req Incoming HTTP request.
     * @return Response JSON output handler.
     */
    public function handleWebhook(Request \$req): Response
    {
        \$body = \$req->jsonBody();
        
        return Response::json(['ok' => true, 'payload_size' => count(\$body)]);
    }
}
EOT;
} else {
    // Theme scaffold options based on template engine selection (PHP or Twig)
    if ($themeEngine === 'php') {
        $phpContent = <<<'EOT'
<?php
declare(strict_types=1);

namespace {{NAMESPACE}};

use OwnPay\Container;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Event\EventManager;

/**
 * {{NAME}} Checkout Theme Scaffolder.
 *
 * Implements clean, secure PHP template views. Enregisters a custom Twig function
 * to bridge PHP view templates safely with the core framework pipeline.
 */
final class Theme implements PluginInterface
{
    /**
     * Returns static plugin metadata mapping parameters.
     *
     * @return array{name: string, slug: string, version: string, description: string, author: string, type: string}
     */
    public static function metadata(): array
    {
        return [
            'name'        => '{{NAME}}',
            'slug'        => '{{SLUG}}',
            'version'     => '{{VERSION}}',
            'description' => '{{DESCRIPTION}}',
            'author'      => '{{AUTHOR}}',
            'type'        => 'theme',
        ];
    }

    /**
     * Declares the system capabilities exposed by the theme.
     *
     * @return array<int, Capability>
     */
    public function capabilities(): array
    {
        return [Capability::THEME];
    }

    /**
     * Registers actions, filters, and template override callbacks during kernel boot.
     *
     * Developer Guide: PHP layout pages are loaded via namespaced render_php function.
     *
     * @param EventManager $events Central event system manager.
     * @param Container $container central service injection container.
     * @return void
     */
    public function register(EventManager $events, Container $container): void
    {
        // 1. Override checkout template wrappers
        $events->addFilter('checkout.template', function (string $template): string {
            return 'checkout/checkout.twig';
        });

        $events->addFilter('checkout.status.template', function (string $template): string {
            return 'checkout/checkout-status.twig';
        });

        $events->addFilter('checkout.payment_link.template', function (string $template): string {
            return 'checkout/payment-link-amount.twig';
        });

        // 2. Register custom PHP view renderer extension into the Twig engine context
        $events->addAction('checkout.before', function (array $txn) use ($container): void {
            $twig = $container->get(\Twig\Environment::class);
            
            // Register a custom function 'render_php' dynamically if not already present
            try {
                $twig->addFunction(new \Twig\TwigFunction('render_php', function (string $file, array $context = []) {
                    extract($context, EXTR_SKIP);
                    ob_start();
                    try {
                        include __DIR__ . '/templates/' . $file;
                        return ob_get_clean();
                    } catch (\Throwable $e) {
                        ob_end_clean();
                        throw $e;
                    }
                }));
            } catch (\LogicException) {
                // Already registered in a concurrent execution thread
            }
        });

        // 3. Enqueue theme style and script assets
        $events->addAction('checkout.head', function (): void {
            echo '<link rel="stylesheet" href="/assets/css/checkout.css">';
        });

        $events->addAction('checkout.footer', function (): void {
            echo '<script src="/assets/js/op-fetch.js"></script>';
            echo '<script src="/assets/js/checkout.js"></script>';
        });
    }

    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    /**
     * Formulates config branding fields rendered inside the brand system dashboard.
     *
     * @return array<int, array{name: string, label: string, type: string, default?: mixed}>
     */
    public function fields(): array
    {
        return [
            [
                'name'    => 'primary_color',
                'label'   => 'Primary Theme Color',
                'type'    => 'color',
                'default' => '#0F172A',
            ],
            [
                'name'    => 'accent_color',
                'label'   => 'Accent Interactive Color',
                'type'    => 'color',
                'default' => '#3B82F6',
            ],
            [
                'name'    => 'custom_footer_note',
                'label'   => 'Footer Details Note',
                'type'    => 'text',
                'default' => 'Secured via 256-bit SSL bank-grade encryption',
            ],
        ];
    }
}
EOT;
    } else {
        $phpContent = <<<'EOT'
<?php
declare(strict_types=1);

namespace {{NAMESPACE}};

use OwnPay\Container;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Event\EventManager;

/**
 * {{NAME}} checkout theme adapter.
 *
 * Scaffolds filters to override default template directories and enqueue assets.
 */
final class Theme implements PluginInterface
{
    /**
     * Returns static plugin metadata mapping parameters.
     *
     * @return array{name: string, slug: string, version: string, description: string, author: string, type: string}
     */
    public static function metadata(): array
    {
        return [
            'name'        => '{{NAME}}',
            'slug'        => '{{SLUG}}',
            'version'     => '{{VERSION}}',
            'description' => '{{DESCRIPTION}}',
            'author'      => '{{AUTHOR}}',
            'type'        => 'theme',
        ];
    }

    /**
     * Declares the system capabilities exposed by the theme.
     *
     * @return array<int, Capability>
     */
    public function capabilities(): array
    {
        return [Capability::THEME];
    }

    /**
     * Registers filters to override checkout twig pages and register style links.
     *
     * @param EventManager $events Central event system manager.
     * @param Container $container central service container.
     * @return void
     */
    public function register(EventManager $events, Container $container): void
    {
        // 1. Override checkout template paths
        $events->addFilter('checkout.template', function (string $template): string {
            return 'checkout/checkout.twig';
        });

        $events->addFilter('checkout.status.template', function (string $template): string {
            return 'checkout/checkout-status.twig';
        });

        $events->addFilter('checkout.payment_link.template', function (string $template): string {
            return 'checkout/payment-link-amount.twig';
        });

        // 2. Enqueue checkout layout assets
        $events->addAction('checkout.head', function (): void {
            echo '<link rel="stylesheet" href="/assets/css/checkout.css">';
        });

        $events->addAction('checkout.footer', function (): void {
            echo '<script src="/assets/js/op-fetch.js"></script>';
            echo '<script src="/assets/js/checkout.js"></script>';
        });
    }

    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}

    /**
     * Theme custom configuration parameters.
     *
     * @return array<int, array{name: string, label: string, type: string, default?: mixed}>
     */
    public function fields(): array
    {
        return [
            [
                'name'    => 'primary_color',
                'label'   => 'Primary Theme Color',
                'type'    => 'color',
                'default' => '#0F172A',
            ],
            [
                'name'    => 'accent_color',
                'label'   => 'Accent Interactive Color',
                'type'    => 'color',
                'default' => '#3B82F6',
            ],
            [
                'name'    => 'custom_footer_note',
                'label'   => 'Footer Details Note',
                'type'    => 'text',
                'default' => 'Secured via 256-bit SSL bank-grade encryption',
            ],
        ];
    }
}
EOT;
    }
}

// Perform variables replacement mapping in code template strings
$phpContent = str_replace(
    ['{{NAMESPACE}}', '{{NAME}}', '{{SLUG}}', '{{VERSION}}', '{{DESCRIPTION}}', '{{AUTHOR}}', '{{STUDLY_SLUG}}'],
    [$namespace, $name, $slug, $version, $description, $author, $studlySlug],
    $phpContent
);

file_put_contents($targetDir . '/' . $entrypointName, $phpContent);
echo C_GREEN . "Done." . C_RESET . "\n";

// Theme-specific templates generation
if ($moduleType === 'theme') {
    echo "Generating Theme asset files... ";
    
    // assets/css/checkout.css
    $cssContent = <<<'EOT'
/*
 * Checkout Stylesheet
 * Pre-styled modern, responsive payment checkout skin.
 */
:root {
    --bg-color: #0b0f19;
    --panel-bg: rgba(22, 27, 42, 0.7);
    --border-color: rgba(255, 255, 255, 0.08);
    --text-primary: #f3f4f6;
    --text-secondary: #9ca3af;
    --primary: #3b82f6;
    --accent: #8b5cf6;
    --glass-blur: blur(16px);
}

body {
    background-color: var(--bg-color);
    color: var(--text-primary);
    font-family: 'Outfit', sans-serif;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    margin: 0;
}

.checkout-card {
    background: var(--panel-bg);
    backdrop-filter: var(--glass-blur);
    border: 1px solid var(--border-color);
    border-radius: 24px;
    padding: 40px;
    max-width: 480px;
    width: 100%;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);
}

.checkout-header {
    text-align: center;
    margin-bottom: 30px;
}

.payment-button {
    background: linear-gradient(135deg, var(--primary) 0%, var(--accent) 100%);
    border: none;
    color: white;
    padding: 14px 28px;
    font-size: 16px;
    font-weight: 600;
    border-radius: 12px;
    cursor: pointer;
    width: 100%;
    transition: transform 0.2s, box-shadow 0.2s;
}

.payment-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(139, 92, 246, 0.4);
}
EOT;
    file_put_contents($targetDir . '/assets/css/checkout.css', $cssContent);

    // assets/js/checkout.js
    $jsContent = <<<'EOT'
/*
 * Checkout Logic Engine
 */
document.addEventListener('DOMContentLoaded', () => {
    console.log('Checkout theme initialized successfully.');
});
EOT;
    file_put_contents($targetDir . '/assets/js/checkout.js', $jsContent);

    // assets/js/op-fetch.js
    $opFetchContent = <<<'EOT'
/*
 * OwnPay Secure Client Fetch Wrapper
 */
async function opFetch(url, options = {}) {
    options.headers = {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(options.headers || {})
    };
    const response = await fetch(url, options);
    return response.json();
}
EOT;
    file_put_contents($targetDir . '/assets/js/op-fetch.js', $opFetchContent);

    // If PHP template engine is requested, generate both PHP layouts and their Twig wrappers
    if ($themeEngine === 'php') {
        // templates/checkout/checkout.twig
        $checkoutTwig = <<<'EOT'
{{ render_php('checkout/checkout.php', _context) | raw }}
EOT;
        file_put_contents($targetDir . '/templates/checkout/checkout.twig', $checkoutTwig);

        // templates/checkout/checkout-status.twig
        $statusTwig = <<<'EOT'
{{ render_php('checkout/checkout-status.php', _context) | raw }}
EOT;
        file_put_contents($targetDir . '/templates/checkout/checkout-status.twig', $statusTwig);

        // templates/checkout/payment-link-amount.twig
        $paymentLinkTwig = <<<'EOT'
{{ render_php('checkout/payment-link-amount.php', _context) | raw }}
EOT;
        file_put_contents($targetDir . '/templates/checkout/payment-link-amount.twig', $paymentLinkTwig);

        // templates/checkout/checkout.php
        $checkoutPhp = <<<'EOT'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout | <?= htmlspecialchars($brand['name'] ?? 'OwnPay', ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Injection hook for head assets -->
    <?= $this->events->doAction('checkout.head') ?>
</head>
<body>

    <div class="checkout-card">
        <div class="checkout-header">
            <?php if (!empty($brand['logo_path'])): ?>
                <img src="/storage/<?= htmlspecialchars($brand['logo_path'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($brand['name'], ENT_QUOTES, 'UTF-8') ?> Logo" style="max-height: 50px; margin-bottom: 12px;">
            <?php else: ?>
                <h2><?= htmlspecialchars($brand['name'] ?? 'OwnPay', ENT_QUOTES, 'UTF-8') ?></h2>
            <?php endif; ?>
            <p>Order Reference: <strong><?= htmlspecialchars($transaction['trx_id'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong></p>
        </div>

        <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); padding: 20px; border-radius: 14px; margin-bottom: 24px; text-align: center;">
            <span style="font-size: 14px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px;">Amount to Pay</span>
            <h1 style="font-size: 32px; font-weight: 700; margin: 6px 0 0 0; color: #ffffff;"><?= htmlspecialchars($transaction['currency'] ?? 'BDT', ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($transaction['amount'] ?? '0.00', ENT_QUOTES, 'UTF-8') ?></h1>
        </div>

        <form action="/checkout/pay" method="POST">
            <!-- CSRF Protection Field -->
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="trx_id" value="<?= htmlspecialchars($transaction['trx_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-size: 14px; font-weight: 500;">Select Payment Method</label>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <?php if (!empty($gateways)): ?>
                        <?php foreach ($gateways as $gwList): ?>
                            <?php foreach ($gwList as $gw): ?>
                                <label style="display: flex; align-items: center; gap: 12px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 12px; padding: 14px; cursor: pointer; transition: background 0.2s;">
                                    <input type="radio" name="gateway" value="<?= htmlspecialchars($gw['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                    <?php if (!empty($gw['logo'])): ?>
                                        <img src="/storage/<?= htmlspecialchars($gw['logo'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($gw['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" style="max-height: 24px;">
                                    <?php endif; ?>
                                    <span><?= htmlspecialchars($gw['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <button type="submit" class="payment-button">Pay Securely</button>
        </form>
    </div>

    <!-- Injection hook for footer scripts -->
    <?= $this->events->doAction('checkout.footer') ?>
</body>
</html>
EOT;
        file_put_contents($targetDir . '/templates/checkout/checkout.php', $checkoutPhp);

        // templates/checkout/checkout-status.php
        $statusPhp = <<<'EOT'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Status | <?= htmlspecialchars($brand['name'] ?? 'OwnPay', ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?= $this->events->doAction('checkout.head') ?>
</head>
<body>

    <div class="checkout-card" style="text-align: center;">
        <?php if (($transaction['status'] ?? '') === 'completed'): ?>
            <div style="width: 72px; height: 72px; background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 32px; margin: 0 auto 20px auto;">✓</div>
            <h2 style="color: #ffffff; font-weight: 700;">Payment Successful</h2>
            <p style="color: var(--text-secondary); margin-bottom: 24px;">Thank you! Your transaction has been processed securely.</p>
        <?php else: ?>
            <div style="width: 72px; height: 72px; background: rgba(244, 63, 94, 0.15); border: 1px solid rgba(244, 63, 94, 0.3); color: #f43f5e; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 32px; margin: 0 auto 20px auto;">✗</div>
            <h2 style="color: #ffffff; font-weight: 700;">Payment Failed</h2>
            <p style="color: var(--text-secondary); margin-bottom: 24px;">The transaction could not be completed or was cancelled.</p>
        <?php endif; ?>

        <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px; margin-bottom: 24px; text-align: left; font-size: 14px;">
            <div style="display:flex; justify-content:space-between; margin-bottom:6px;"><span style="color:var(--text-secondary);">Trx ID:</span><strong><?= htmlspecialchars($transaction['trx_id'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div style="display:flex; justify-content:space-between; margin-bottom:6px;"><span style="color:var(--text-secondary);">Amount:</span><strong><?= htmlspecialchars($transaction['currency'] ?? 'BDT', ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($transaction['amount'] ?? '0.00', ENT_QUOTES, 'UTF-8') ?></strong></div>
            <div style="display:flex; justify-content:space-between;"><span style="color:var(--text-secondary);">Gateway:</span><strong><?= htmlspecialchars($transaction['gateway'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></strong></div>
        </div>

        <a href="<?= htmlspecialchars($brand['url'] ?? '#', ENT_QUOTES, 'UTF-8') ?>" class="payment-button" style="display: inline-block; text-decoration: none; line-height: 48px; height: 48px; padding:0;">Back to Merchant</a>
    </div>

    <?= $this->events->doAction('checkout.footer') ?>
</body>
</html>
EOT;
        file_put_contents($targetDir . '/templates/checkout/checkout-status.php', $statusPhp);

        // templates/checkout/payment-link-amount.php
        $paymentLinkPhp = <<<'EOT'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Brand | <?= htmlspecialchars($brand['name'] ?? 'OwnPay', ENT_QUOTES, 'UTF-8') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?= $this->events->doAction('checkout.head') ?>
</head>
<body>

    <div class="checkout-card">
        <div class="checkout-header">
            <h2><?= htmlspecialchars($brand['name'] ?? 'OwnPay', ENT_QUOTES, 'UTF-8') ?></h2>
            <p>Direct invoice payment link</p>
        </div>

        <form action="/pay/<?= htmlspecialchars($payment_link['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>" method="POST">
            <input type="hidden" name="csrf_token" value="<?= csrf_token() ?>">

            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-size: 14px; font-weight: 500;">Enter Amount (<?= htmlspecialchars($payment_link['currency'] ?? 'BDT', ENT_QUOTES, 'UTF-8') ?>)</label>
                <input type="number" name="amount" step="0.01" min="1" placeholder="0.00" required style="width: 100%; box-sizing: border-box; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 12px; padding: 14px; color: #ffffff; font-size: 18px; outline: none; transition: border-color 0.2s;" onfocus="this.style.borderColor='var(--primary)'" onblur="this.style.borderColor='var(--border-color)'">
            </div>

            <button type="submit" class="payment-button">Continue to Checkout</button>
        </form>
    </div>

    <?= $this->events->doAction('checkout.footer') ?>
</body>
</html>
EOT;
        file_put_contents($targetDir . '/templates/checkout/payment-link-amount.php', $paymentLinkPhp);

    } else {
        // Standard Twig templates generation
        // templates/checkout/checkout.twig
        $checkoutTwig = <<<'EOT'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout | {{ brand.name }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Injection hook for head assets -->
    {{ hook('checkout.head') }}
</head>
<body>

    <div class="checkout-card">
        <div class="checkout-header">
            {% if brand.logo_path %}
                <img src="/storage/{{ brand.logo_path }}" alt="{{ brand.name }} Logo" style="max-height: 50px; margin-bottom: 12px;">
            {% else %}
                <h2>{{ brand.name }}</h2>
            {% endif %}
            <p>Order Reference: <strong>{{ transaction.trx_id }}</strong></p>
        </div>

        <div style="background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); padding: 20px; border-radius: 14px; margin-bottom: 24px; text-align: center;">
            <span style="font-size: 14px; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.5px;">Amount to Pay</span>
            <h1 style="font-size: 32px; font-weight: 700; margin: 6px 0 0 0; color: #ffffff;">{{ transaction.currency }} {{ transaction.amount }}</h1>
        </div>

        <form action="/checkout/pay" method="POST">
            <!-- CSRF Protection Field -->
            <input type="hidden" name="csrf_token" value="{{ csrf_token() }}">
            <input type="hidden" name="trx_id" value="{{ transaction.trx_id }}">

            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-size: 14px; font-weight: 500;">Select Payment Method</label>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    {% for gwList in gateways %}
                        {% for gw in gwList %}
                            <label style="display: flex; align-items: center; gap: 12px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 12px; padding: 14px; cursor: pointer; transition: background 0.2s;">
                                <input type="radio" name="gateway" value="{{ gw.slug }}" required>
                                {% if gw.logo %}
                                    <img src="/storage/{{ gw.logo }}" alt="{{ gw.name }}" style="max-height: 24px;">
                                {% endif %}
                                <span>{{ gw.name }}</span>
                            </label>
                        {% endfor %}
                    {% endfor %}
                </div>
            </div>

            <button type="submit" class="payment-button">Pay Securely</button>
        </form>
    </div>

    <!-- Injection hook for footer scripts -->
    {{ hook('checkout.footer') }}
</body>
</html>
EOT;
        file_put_contents($targetDir . '/templates/checkout/checkout.twig', $checkoutTwig);

        // templates/checkout/checkout-status.twig
        $statusTwig = <<<'EOT'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Status | {{ brand.name }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    {{ hook('checkout.head') }}
</head>
<body>

    <div class="checkout-card" style="text-align: center;">
        {% if transaction.status == 'completed' %}
            <div style="width: 72px; height: 72px; background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #10b981; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 32px; margin: 0 auto 20px auto;">✓</div>
            <h2 style="color: #ffffff; font-weight: 700;">Payment Successful</h2>
            <p style="color: var(--text-secondary); margin-bottom: 24px;">Thank you! Your transaction has been processed securely.</p>
        {% else %}
            <div style="width: 72px; height: 72px; background: rgba(244, 63, 94, 0.15); border: 1px solid rgba(244, 63, 94, 0.3); color: #f43f5e; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 32px; margin: 0 auto 20px auto;">✗</div>
            <h2 style="color: #ffffff; font-weight: 700;">Payment Failed</h2>
            <p style="color: var(--text-secondary); margin-bottom: 24px;">The transaction could not be completed or was cancelled.</p>
        {% endif %}

        <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: 12px; padding: 16px; margin-bottom: 24px; text-align: left; font-size: 14px;">
            <div style="display:flex; justify-content:space-between; margin-bottom:6px;"><span style="color:var(--text-secondary);">Trx ID:</span><strong>{{ transaction.trx_id }}</strong></div>
            <div style="display:flex; justify-content:space-between; margin-bottom:6px;"><span style="color:var(--text-secondary);">Amount:</span><strong>{{ transaction.currency }} {{ transaction.amount }}</strong></div>
            <div style="display:flex; justify-content:space-between;"><span style="color:var(--text-secondary);">Gateway:</span><strong>{{ transaction.gateway }}</strong></div>
        </div>

        <a href="{{ brand.url }}" class="payment-button" style="display: inline-block; text-decoration: none; line-height: 48px; height: 48px; padding:0;">Back to Merchant</a>
    </div>

    {{ hook('checkout.footer') }}
</body>
</html>
EOT;
        file_put_contents($targetDir . '/templates/checkout/checkout-status.twig', $statusTwig);

        // templates/checkout/payment-link-amount.twig
        $paymentLinkTwig = <<<'EOT'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pay Brand | {{ brand.name }}</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    {{ hook('checkout.head') }}
</head>
<body>

    <div class="checkout-card">
        <div class="checkout-header">
            <h2>{{ brand.name }}</h2>
            <p>Direct invoice payment link</p>
        </div>

        <form action="/pay/{{ payment_link.id }}" method="POST">
            <input type="hidden" name="csrf_token" value="{{ csrf_token() }}">

            <div style="margin-bottom: 20px;">
                <label style="display: block; margin-bottom: 8px; font-size: 14px; font-weight: 500;">Enter Amount ({{ payment_link.currency }})</label>
                <input type="number" name="amount" step="0.01" min="1" placeholder="0.00" required style="width: 100%; box-sizing: border-box; background: rgba(255,255,255,0.03); border: 1px solid var(--border-color); border-radius: 12px; padding: 14px; color: #ffffff; font-size: 18px; outline: none; transition: border-color 0.2s;" onfocus="this.style.borderColor='var(--primary)'" onblur="this.style.borderColor='var(--border-color)'">
            </div>

            <button type="submit" class="payment-button">Continue to Checkout</button>
        </form>
    </div>

    {{ hook('checkout.footer') }}
</body>
</html>
EOT;
        file_put_contents($targetDir . '/templates/checkout/payment-link-amount.twig', $paymentLinkTwig);
    }
    
    echo C_GREEN . "Done." . C_RESET . "\n";
}

// -------------------------------------------------------------
// Logo / Brand Icon Implementation Guide Prompt (Per rules)
// -------------------------------------------------------------
echo "\n" . C_YELLOW . C_BOLD . "=== Custom Brand Logo Placement Guide ===" . C_RESET . "\n";
echo "1. " . C_BOLD . "Requirement" . C_RESET . ": Enforce white-labeling by adding your custom brand logo file.\n";
echo "2. " . C_BOLD . "Action Needed" . C_RESET . ": Place your logo file inside: " . C_GREEN . "{$slug}/assets/icon.png" . C_RESET . ".\n";
echo "3. " . C_BOLD . "Support Formats" . C_RESET . ": The logo can be in " . C_CYAN . "SVG, PNG, or JPG" . C_RESET . " format.\n";
echo "4. " . C_BOLD . "Manifest Sync" . C_RESET . ": By default, manifest.json maps " . C_CYAN . '"icon": "assets/icon.png"' . C_RESET . ". If your file is different (e.g. `logo.svg`), update the `\"icon\"` tag accordingly.\n\n";

// -------------------------------------------------------------
// Success Reporting & Developer Guidelines Walkthrough
// -------------------------------------------------------------
echo C_GREEN . C_BOLD . "====================================================================" . C_RESET . "\n";
echo C_GREEN . C_BOLD . "🎉 Success! The module \"{$name}\" has been scaffolded perfectly!     " . C_RESET . "\n";
echo C_GREEN . C_BOLD . "====================================================================" . C_RESET . "\n";
echo "Module Directory: " . C_YELLOW . $targetDir . C_RESET . "\n\n";

echo C_BOLD . "Generated Files:" . C_RESET . "\n";
echo "  [✓] " . C_CYAN . "manifest.json" . C_RESET . " (Clean, type-specific manifest format)\n";
echo "  [✓] " . C_CYAN . $entrypointName . C_RESET . " (Strict types & PHPDocs compliant PHP Entrypoint class)\n";

if ($moduleType === 'theme') {
    echo "  [✓] " . C_CYAN . "assets/css/checkout.css" . C_RESET . " (Core theme checkout stylesheet)\n";
    echo "  [✓] " . C_CYAN . "assets/js/checkout.js" . C_RESET . " (DOM interactions script)\n";
    echo "  [✓] " . C_CYAN . "assets/js/op-fetch.js" . C_RESET . " (Secure, AJAX wrapper utility)\n";
    if ($themeEngine === 'php') {
        echo "  [✓] " . C_CYAN . "templates/checkout/checkout.twig" . C_RESET . " (Twig layout engine bridge wrapper)\n";
        echo "  [✓] " . C_CYAN . "templates/checkout/checkout-status.twig" . C_RESET . " (Twig result template bridge wrapper)\n";
        echo "  [✓] " . C_CYAN . "templates/checkout/payment-link-amount.twig" . C_RESET . " (Twig payment form bridge wrapper)\n";
        echo "  [✓] " . C_CYAN . "templates/checkout/checkout.php" . C_RESET . " (Pure PHP modern checkout template view)\n";
        echo "  [✓] " . C_CYAN . "templates/checkout/checkout-status.php" . C_RESET . " (Pure PHP status display view)\n";
        echo "  [✓] " . C_CYAN . "templates/checkout/payment-link-amount.php" . C_RESET . " (Pure PHP invoice link form)\n";
    } else {
        echo "  [✓] " . C_CYAN . "templates/checkout/checkout.twig" . C_RESET . " (Twig checkout HTML structure)\n";
        echo "  [✓] " . C_CYAN . "templates/checkout/checkout-status.twig" . C_RESET . " (Twig result template)\n";
        echo "  [✓] " . C_CYAN . "templates/checkout/payment-link-amount.twig" . C_RESET . " (Twig direct payment form)\n";
    }
}

echo "\n" . C_MAGENTA . C_BOLD . "=== Developer Secure Coding Guidelines Walkthrough ===" . C_RESET . "\n";
echo "1. " . C_BOLD . "Strict Type Enforcement" . C_RESET . ": The entrypoint PHP class begins with " . C_GREEN . "declare(strict_types=1);" . C_RESET . ".\n";
echo "2. " . C_BOLD . "No SQL Concatenation" . C_RESET . ": Enforce parameterization inside all custom repositories or service database queries.\n";
echo "3. " . C_BOLD . "Scoping" . C_RESET . ": Never pull configuration or transactions globally. Scope records via " . C_GREEN . "merchant_id" . C_RESET . " values.\n";
echo "4. " . C_BOLD . "Backchannel Checks" . C_RESET . ": For gateway modules, check checkout state via " . C_GREEN . "CURL" . C_RESET . " requests rather than trusting webhook input arrays directly.\n\n";

echo "To check or register this plugin, log in to the OwnPay master domain Administration panel, go to Appearance / Plugins, click discover, and enable " . C_BOLD . $name . C_RESET . ".\n\n";
