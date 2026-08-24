<?php
declare(strict_types=1);

namespace OwnPay\Security;

/**
 * Encapsulates the per-request Content Security Policy (CSP) nonce.
 *
 * Registered as a singleton in the PSR-11 container prior to container freeze,
 * allowing SecurityHeadersMiddleware to set the active request nonce value
 * without mutating container service bindings after kernel boot.
 */
final class CspNonce implements \Stringable
{
    /**
     * @var string The active CSP nonce.
     */
    private string $nonce = '';

    /**
     * Sets the active CSP nonce value for the current request.
     *
     * @param string $nonce
     * @return void
     */
    public function setNonce(string $nonce): void
    {
        $this->nonce = $nonce;
    }

    /**
     * Returns the active CSP nonce value.
     *
     * @return string
     */
    public function getNonce(): string
    {
        return $this->nonce;
    }

    /**
     * String representation for template interpolation.
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->nonce;
    }
}
