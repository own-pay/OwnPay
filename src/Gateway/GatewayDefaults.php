<?php
declare(strict_types=1);

namespace OwnPay\Gateway;

/**
 * Trait providing sensible default implementations for the GatewayAdapterInterface contract.
 *
 * Gateway adapters can import this trait to avoid boilerplate code for features they do not support,
 * progressively overriding only the specific capabilities they need to implement.
 */
/** @phpstan-ignore trait.unused */
trait GatewayDefaults
{
    /**
     * Fallback payment initiation returning a default HTML alert message.
     *
     * @param array{amount: string, currency: string, trx_id: string, redirect_url: string, cancel_url: string, metadata?: array<string, mixed>} $params Core transaction parameters.
     * @param array<string, mixed> $credentials Decrypted, merchant-configured gateway credentials.
     * @return array{redirect_url?: string|null, form_html?: string} Default fallback payload.
     */
    public function initiate(array $params, array $credentials): array
    {
        return [
            'redirect_url' => null,
            'form_html'    => '<div class="op-alert op-alert-warning">This gateway does not support programmatic initiation.</div>',
        ];
    }

    /**
     * Fallback verification indicating failure of transaction lookups.
     *
     * @param array<string, mixed> $callbackData Payload received from the payment provider.
     * @param array<string, mixed> $credentials Decrypted, merchant-configured gateway credentials.
     * @return array{success: bool, gateway_trx_id?: string|null, amount?: string|null, status?: string} Unsuccessful verification state.
     */
    public function verify(array $callbackData, array $credentials): array
    {
        return [
            'success'        => false,
            'gateway_trx_id' => null,
            'amount'         => null,
            'status'         => 'unsupported',
        ];
    }

    /**
     * Default webhook verification that rejects all payloads by default.
     *
     * Gateways must override this to check signatures, check SSL certificates, or execute IPNs.
     *
     * @param string $rawBody The raw HTTP request payload.
     * @param array<string, string> $headers Inbound HTTP request headers.
     * @param array<string, mixed> $credentials Decrypted, merchant-configured gateway credentials.
     * @return bool Always false by default.
     */
    public function verifyWebhook(string $rawBody, array $headers, array $credentials): bool
    {
        return false;
    }

    /**
     * Fallback refund processor indicating refunds are unsupported.
     *
     * @param string $gatewayTrxId The processor's transaction identifier.
     * @param string $amount The numeric amount to refund.
     * @param array<string, mixed> $credentials Decrypted, merchant-configured gateway credentials.
     * @return array{success: bool, refund_id?: string|null, error?: string} Failed refund status.
     */
    public function refund(string $gatewayTrxId, string $amount, array $credentials): array
    {
        return [
            'success'   => false,
            'refund_id' => null,
            'error'     => 'Refunds not supported by this gateway',
        ];
    }

    /**
     * Checks support for standard gateway capabilities, defaulting to false.
     *
     * @param string $feature Name of the capability (e.g., 'refund', 'subscription').
     * @return bool Always false.
     */
    public function supports(string $feature): bool
    {
        return match ($feature) {
            'refund'       => false,
            'recurring'    => false,
            'partial'      => false,
            'verification' => false,
            default        => false,
        };
    }

    /**
     * Default list of supported currencies. An empty array permits any currency.
     *
     * @return string[] Empty array.
     */
    public function supportedCurrencies(): array
    {
        return [];
    }

    /**
     * Converts a decimal amount to integer minor units (e.g. cents) exactly.
     *
     * Validates the input as a plain non-negative decimal string BEFORE any
     * conversion: a (float) round-trip here corrupts large or high-precision
     * amounts (binary floating point cannot represent them exactly) and
     * silently accepts scientific notation, negatives, or non-numeric input.
     *
     * @param mixed $amount The amount as provided by core (numeric-string).
     * @param int $exponent Number of minor-unit digits (2 for cents).
     * @return int The amount expressed in minor units.
     * @throws \InvalidArgumentException When the amount is not a plain non-negative decimal.
     */
    protected function toMinorUnits(mixed $amount, int $exponent = 2): int
    {
        $str = is_scalar($amount) && !is_bool($amount) ? (string) $amount : '';
        if (!preg_match('/^\d{1,13}(\.\d+)?$/', $str) || !is_numeric($str)) {
            throw new \InvalidArgumentException('Invalid payment amount for minor-unit conversion.');
        }
        return (int) bcmul($str, bcpow('10', (string) $exponent, 0), 0);
    }

    /**
     * Normalizes a decimal amount to a fixed-precision string without a float round-trip.
     *
     * @param mixed $amount The amount as provided by core (numeric-string).
     * @param int $decimals Number of decimal places to keep.
     * @return string The normalized amount string.
     * @throws \InvalidArgumentException When the amount is not a plain non-negative decimal.
     */
    protected function toDecimalString(mixed $amount, int $decimals = 2): string
    {
        $str = is_scalar($amount) && !is_bool($amount) ? (string) $amount : '';
        if (!preg_match('/^\d{1,13}(\.\d+)?$/', $str) || !is_numeric($str)) {
            throw new \InvalidArgumentException('Invalid payment amount.');
        }
        return bcadd($str, '0', $decimals);
    }

    /**
     * Reports whether the platform is running in a production environment.
     *
     * Adapters use this to disable their sandbox "simulation accept" callback
     * paths in production, so a gateway accidentally left in a non-live mode
     * cannot complete real transactions from a forged/unconfirmed callback.
     * Defaults to production (fail closed) when APP_ENV is unset.
     *
     * @return bool True when APP_ENV is 'production' (or unset).
     */
    protected function isProductionEnv(): bool
    {
        $env = $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'production';
        return strtolower(is_string($env) ? $env : 'production') === 'production';
    }

    /**
     * Safely cast a mixed value to string.
     */
    protected function getString(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Safely cast a mixed value to int.
     */
    protected function getInt(mixed $value, int $default = 0): int
    {
        return is_scalar($value) ? (int) $value : $default;
    }

    /**
     * Safely cast a mixed value to float.
     */
    protected function getFloat(mixed $value, float $default = 0.0): float
    {
        return is_scalar($value) ? (float) $value : $default;
    }

    /**
     * Safely cast a mixed value to bool.
     */
    protected function getBool(mixed $value, bool $default = false): bool
    {
        return is_scalar($value) ? (bool) $value : $default;
    }

    /**
     * Safely get a nested array value or return an empty array.
     *
     * @param mixed $array
     * @param string|int ...$keys
     * @return array<mixed>
     */
    protected function getArray(mixed $array, string|int ...$keys): array
    {
        $current = $array;
        foreach ($keys as $key) {
            if (!is_array($current) || !isset($current[$key])) {
                return [];
            }
            $current = $current[$key];
        }
        return is_array($current) ? $current : [];
    }
}

