<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Service\Payment\PaymentService;
use OwnPay\Service\Payment\CurrencyService;

// Boot dynamic environment
$_ENV['APP_ENV'] = 'development';
$_ENV['DB_HOST'] = $_ENV['DB_HOST'] ?? '127.0.0.1';
$_ENV['DB_PORT'] = $_ENV['DB_PORT'] ?? '3306';
$_ENV['DB_DATABASE'] = $_ENV['DB_DATABASE'] ?? 'ownpay';
$_ENV['DB_USERNAME'] = $_ENV['DB_USERNAME'] ?? 'root';
$_ENV['DB_PASSWORD'] = $_ENV['DB_PASSWORD'] ?? '';

// Read .env if exists
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->safeLoad();
}

$container = new Container();
$container->instance(Container::class, $container);
// Boot standard services
$registerServices = require __DIR__ . '/config/services.php';
$registerServices($container);

$db = $container->get(Database::class);

// Find first active merchant
$merchant = $db->fetchOne("SELECT id, name FROM op_merchants LIMIT 1");
if (!$merchant) {
    echo "No merchants found in database!\n";
    exit(1);
}
$mid = (int) $merchant['id'];
echo "Found active Brand/Store: {$merchant['name']} (ID: {$mid})\n";

// Let's ensure the database has required currency seeds for the test to work
$currencyCount = (int) $db->fetchOne("SELECT COUNT(*) as c FROM op_currencies")['c'];
if ($currencyCount === 0) {
    echo "Seeding op_currencies...\n";
    $db->execute("INSERT INTO `op_currencies` (`code`, `name`, `symbol`, `decimal_places`, `status`) VALUES
        ('BDT', 'Bangladeshi Taka', '৳', 2, 'active'),
        ('USD', 'US Dollar', '$', 2, 'active'),
        ('EUR', 'Euro', '€', 2, 'active'),
        ('GBP', 'British Pound', '£', 2, 'active'),
        ('INR', 'Indian Rupee', '₹', 2, 'active')");
}

$ratesCount = (int) $db->fetchOne("SELECT COUNT(*) as c FROM op_exchange_rates")['c'];
if ($ratesCount === 0) {
    echo "Seeding op_exchange_rates...\n";
    $db->execute("INSERT INTO `op_exchange_rates` (`base_currency`, `target_currency`, `rate`, `source`) VALUES
        ('BDT', 'USD', 0.00833, 'manual'),
        ('BDT', 'EUR', 0.00769, 'manual'),
        ('BDT', 'GBP', 0.00658, 'manual'),
        ('BDT', 'INR', 0.69444, 'manual'),
        ('USD', 'BDT', 120.00000, 'manual'),
        ('EUR', 'BDT', 130.00000, 'manual'),
        ('GBP', 'BDT', 152.00000, 'manual')");
}

// Ensure general/base_currency is set to USD for test predictability
$baseCurrencyExists = $db->fetchOne("SELECT id FROM op_system_settings WHERE group_name = 'general' AND key_name = 'base_currency'");
if (!$baseCurrencyExists) {
    echo "Setting base_currency to USD...\n";
    $db->execute("INSERT INTO `op_system_settings` (`group_name`, `key_name`, `value`, `type`) VALUES ('general', 'base_currency', 'USD', 'string')");
}

// Seed manual MFS and API gateways for checkout visibility
$manualCount = (int) $db->fetchOne("SELECT COUNT(*) as c FROM op_manual_gateways WHERE merchant_id = :mid", ['mid' => $mid])['c'];
if ($manualCount === 0) {
    echo "Seeding op_manual_gateways...\n";
    $db->execute("INSERT INTO `op_manual_gateways` (`merchant_id`, `slug`, `name`, `logo_path`, `colors`, `input_fields`, `instructions`, `currency`, `status`) VALUES
        (:mid, 'bkash-manual', 'bKash Manual', 'https://ownpay.test/assets/images/bkash.png', '{\"primary\":\"#D12053\"}', '[{\"name\":\"payment_number\",\"type\":\"payment_number\",\"default\":\"01700000000\"}]', '[\"Go to Cash Out\",\"Enter number 01700000000\",\"Enter amount\"]', 'BDT', 'active'),
        (:mid, 'nagad-manual', 'Nagad Manual', 'https://ownpay.test/assets/images/nagad.png', '{\"primary\":\"#F05A24\"}', '[{\"name\":\"payment_number\",\"type\":\"payment_number\",\"default\":\"01800000000\"}]', '[\"Go to Send Money\",\"Enter number 01800000000\",\"Enter amount\"]', 'BDT', 'active')", ['mid' => $mid]);
}

$gatewayExists = $db->fetchOne("SELECT id FROM op_gateways WHERE slug = 'bkash-api'");
if (!$gatewayExists) {
    echo "Seeding op_gateways bkash-api...\n";
    $db->execute("INSERT INTO `op_gateways` (`slug`, `name`, `type`, `status`) VALUES ('bkash-api', 'bKash API', 'api', 'active')");
}
$gatewayId = (int) $db->fetchOne("SELECT id FROM op_gateways WHERE slug = 'bkash-api'")['id'];

$configExists = $db->fetchOne("SELECT id FROM op_gateway_configs WHERE merchant_id = :mid AND gateway_id = :gid", ['mid' => $mid, 'gid' => $gatewayId]);
if (!$configExists) {
    echo "Seeding op_gateway_configs for bkash-api...\n";
    $db->execute("INSERT INTO `op_gateway_configs` (`merchant_id`, `gateway_id`, `status`) VALUES (:mid, :gid, 'active')", ['mid' => $mid, 'gid' => $gatewayId]);
}

// API Key validation is bypassed for direct simulation since we manually bind merchant_id attribute.
echo "Bypassing Bearer Auth for direct request simulation...\n";

// DB Diagnostics for Gateways
$mGws = $db->fetchAll("SELECT * FROM op_manual_gateways");
echo "DB op_manual_gateways count: " . count($mGws) . "\n";
foreach ($mGws as $g) {
    echo "  - Slug: {$g['slug']}, Merchant: {$g['merchant_id']}, Status: {$g['status']}\n";
}

$aGws = $db->fetchAll("SELECT gc.*, g.slug FROM op_gateway_configs gc JOIN op_gateways g ON g.id = gc.gateway_id");
echo "DB op_gateway_configs count: " . count($aGws) . "\n";
foreach ($aGws as $g) {
    echo "  - Slug: {$g['slug']}, Merchant: {$g['merchant_id']}, Status: {$g['status']}\n";
}

// Check currency service conversion
$currSvc = $container->get(CurrencyService::class);
echo "\n--- CURRENCY SERVICE --- \n";
echo "Supported currencies: " . implode(', ', $currSvc->supported()) . "\n";

// DB Diagnostics
$baseCurrencySetting = $db->fetchOne("SELECT value FROM op_system_settings WHERE group_name = 'general' AND key_name = 'base_currency'");
echo "Base currency in setting: " . ($baseCurrencySetting['value'] ?? 'not set') . "\n";

$rates = $db->fetchAll("SELECT * FROM op_exchange_rates");
echo "Loaded rates from DB: \n";
foreach ($rates as $r) {
    echo "  - {$r['base_currency']} -> {$r['target_currency']}: {$r['rate']}\n";
}

try {
    $usdToBdt = $currSvc->convert('100.00', 'USD', 'BDT');
    echo "USD to BDT conversion check: 100.00 USD = {$usdToBdt} BDT\n";
} catch (\Throwable $e) {
    echo "Conversion failed: " . $e->getMessage() . "\n";
}

echo "\n--- PAYMENTS INITIATION TEST ---\n";
// Let's perform a local POST simulation on PaymentController via standard Request
$reqData = [
    'amount' => '100.50',
    'currency' => 'USD',
    'reference' => 'ORD-12345',
    'customer_email' => 'test@example.com',
    'callback_url' => 'https://example.com/callback'
];

$req = new \OwnPay\Http\Request(
    [],
    [],
    [
        'REQUEST_METHOD' => 'POST',
        'REQUEST_URI' => '/api/v1/payments/initiate',
        'CONTENT_TYPE' => 'application/json'
    ],
    [],
    [],
    json_encode($reqData)
);
// Bind merchant_id attribute as resolved by BearerAuthMiddleware
$req->setAttribute('merchant_id', $mid);

$controller = $container->get(\OwnPay\Controller\Api\PaymentController::class);
$response = $controller->initiate($req);

echo "Response Status: " . $response->getStatusCode() . "\n";
echo "Response Body: " . $response->getBody() . "\n";

// Load payment intent by token and verify checkouts
$respBody = json_decode($response->getBody(), true);
if (!empty($respBody['token'])) {
    $token = $respBody['token'];
    echo "\n--- CHECKOUT INTENT VIEW TEST ---\n";
    $checkoutReq = new \OwnPay\Http\Request(
        [],
        [],
        [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/checkout/intent/' . $token
        ]
    );
    $checkoutReq->setAttribute('token', $token);
    
    $checkoutController = $container->get(\OwnPay\Controller\Checkout\PaymentIntentCheckoutController::class);
    $checkoutResponse = $checkoutController->show($checkoutReq);
    echo "Checkout Show Status: " . $checkoutResponse->getStatusCode() . "\n";
    $body = $checkoutResponse->getBody();
    echo "Body length: " . strlen($body) . "\n";
    if (!is_dir(__DIR__ . '/scratch')) {
        mkdir(__DIR__ . '/scratch', 0755, true);
    }
    file_put_contents(__DIR__ . '/scratch/checkout_output.html', $body);
    echo "Contains BDT conversion label? " . (str_contains($body, 'BDT') ? 'YES' : 'NO') . "\n";
}
