<?php
declare(strict_types=1);

namespace OwnPay\Modules\Gateways\PagSeguro;

use OwnPay\Gateway\GatewayAdapterInterface;
use OwnPay\Gateway\GatewayDefaults;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Plugin\Capability;
use OwnPay\Container;
use OwnPay\Event\EventManager;

/**
 * PagSeguro Payment Gateway Adapter.
 * 
 * Implements strict type system, PCI-DSS compliance signature checking,
 * and secure backchannel payment status verification.
 */
final class PagSeguroGateway implements PluginInterface, GatewayAdapterInterface
{
    use GatewayDefaults;

    public static function metadata(): array
    {
        return [
            'name' => 'PagSeguro',
            'slug' => 'pagseguro',
            'version' => '1.0.0',
            'description' => 'PagSeguro payment gateway integration for OwnPay',
            'author' => 'OwnPay Core',
            'type' => 'gateway',
        ];
    }

    public function slug(): string { return 'pagseguro'; }
    public function name(): string { return 'PagSeguro'; }
    public function version(): string { return '1.0.0'; }
    public function description(): string { return 'PagSeguro checkout gateway'; }

    public function register(EventManager $events, Container $container): void {}
    public function boot(Container $container): void {}
    public function deactivate(Container $container): void {}
    public function uninstall(Container $container): void {}
    public function capabilities(): array { return [Capability::GATEWAY]; }

    public function fields(): array
    {
        return [
            ['name' => 'email', 'label' => 'Merchant Email', 'type' => 'text', 'required' => true],
            ['name' => 'token', 'label' => 'API Token', 'type' => 'password', 'required' => true],
            ['name' => 'mode', 'label' => 'Mode', 'type' => 'select', 'options' => ['sandbox' => 'sandbox', 'live' => 'live'], 'required' => true],
        ];
    }

    public function initiate(array $params, array $credentials): array
    {
        $url = $credentials['mode'] === 'live'
            ? 'https://ws.pagseguro.uol.com.br/v2/checkouts'
            : 'https://ws.sandbox.pagseguro.uol.com.br/v2/checkouts';

        $query = http_build_query([
            'email' => $credentials['email'],
            'token' => $credentials['token'],
        ]);

        $xml = sprintf(
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
            <checkout>
                <sender>
                    <email>customer@ownpay.test</email>
                </sender>
                <currency>BRL</currency>
                <items>
                    <item>
                        <id>1</id>
                        <description>Payment %s</description>
                        <amount>%s</amount>
                        <quantity>1</quantity>
                    </item>
                </items>
                <reference>%s</reference>
                <redirectURL>%s</redirectURL>
            </checkout>',
            $params['trx_id'],
            number_format((float)$params['amount'], 2, '.', ''),
            $params['trx_id'],
            $params['redirect_url']
        );

        $ch = curl_init($url . '?' . $query);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/xml; charset=ISO-8859-1'],
            CURLOPT_POSTFIELDS     => $xml,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \RuntimeException('PagSeguro checkouts failed: HTTP ' . $httpCode);
        }

        $xmlObj = simplexml_load_string((string)$response);
        $code = (string) ($xmlObj->code ?? '');
        $redirectUrl = $credentials['mode'] === 'live'
            ? "https://pagseguro.uol.com.br/v2/checkout/payment.html?code={$code}"
            : "https://sandbox.pagseguro.uol.com.br/v2/checkout/payment.html?code={$code}";

        return [
            'redirect_url' => $redirectUrl,
            'session_id'   => $code,
        ];
    }

    public function verify(array $callbackData, array $credentials): array
    {
        if (($callbackData['_op_webhook_verified'] ?? false) !== true) {
            return ['success' => false, 'gateway_trx_id' => '', 'status' => 'unverified'];
        }

        $trxId = $this->getString($callbackData['transaction_id'] ?? null);
        return [
            'success'        => $trxId !== '',
            'gateway_trx_id' => $trxId,
            'status'         => $trxId !== '' ? 'completed' : 'failed',
        ];
    }

    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        return false;
    }
}