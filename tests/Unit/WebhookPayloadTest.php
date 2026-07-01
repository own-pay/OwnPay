<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use OwnPay\Model\WebhookPayload;

class WebhookPayloadTest extends TestCase
{
    public function testConstruction(): void
    {
        $p = new WebhookPayload(
            gateway: 'stripe',
            merchantId: 42,
            rawBody: '{"type":"payment_intent.succeeded"}',
            headers: ['Stripe-Signature' => 't=123,v1=abc'],
            ip: '10.0.0.1',
        );

        $this->assertSame('stripe', $p->gateway);
        $this->assertSame(42, $p->merchantId);
        $this->assertSame('POST', $p->method);
        $this->assertSame('10.0.0.1', $p->ip);
    }

    public function testJsonParsing(): void
    {
        $p = new WebhookPayload('bkash', 1, '{"status":"completed","txn_id":"TXN123"}', [], '0.0.0.0');
        $json = $p->json();
        $this->assertSame('completed', $json['status']);
        $this->assertSame('TXN123', $json['txn_id']);
    }

    public function testJsonInvalidReturnsEmptyArray(): void
    {
        $p = new WebhookPayload('test', 0, 'not-json', [], '0.0.0.0');
        $this->assertSame([], $p->json());
    }

    public function testHeaderCaseInsensitive(): void
    {
        $p = new WebhookPayload('stripe', 1, '', ['X-Signature' => 'abc123', 'Content-Type' => 'application/json'], '0.0.0.0');
        $this->assertSame('abc123', $p->header('x-signature'));
        $this->assertSame('application/json', $p->header('CONTENT-TYPE'));
        $this->assertNull($p->header('missing'));
    }

    public function testFormData(): void
    {
        $p = new WebhookPayload('sslcommerz', 1, 'tran_id=TXN456&status=VALID&val_id=V789', [], '0.0.0.0');
        $data = $p->formData();
        $this->assertSame('TXN456', $data['tran_id']);
        $this->assertSame('VALID', $data['status']);
    }

    public function testBodyHash(): void
    {
        $body = '{"test":"data"}';
        $p = new WebhookPayload('test', 0, $body, [], '0.0.0.0');
        $this->assertSame(hash('sha256', $body), $p->bodyHash());
    }

    public function testMethodOverride(): void
    {
        $p = new WebhookPayload('test', 0, '', [], '0.0.0.0', 'GET');
        $this->assertSame('GET', $p->method);
    }
}
