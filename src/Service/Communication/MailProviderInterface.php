<?php
declare(strict_types=1);

namespace OwnPay\Service\Communication;

/**
 * OwnPay Mail Provider Interface.
 *
 * Exposes the interface definitions that external email channel integration plugins
 * must implement to connect with the system's unified communication dispatcher.
 *
 * @package OwnPay\Service\Communication
 */
interface MailProviderInterface
{
    /**
     * Retrieves the unique identifier slug corresponding to the mail provider.
     *
     * @return string The provider's unique slug identifier.
     */
    public function slug(): string;

    /**
     * Dispatches an outbound email message payload.
     *
     * @param array{to: string, subject: string, body: string, html?: string, from?: string, reply_to?: string, attachments?: array<int, array<string, mixed>>} $message The mail payload.
     * @return array{success: bool, message_id?: string, error?: string} Transmission results status block.
     */
    public function send(array $message): array;
}
