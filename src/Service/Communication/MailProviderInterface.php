<?php
declare(strict_types=1);

namespace OwnPay\Service\Communication;

/**
 * Mail provider interface — plugins implement this for email delivery.
 */
interface MailProviderInterface
{
    /**
     * Provider slug.
     */
    public function slug(): string;

    /**
     * Send email.
     *
     * @param array{to: string, subject: string, body: string, html?: string, from?: string, reply_to?: string, attachments?: array} $message
     * @return array{success: bool, message_id?: string, error?: string}
     */
    public function send(array $message): array;
}
