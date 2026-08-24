<?php
declare(strict_types=1);

namespace OwnPay\Gateway;

/**
 * Optional capability for gateway adapters that enforce a minimum chargeable amount
 * (e.g. Stripe rejects charges below its own per-currency floor). Implementing this
 * lets GatewayBridge::minimumChargeAmount() report the limit up front, so the checkout
 * page can hide the gateway for under-minimum transactions instead of offering it and
 * letting the customer hit an API error after selecting it.
 *
 * Adapters that don't implement this are assumed to have no known minimum.
 */
interface MinimumAmountAwareInterface
{
    /**
     * Resolves the minimum chargeable amount for a currency, if known.
     *
     * @param string $currencyLower Lowercase ISO 4217 currency code.
     * @param array<string, mixed> $credentials Decrypted, merchant-configured gateway credentials.
     * @return string|null The minimum amount as a decimal string, or null if none is known.
     */
    public function minimumChargeAmount(string $currencyLower, array $credentials): ?string;
}
