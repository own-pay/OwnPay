<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Mobile;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Sms\SmsParserService;
use OwnPay\Repository\CommLogRepository;
use OwnPay\Event\EventManager;
use OwnPay\Support\DateHelper;

final class SmsController
{
    /** @phpstan-ignore property.onlyWritten */
    private Container $c;
    private SmsParserService $parser;
    private CommLogRepository $commRepo;
    private EventManager $events;

    public function __construct(Container $c, SmsParserService $parser, CommLogRepository $commRepo, EventManager $events)
    {
        $this->c        = $c;
        $this->parser   = $parser;
        $this->commRepo = $commRepo;
        $this->events   = $events;
    }

    public function receive(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $deviceId = (string) $req->getAttribute('device_id');
        $body = $req->json();

        // Check if batch payload or single
        $isBatch = false;
        $messages = [];

        if (isset($body['messages']) && is_array($body['messages'])) {
            $isBatch = true;
            $messages = $body['messages'];
        } elseif (is_array($body) && isset($body[0])) {
            $isBatch = true;
            $messages = $body;
        } else {
            $messages = [$body];
        }

        if (empty($messages)) {
            return Response::json(['success' => false, 'error' => 'No messages provided'], 422);
        }

        // Validate messages
        foreach ($messages as $msg) {
            if (empty($msg['sender']) || (empty($msg['encrypted_payload']) && empty($msg['body']))) {
                return Response::json(['success' => false, 'error' => 'sender and encrypted_payload/body required'], 422);
            }
        }

        $this->events->doAction('sms.received.before', $body);

        $parsedMessages = [];
        foreach ($messages as $msg) {
            $parsedMessages[] = [
                'local_id'          => isset($msg['local_id']) ? (int) $msg['local_id'] : null,
                'sender'            => $msg['sender'],
                'encrypted_payload' => $msg['encrypted_payload'] ?? $msg['body'] ?? '',
                'received_at'       => $msg['received_at'] ?? DateHelper::now(),
                'device_id'         => $deviceId,
            ];
        }

        $results = $this->parser->processBatch((string) $deviceId, $mid, $parsedMessages);

        $this->events->doAction('sms.received.after', $results);

        if ($isBatch) {
            return Response::json([
                'success' => true,
                'results' => $results,
            ]);
        }

        // For single message compatibility, return top-level keys
        $singleResult = $results[0] ?? [];
        $status = $singleResult['status'] ?? 'rejected';
        $success = ($status === 'accepted' || $status === 'duplicate');

        return Response::json([
            'success'    => $success,
            'status'     => $status,
            'server_ref' => $singleResult['server_ref'] ?? null,
            'error'      => $singleResult['error'] ?? null,
        ]);
    }

    public function queue(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $pending = $this->commRepo->listPendingSms($mid, 20);
        return Response::json(['success' => true, 'queue' => $pending]);
    }
}
