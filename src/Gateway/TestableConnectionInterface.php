<?php
declare(strict_types=1);

namespace OwnPay\Gateway;

/**
 * Optional capability for gateway adapters that can verify their own credentials against the
 * provider's API (e.g. a lightweight balance/account lookup) without moving money. Implementing
 * this powers the "Test Connection" button on the gateway's admin settings page.
 *
 * Adapters that don't implement this report as "not supported" by PluginController rather than
 * the button silently failing or being hidden inconsistently across gateways.
 */
interface TestableConnectionInterface
{
    /**
     * Verifies that the given credentials actually work against the provider's API.
     *
     * @param array<string, mixed> $credentials Decrypted (or freshly-submitted, unsaved) credentials.
     * @return array{success: bool, message: string} Human-readable result - message is shown as-is to the admin.
     */
    public function testConnection(array $credentials): array;
}
