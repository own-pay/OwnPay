<?php

declare(strict_types=1);

namespace OwnPay\Security {
    if (!function_exists('OwnPay\Security\gethostbynamel')) {
        function gethostbynamel(string $hostname): array|false
        {
            return ['8.8.8.8'];
        }
    }
}

namespace Tests\Integration {

    use OwnPay\Core\Database;
    use OwnPay\Service\Payment\CurrencyService;
    use OwnPay\Cron\CurrencyUpdateJob;
    use OwnPay\Service\System\HttpClient;
    use OwnPay\Repository\SettingsRepository;

    class CurrencyIntegrationTest extends IntegrationTestCase
    {
        private Database $db;
        private CurrencyService $currencyService;
        private SettingsRepository $settingsRepo;

        protected function setUp(): void
        {
            parent::setUp();

            if (!static::$dbAvailable) {
                return;
            }

            $this->db = Database::getInstance();

            $this->db->execute("DELETE FROM op_exchange_rates");
            $this->db->execute("DELETE FROM op_currencies");

            $this->db->execute("INSERT INTO op_currencies (code, name, symbol, decimal_places, status) VALUES ('USD', 'US Dollar', '$', 2, 'active')");
            $this->db->execute("INSERT INTO op_currencies (code, name, symbol, decimal_places, status) VALUES ('BDT', 'Bangladeshi Taka', '৳', 2, 'active')");
            $this->db->execute("INSERT INTO op_currencies (code, name, symbol, decimal_places, status) VALUES ('EUR', 'Euro', '€', 2, 'active')");
            $this->db->execute("INSERT INTO op_currencies (code, name, symbol, decimal_places, status) VALUES ('JPY', 'Japanese Yen', '¥', 0, 'active')");

            $this->settingsRepo = new SettingsRepository($this->db);
            $this->settingsRepo->set('general', 'base_currency', 'USD');
            $this->settingsRepo->set('general', 'default_currency', 'USD');
            $this->settingsRepo->set('general', 'currency', 'USD');
            $this->settingsRepo->set('general', 'exchange_rate_mode', 'auto');
            $this->settingsRepo->set('general', 'exchange_rate_api_url', '');

            $this->db->execute("INSERT INTO op_exchange_rates (base_currency, target_currency, rate, source) VALUES ('USD', 'USD', 1.00000000, 'manual')");
            $this->db->execute("INSERT INTO op_exchange_rates (base_currency, target_currency, rate, source) VALUES ('USD', 'BDT', 117.50000000, 'manual')");
            $this->db->execute("INSERT INTO op_exchange_rates (base_currency, target_currency, rate, source) VALUES ('USD', 'EUR', 0.92000000, 'manual')");
            $this->db->execute("INSERT INTO op_exchange_rates (base_currency, target_currency, rate, source) VALUES ('USD', 'JPY', 156.00000000, 'manual')");

            $this->currencyService = new CurrencyService($this->db);
            HttpClient::$mockResponses = null;
        }

        protected function tearDown(): void
        {
            HttpClient::$mockResponses = null;
            parent::tearDown();
        }

        public function testConversions(): void
        {
            $result = $this->currencyService->convert('100.00', 'USD', 'BDT');
            $this->assertSame('11750.00', $result);

            $result2 = $this->currencyService->convert('117.50', 'BDT', 'USD');
            $this->assertSame('1.00', $result2);

            $result3 = $this->currencyService->convert('100.00', 'EUR', 'JPY');
            $this->assertSame('16956', $result3);
        }

        public function testManualRateOverrides(): void
        {
            $this->currencyService->updateExchangeRate('BDT', '120.00000000');
            $result = $this->currencyService->convert('10.00', 'USD', 'BDT');
            $this->assertSame('1200.00', $result);
        }

        public function testAddManualCurrency(): void
        {
            $this->currencyService->upsert('CAD', 'Canadian Dollar', 'C$', 'active', 2);

            $currencies = $this->currencyService->listAll();
            $codes = array_column($currencies, 'code');
            $this->assertContains('CAD', $codes);

            $curDetail = null;
            foreach ($currencies as $c) {
                if ($c['code'] === 'CAD') {
                    $curDetail = $c;
                    break;
                }
            }
            $this->assertNotNull($curDetail);
            $this->assertSame('Canadian Dollar', $curDetail['name']);
            $this->assertSame('C$', $curDetail['symbol']);
            $this->assertSame(2, (int) $curDetail['decimal_places']);
            $this->assertSame('active', $curDetail['status']);
        }

        public function testSyncRatesJobWithFawazAhmedFallback(): void
        {
            $mockJson = json_encode([
                'date' => '2026-05-29',
                'usd' => [
                    'usd' => 1.0,
                    'bdt' => 118.0,
                    'eur' => 0.93,
                    'jpy' => 157.0,
                ]
            ]);

            HttpClient::$mockResponses = [
                'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.json' => [
                    'status' => 200,
                    'body' => $mockJson,
                    'headers' => []
                ]
            ];

            $job = new CurrencyUpdateJob($this->db);
            $res = $job->run(true);

            $this->assertTrue($res['success']);
            $this->assertGreaterThan(0, $res['updated']);

            $currencySvc = new CurrencyService($this->db);
            $this->assertSame('118.00', $currencySvc->convert('1.00', 'USD', 'BDT'));
            $this->assertSame('0.93', $currencySvc->convert('1.00', 'USD', 'EUR'));
        }

        public function testSyncRatesJobWithCloudflareFallback(): void
        {
            $mockJson = json_encode([
                'date' => '2026-05-29',
                'usd' => [
                    'usd' => 1.0,
                    'bdt' => 119.5,
                    'eur' => 0.94,
                    'jpy' => 158.0,
                ]
            ]);

            HttpClient::$mockResponses = [
                'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.json' => [
                    'status' => 404,
                    'body' => 'Not Found',
                    'headers' => []
                ],
                'https://latest.currency-api.pages.dev/v1/currencies/usd.json' => [
                    'status' => 200,
                    'body' => $mockJson,
                    'headers' => []
                ]
            ];

            $job = new CurrencyUpdateJob($this->db);
            $res = $job->run(true);

            $this->assertTrue($res['success']);

            $currencySvc = new CurrencyService($this->db);
            $this->assertSame('119.50', $currencySvc->convert('1.00', 'USD', 'BDT'));
        }

        public function testSyncRatesJobWithCustomUrl(): void
        {
            $this->settingsRepo->set('general', 'exchange_rate_api_url', 'https://my-custom-rates.test/{currency}.json');

            $mockJson = json_encode([
                'date' => '2026-05-29',
                'usd' => [
                    'usd' => 1.0,
                    'bdt' => 121.25,
                    'eur' => 0.95,
                    'jpy' => 159.0,
                ]
            ]);

            HttpClient::$mockResponses = [
                'https://my-custom-rates.test/usd.json' => [
                    'status' => 200,
                    'body' => $mockJson,
                    'headers' => []
                ]
            ];

            $job = new CurrencyUpdateJob($this->db);
            $res = $job->run(true);

            $this->assertTrue($res['success']);

            $currencySvc = new CurrencyService($this->db);
            $this->assertSame('121.25', $currencySvc->convert('1.00', 'USD', 'BDT'));
        }
    }
}
