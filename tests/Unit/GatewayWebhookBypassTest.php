<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/gateways/amazon-pay/AmazonPayGateway.php';
require_once dirname(__DIR__, 2) . '/modules/gateways/easypaisa/EasypaisaGateway.php';

use OwnPay\Modules\Gateways\AmazonPay\AmazonPayGateway;
use OwnPay\Modules\Gateways\Easypaisa\EasypaisaGateway;

/**
 * Regression tests for the webhook signature-bypass fix.
 *
 * The previous implementations accepted any non-empty signature (Amazon Pay
 * fell through to `return true`; Easypaisa returned true unconditionally),
 * letting an attacker forge paid webhooks. verifyWebhook() must now reject an
 * arbitrary wrong signature.
 */
final class GatewayWebhookBypassTest extends TestCase
{
    public function testAmazonPayRejectsArbitraryWrongSignature(): void
    {
        $gw = new AmazonPayGateway();
        $credentials = ['store_id' => 'amzn_store_456', 'mode' => 'test'];
        $rawBody = '{"checkoutSessionId":"session_100","status":"PAID"}';

        $valid = hash_hmac('sha256', $rawBody, 'amzn_store_456');
        $this->assertTrue($gw->verifyWebhook($rawBody, ['x-amz-pay-signature' => $valid], $credentials));

        // The bug: any non-'invalid_signature' value used to be accepted.
        $this->assertFalse($gw->verifyWebhook($rawBody, ['x-amz-pay-signature' => 'deadbeef'], $credentials));
        $this->assertFalse($gw->verifyWebhook($rawBody, ['x-amz-pay-signature' => str_repeat('a', 64)], $credentials));
        $this->assertFalse($gw->verifyWebhook($rawBody, ['x-amz-pay-signature' => 'invalid_signature'], $credentials));
        $this->assertFalse($gw->verifyWebhook($rawBody, [], $credentials));

        // Tampered body must not match a signature computed for the original.
        $this->assertFalse($gw->verifyWebhook('{"checkoutSessionId":"session_100","status":"PAID","amount":"99999"}', ['x-amz-pay-signature' => $valid], $credentials));
    }

    public function testAmazonPayFailsClosedWithoutSecret(): void
    {
        $gw = new AmazonPayGateway();
        $rawBody = '{"x":"y"}';
        $sig = hash_hmac('sha256', $rawBody, '');
        // Even a signature computed with an empty secret must not authenticate
        // when no secret is configured.
        $this->assertFalse($gw->verifyWebhook($rawBody, ['x-amz-pay-signature' => $sig], []));
    }

    public function testEasypaisaRejectsForgedWebhook(): void
    {
        $gw = new EasypaisaGateway();
        $credentials = ['hash_key' => 'secret_hash_key_123', 'mode' => 'live'];

        $params = ['orderRefNum' => 'OP-1', 'responseCode' => '0000', 'amount' => '100.00'];
        ksort($params);
        $sigString = '';
        foreach ($params as $k => $v) {
            $sigString .= $k . '=' . $v . '&';
        }
        $sigString = rtrim($sigString, '&');
        $validHash = hash_hmac('sha256', $sigString, 'secret_hash_key_123');

        $validBody = json_encode(array_merge($params, ['secureHash' => $validHash]));
        $this->assertIsString($validBody);
        $this->assertTrue($gw->verifyWebhook($validBody, [], $credentials));

        // Forged hash must be rejected in live mode.
        $forgedBody = json_encode(array_merge($params, ['secureHash' => 'forged_hash']));
        $this->assertIsString($forgedBody);
        $this->assertFalse($gw->verifyWebhook($forgedBody, [], $credentials));

        // Missing secureHash must be rejected.
        $noHashBody = json_encode($params);
        $this->assertIsString($noHashBody);
        $this->assertFalse($gw->verifyWebhook($noHashBody, [], $credentials));
    }

    public function testEasypaisaLiveModeRequiresConfiguredKey(): void
    {
        $gw = new EasypaisaGateway();
        // Live mode with no hash_key configured must fail closed.
        $this->assertFalse($gw->verifyWebhook('{"secureHash":"x"}', [], ['mode' => 'live']));
        // Sandbox without a key is permitted (test convenience).
        $this->assertTrue($gw->verifyWebhook('{"secureHash":"x"}', [], ['mode' => 'sandbox']));
    }
}
