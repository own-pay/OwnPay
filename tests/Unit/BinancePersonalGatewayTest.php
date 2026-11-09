<?php

declare(strict_types=1);

namespace Tests\Unit {
    use OwnPay\Modules\Gateways\BinancePersonal\BinancePersonalGateway;
    use OwnPay\Service\Payment\GatewayApiService;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    require_once dirname(__DIR__) . '/Fixtures/BinancePersonalHttp.php';
    require_once dirname(__DIR__, 2) . '/modules/gateways/binance-personal/BinancePersonalGateway.php';

    final class BinancePersonalGatewayTest extends TestCase
    {
        public static array $responses = [];
        private const HASH = '0x' . 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        private const WALLET = '0x1111111111111111111111111111111111111111';

        private function verify(array $overrides = [], string $status = '1', string $wallet = self::WALLET): array
        {
            self::$responses = [json_encode(['result' => array_replace([
                'hash' => self::HASH, 'to' => self::WALLET, 'input' => '0x',
                'value' => '0xde0b6b3a7640000', 'blockNumber' => '0x123',
            ], $overrides)]), json_encode(['result' => ['status' => $status]])];
            return (new BinancePersonalGateway())->verify(
                ['txhash' => self::HASH, 'amount' => '99999', 'trx_id' => 'order-1'],
                ['wallet_address' => $wallet, 'bscscan_api_key' => 'synthetic', 'mode' => 'live']
            );
        }

        public function testReturnsChainValueInsteadOfCallbackClaim(): void
        {
            $result = $this->verify();
            self::assertTrue($result['success']);
            self::assertSame('1.000000000000000000', $result['amount']);
            self::assertSame('BNB', $result['currency']);
            $dust = $this->verify(['value' => '0x1']);
            self::assertSame('0.000000000000000001', $dust['amount']);
        }

        public function testRejectsZeroInvalidPendingWrongRecipientAndFailedTransactions(): void
        {
            foreach ([['value' => '0x0'], ['value' => 'garbage'], ['value' => null],
                ['blockNumber' => null], ['hash' => '0xwrong'],
                ['to' => '0x2222222222222222222222222222222222222222', 'input' => self::WALLET],
            ] as $tx) {
                self::assertFalse($this->verify($tx)['success']);
            }
            self::assertFalse($this->verify([], '0')['success']);
            self::assertFalse($this->verify([], '1', '')['success']);
        }

        #[DataProvider('storedRequirements')]
        public function testSettlementRequiresStoredBnbAndExactValue(string $expected, string $received, mixed $currency, bool $accepted): void
        {
            $method = new \ReflectionMethod(GatewayApiService::class, 'matchesNativeBnbPayment');
            self::assertSame($accepted, $method->invoke(null, $expected, $received, $currency, 'BNB'));
        }

        public static function storedRequirements(): array
        {
            return [
                ['1.00', '1.000000000000000000', 'BNB', true],
                ['100', '0.000000000000000001', 'BNB', false],
                ['1.009', '1.001', 'BNB', false],
                ['0.000000000000000002', '0.000000000000000001', 'BNB', false],
                ['1', '1', 'USD', false], ['1', '1', null, false],
                ['0', '0', 'BNB', false], ['1e0', '1', 'BNB', false],
                ['1', '1.0000000000000000001', 'BNB', false],
            ];
        }

        public function testConnectionValidationSurvivesMerge(): void
        {
            $gateway = new BinancePersonalGateway();
            self::assertFalse($gateway->testConnection([])['success']);
            self::assertTrue($gateway->testConnection(['wallet_address' => self::WALLET])['success']);
            self::$responses = [json_encode(['status' => '1'])];
            self::assertTrue($gateway->testConnection([
                'wallet_address' => self::WALLET, 'bscscan_api_key' => 'synthetic', 'mode' => 'live',
            ])['success']);
            self::$responses = [json_encode(['status' => '0', 'result' => 'Invalid key'])];
            self::assertFalse($gateway->testConnection([
                'wallet_address' => self::WALLET, 'bscscan_api_key' => 'synthetic', 'mode' => 'live',
            ])['success']);
        }

        public function testRejectsUnsupportedCheckoutAsset(): void
        {
            $gateway = new BinancePersonalGateway();
            self::assertSame(['BNB'], $gateway->supportedCurrencies());
            $this->expectException(\RuntimeException::class);
            $gateway->initiate(['currency' => 'USDT'], []);
        }
    }
}
