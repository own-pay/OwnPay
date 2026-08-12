<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/modules/gateways/alipay/AlipayGateway.php';

use OwnPay\Modules\Gateways\Alipay\AlipayGateway;

/**
 * Security regression tests for AlipayGateway (GHSA-f9vm-jrm6-wcvq).
 *
 * These tests assert that the gateway FAILS CLOSED: every code path that does
 * not produce a cryptographically valid RSA signature must return false/failed
 * regardless of any credential mode field or test environment assumption.
 */
class AlipayGatewayTest extends TestCase
{
    /**
     * Pre-generated RSA-2048 test private key (PKCS8 PEM format).
     *
     * This static key is used instead of dynamically generating keys with
     * openssl_pkey_new(), which requires OPENSSL_CONF to be set at the OS level
     * and fails in Laragon on Windows without it. openssl_pkey_get_private(),
     * openssl_pkey_get_public(), openssl_sign(), and openssl_verify() all work
     * without OPENSSL_CONF - they only need the key material itself.
     *
     * This key is FOR TESTS ONLY and must never be used in production.
     */
    private const TEST_PRIVATE_KEY = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQCxgRueveZO8bMc
8b22ibFcErEgXbd2AVnxcB4gxrd3rb3h9QDwwT2QuXa+XIpPwb7IsyfRXyMYl8Dq
G9WKm8PU2vnHjJf8+dmzE+b2n0+NpqeCvGDw7bKAd7K8rj42PUIJmB84L7BpJ6T+
nYDxXfM8XthM21agedJqmderc4pGhm62KGWnlfSJhRFQhURRX/8IlNAjybneoDHK
gr0CF45gsQ+558KYa1cNhi924YEfhGIvWoEBTYMqPFsRqXLT5SrABpnJsJVksUFv
YT+hP+cYea8X+5J42bHbbVzvD28/Cv+asbCCpsJH6myLUdnEF1yK83ET6ZK7JEw8
seA5MqvFAgMBAAECggEAEnTwyMhJLtM+Axf12ImnugGtjkaAYZJRZmP4lgLjp9uW
f2zI3L/TJX3LZY7cyN9F9bt/O+uPDCsXYaBkS2XX7oWYbFHnqePW6aJ7pRA6pul5
yIPK9rJYmbgYizr4+A3VlIbPVDnNN5nqM5lwA2j39kKMbi1ubABOy0vU25yHd76t
W4b7dRP81eD172vcjxn1VcjSmpobOsMsf9vE7kztNirKPVKHwCVp4gwNcEa2aLWp
zeV+CZaWvYMimtXv1ElN8P0kDOfZoXnneTR3NsvfJThot+VNwHLKOeJXza+DWMOi
v2IM1wPB0dfqR4SV/eXGLEtsntEYO6acFDiqhs2sLwKBgQDezKF10Sj/xlMZv1/u
eQ/Na9tWngHijiucWz3CdyntmRs9jt5Q8SoxfF7UUq/FqgEvqQdER+cQFqgPjB4h
p+tjn6WUizN4TUqEiYfEbEztzN0Zy4KmEKSKzZTV6VMjsBFUKIgh2Gz/ksudjQh5
f0xCgAjhSTMDhJzSyqMXnn90OwKBgQDL9I8btqoxiDQ1k16zaCgOTxYjRY1RbjsY
sT7ml45bmLhsc4y82xJpkI4aKDCBHtfCkqxsczOb7T8zkuXPRpFtVJgN+gbVLQ6u
zl9aqfxZVPHrOL+2fS/sl+baKDD6FYpu+zIFL623mknpewOTh4ozwS/9FXaZjbDA
eOK+7pRf/wKBgQDArO6k2PE/4N9U0O/BZ+iGmdPhoFu49YI5gZ5zYmJcG6A3KCqS
jT0T9p57t9ZSdqb39JFYN2ZXv4Avkdks+e7TBOlJAOjODOl4nQdQkugkbpp3CExA
GPxzHT6tj05a+pTB1xuDSYtZqjV0VtcRW0kz6U3Epmz7id+lW6CXeHZ4qQKBgQCX
AbRvdXhfghP90uYb+TMnHQEsDlQKhY31w03qFy5MvS9XBNqf/aeNR30e+sCm6Tog
ks5c4ZLFkQvpWIsUQ5BrHWYTexpR/bFQVfYdv5vaXF1dpj+zks7H4tQGlBlnIEnF
z/CCDIIXXYH5/51vPrxhg1jPjnOoT1MdvqNLcIOk6wKBgDK34b/UbUqgmKoYw863
4+8b0yyHihdVHn5zXPLXZwvCT8TIqx07DC7Y+mim55U+ReVOvpGsCsH2tSCx34cy
0rQX4jEUmg9VEurdLlpn22kfHuBqXER4sA7Iw5KbMpGi2z98W+eeV2jRRyLhJYSx
FjKYOpUhqgiab6WY3W5JZ6Fq
-----END PRIVATE KEY-----
PEM;

    /**
     * RSA-2048 test public key matching TEST_PRIVATE_KEY.
     * This key is FOR TESTS ONLY and must never be used in production.
     */
    private const TEST_PUBLIC_KEY = <<<'PEM'
-----BEGIN PUBLIC KEY-----
MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAsYEbnr3mTvGzHPG9tomx
XBKxIF23dgFZ8XAeIMa3d6294fUA8ME9kLl2vlyKT8G+yLMn0V8jGJfA6hvVipvD
1Nr5x4yX/PnZsxPm9p9Pjaangrxg8O2ygHeyvK4+Nj1CCZgfOC+waSek/p2A8V3z
PF7YTNtWoHnSapnXq3OKRoZutihlp5X0iYURUIVEUV//CJTQI8m53qAxyoK9AheO
YLEPuefCmGtXDYYvduGBH4RiL1qBAU2DKjxbEaly0+UqwAaZybCVZLFBb2E/oT/n
GHmvF/uSeNmx221c7w9vPwr/mrGwgqbCR+psi1HZxBdcivNxE+mSuyRMPLHgOTKr
xQIDAQAB
-----END PUBLIC KEY-----
PEM;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Builds a canonical Alipay query string from a parameter map
     * (excluding sign and sign_type, sorted by key).
     *
     * @param array<string, string> $params
     */
    private function buildQueryString(array $params): string
    {
        $filtered = [];
        foreach ($params as $k => $v) {
            if ($k !== 'sign' && $k !== 'sign_type' && $v !== '') {
                $filtered[$k] = $v;
            }
        }
        ksort($filtered);
        $parts = [];
        foreach ($filtered as $k => $v) {
            $parts[] = "{$k}={$v}";
        }
        return implode('&', $parts);
    }

    /**
     * Signs a query string with a private key using SHA-256 (RSA2).
     */
    private function signString(string $queryStr, string $privatePem): string
    {
        $keyObj = openssl_pkey_get_private($privatePem);
        $this->assertNotFalse($keyObj);
        $sig = '';
        openssl_sign($queryStr, $sig, $keyObj, OPENSSL_ALGO_SHA256);
        return base64_encode($sig);
    }

    /**
     * Returns base credentials with the given Alipay public key.
     *
     * @return array<string, string>
     */
    private function credsWith(string $publicPem): array
    {
        return ['alipay_public_key' => $publicPem];
    }

    // -----------------------------------------------------------------------
    // fields() - configuration contract
    // -----------------------------------------------------------------------

    public function testAlipayPublicKeyIsRequired(): void
    {
        $gw     = new AlipayGateway();
        $fields = $gw->fields();

        $keyField = null;
        foreach ($fields as $f) {
            if ($f['name'] === 'alipay_public_key') {
                $keyField = $f;
                break;
            }
        }

        $this->assertNotNull($keyField, 'alipay_public_key field must be declared in fields()');
        $this->assertTrue(
            $keyField['required'],
            'alipay_public_key must be required=true (GHSA-f9vm-jrm6-wcvq fix)'
        );
    }

    // -----------------------------------------------------------------------
    // verify() - happy path
    // -----------------------------------------------------------------------

    public function testVerifyAcceptsValidRsa2Signature(): void
    {
        $params = [
            'out_trade_no'  => 'TRX-001',
            'total_amount'  => '99.50',
            'trade_no'      => 'ALIPAY-99',
            'trade_status'  => 'TRADE_SUCCESS',
            'sign_type'     => 'RSA2',
        ];

        $queryStr       = $this->buildQueryString($params);
        $params['sign'] = $this->signString($queryStr, self::TEST_PRIVATE_KEY);

        $result = (new AlipayGateway())->verify($params, $this->credsWith(self::TEST_PUBLIC_KEY));

        $this->assertTrue($result['success'], 'verify() must return success=true for a valid RSA2 signature');
        $this->assertSame('completed', $result['status']);
        $this->assertSame('ALIPAY-99', $result['gateway_trx_id']);
        $this->assertSame('99.50', $result['amount']);
    }

    public function testVerifyAcceptsTradeFinished(): void
    {
        $params = [
            'out_trade_no' => 'TRX-002',
            'trade_no'     => 'ALIPAY-100',
            'trade_status' => 'TRADE_FINISHED',
            'sign_type'    => 'RSA2',
        ];
        $params['sign'] = $this->signString($this->buildQueryString($params), self::TEST_PRIVATE_KEY);

        $result = (new AlipayGateway())->verify($params, $this->credsWith(self::TEST_PUBLIC_KEY));

        $this->assertTrue($result['success']);
        $this->assertSame('completed', $result['status']);
    }

    // -----------------------------------------------------------------------
    // verify() - GHSA-f9vm-jrm6-wcvq: fail-closed paths
    // -----------------------------------------------------------------------

    /**
     * A callback that omits the sign field entirely must be rejected.
     * Previously the insecure else-branch returned verified=true when mode=test.
     */
    public function testVerifyRejectsMissingSign(): void
    {
        $params = [
            'out_trade_no' => 'TRX-003',
            'trade_status' => 'TRADE_SUCCESS',
            'sign_type'    => 'RSA2',
            // no 'sign' field
        ];

        $result = (new AlipayGateway())->verify($params, $this->credsWith(self::TEST_PUBLIC_KEY));

        $this->assertFalse($result['success'], 'verify() must fail when sign is absent');
        $this->assertSame('failed', $result['status']);
    }

    /**
     * A callback whose alipay_public_key credential is empty must be rejected.
     * Previously the insecure else-branch returned verified=true when mode=test.
     */
    public function testVerifyRejectsMissingPublicKey(): void
    {
        $params = [
            'out_trade_no' => 'TRX-004',
            'trade_status' => 'TRADE_SUCCESS',
            'sign_type'    => 'RSA2',
        ];
        $params['sign'] = $this->signString($this->buildQueryString($params), self::TEST_PRIVATE_KEY);

        // Pass empty public key - simulates merchant who never configured the key
        $result = (new AlipayGateway())->verify($params, ['alipay_public_key' => '']);

        $this->assertFalse($result['success'], 'verify() must fail when alipay_public_key is not configured');
        $this->assertSame('failed', $result['status']);
    }

    /**
     * A callback with a sign field that carries a forged/incorrect signature must be rejected.
     */
    public function testVerifyRejectsForgedSignature(): void
    {
        $params = [
            'out_trade_no' => 'TRX-005',
            'trade_status' => 'TRADE_SUCCESS',
            'sign_type'    => 'RSA2',
            'sign'         => base64_encode('not-a-real-signature'),
        ];

        $result = (new AlipayGateway())->verify($params, $this->credsWith(self::TEST_PUBLIC_KEY));

        $this->assertFalse($result['success'], 'verify() must fail for forged RSA signature');
        $this->assertSame('failed', $result['status']);
    }

    /**
     * A callback with a valid signature but non-success trade status must NOT complete.
     */
    public function testVerifyRejectsNonSuccessTradeStatus(): void
    {
        $params = [
            'out_trade_no' => 'TRX-006',
            'trade_status' => 'TRADE_CLOSED',
            'sign_type'    => 'RSA2',
        ];
        $params['sign'] = $this->signString($this->buildQueryString($params), self::TEST_PRIVATE_KEY);

        $result = (new AlipayGateway())->verify($params, $this->credsWith(self::TEST_PUBLIC_KEY));

        $this->assertFalse($result['success'], 'verify() must fail for non-success trade status');
        $this->assertSame('failed', $result['status']);
    }

    /**
     * A callback with a missing out_trade_no must be rejected before signature checks.
     */
    public function testVerifyRejectsMissingTrxId(): void
    {
        $params = [
            // no out_trade_no
            'trade_status' => 'TRADE_SUCCESS',
            'sign'         => base64_encode('anything'),
        ];

        $result = (new AlipayGateway())->verify($params, $this->credsWith(self::TEST_PUBLIC_KEY));

        $this->assertFalse($result['success']);
    }

    /**
     * mode=test credential must NOT bypass signature verification (regression for GHSA-f9vm-jrm6-wcvq).
     */
    public function testVerifyIgnoresModeTestBypass(): void
    {
        $params = [
            'out_trade_no' => 'TRX-007',
            'trade_status' => 'TRADE_SUCCESS',
            'sign_type'    => 'RSA2',
            // deliberately omit sign
        ];

        // Provide mode=test to confirm the old bypass no longer works
        $result = (new AlipayGateway())->verify(
            $params,
            ['alipay_public_key' => self::TEST_PUBLIC_KEY, 'mode' => 'test']
        );

        $this->assertFalse(
            $result['success'],
            'mode=test must NOT bypass RSA signature verification (GHSA-f9vm-jrm6-wcvq regression)'
        );
    }

    // -----------------------------------------------------------------------
    // verifyWebhook() - form-encoded body
    // -----------------------------------------------------------------------

    public function testVerifyWebhookAcceptsValidFormEncodedBody(): void
    {
        $params = [
            'out_trade_no' => 'TRX-008',
            'trade_status' => 'TRADE_SUCCESS',
            'sign_type'    => 'RSA2',
        ];
        $params['sign'] = $this->signString($this->buildQueryString($params), self::TEST_PRIVATE_KEY);

        $rawBody = http_build_query($params);

        $ok = (new AlipayGateway())->verifyWebhook($rawBody, [], $this->credsWith(self::TEST_PUBLIC_KEY));

        $this->assertTrue($ok, 'verifyWebhook() must accept a valid form-encoded payload');
    }

    public function testVerifyWebhookAcceptsValidJsonBody(): void
    {
        $params = [
            'out_trade_no' => 'TRX-009',
            'trade_status' => 'TRADE_SUCCESS',
            'sign_type'    => 'RSA2',
        ];
        $params['sign'] = $this->signString($this->buildQueryString($params), self::TEST_PRIVATE_KEY);

        $rawBody = (string) json_encode($params);

        $ok = (new AlipayGateway())->verifyWebhook($rawBody, [], $this->credsWith(self::TEST_PUBLIC_KEY));

        $this->assertTrue($ok, 'verifyWebhook() must accept a valid JSON payload');
    }

    public function testVerifyWebhookRejectsMissingSignInBody(): void
    {
        $rawBody = http_build_query([
            'out_trade_no' => 'TRX-010',
            'trade_status' => 'TRADE_SUCCESS',
            // no sign
        ]);

        $ok = (new AlipayGateway())->verifyWebhook($rawBody, [], $this->credsWith(self::TEST_PUBLIC_KEY));

        $this->assertFalse($ok, 'verifyWebhook() must reject a payload missing the sign field');
    }

    public function testVerifyWebhookRejectsEmptyPublicKey(): void
    {
        $params = ['out_trade_no' => 'TRX-011', 'sign_type' => 'RSA2'];
        $params['sign'] = $this->signString($this->buildQueryString($params), self::TEST_PRIVATE_KEY);

        $rawBody = http_build_query($params);

        $ok = (new AlipayGateway())->verifyWebhook($rawBody, [], ['alipay_public_key' => '']);

        $this->assertFalse($ok, 'verifyWebhook() must reject when alipay_public_key is not configured');
    }

    public function testVerifyWebhookRejectsEmptyBody(): void
    {
        $ok = (new AlipayGateway())->verifyWebhook('', [], $this->credsWith(self::TEST_PUBLIC_KEY));

        $this->assertFalse($ok, 'verifyWebhook() must reject an empty body');
    }
}
