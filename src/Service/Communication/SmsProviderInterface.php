<?php
declare(strict_types=1);

namespace OwnPay\Service\Communication;

/**
 * OwnPay SMS Provider Interface.
 *
 * Exposes the interface definitions that external short messaging (SMS) integration
 * plugins must implement to connect with the system's unified communication dispatcher.
 *
 * @package OwnPay\Service\Communication
 */
interface SmsProviderInterface
{
    /**
     * Retrieves the unique identifier slug corresponding to the SMS provider.
     *
     * @return string The provider's unique slug identifier.
     */
    public function slug(): string;

    /**
     * Dispatches an outbound SMS message payload.
     *
     * @param string $to Recipient target telephone number.
     * @param string $message Plain text message content payload.
     * @param array<string, mixed> $options Optional vendor-specific routing parameters.
     * @return array{success: bool, message_id?: string, error?: string} Transmission results status block.
     */
    public function send(string $to, string $message, array $options = []): array;

    /**
     * Queries delivery status of a previously dispatched SMS message.
     *
     * @param string $messageId Vendor message identification string.
     * @return string Status string response.
     */
    public function status(string $messageId): string;

    /**
     * Retrieves the remaining credit/currency balance on the SMS provider account.
     *
     * @return float|null Current remaining balance count, or null if unsupported.
     */
    public function balance(): ?float;
}
