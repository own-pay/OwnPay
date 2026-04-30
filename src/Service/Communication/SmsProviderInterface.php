<?php
declare(strict_types=1);

namespace OwnPay\Service\Communication;

/**
 * SMS provider interface — plugins implement this for SMS delivery.
 */
interface SmsProviderInterface
{
    /**
     * Provider slug.
     */
    public function slug(): string;

    /**
     * Send SMS.
     *
     * @return array{success: bool, message_id?: string, error?: string}
     */
    public function send(string $to, string $message, array $options = []): array;

    /**
     * Check delivery status.
     */
    public function status(string $messageId): string;

    /**
     * Get remaining balance/credits.
     */
    public function balance(): ?float;
}
