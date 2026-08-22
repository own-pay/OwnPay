<?php
declare(strict_types=1);

namespace OwnPay\Security;

/**
 * Per-request CSP nonce holder.
 *
 * Registered as a singleton before the container freezes.
 * SecurityHeadersMiddleware sets the nonce; Twig templates
 * and other consumers read it from here.
 */
final class CspNonce
{
    private string $nonce = '';

    public function set(string $nonce): void
    {
        $this->nonce = $nonce;
    }

    public function get(): string
    {
        return $this->nonce;
    }

    public function __toString(): string
    {
        return $this->nonce;
    }
}
