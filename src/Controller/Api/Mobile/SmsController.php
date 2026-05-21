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

/**
 * Class SmsController
 *
 * Handles API actions related to receiving SMS payloads and listing outbound SMS queues for the companion app.
 *
 * @package OwnPay\Controller\Api\Mobile
 */
final class SmsController
{
    /**
     * @var Container The dependency injection container.
     * @phpstan-ignore property.onlyWritten
     */
    private Container $c;

    /**
     * @var SmsParserService The SMS parser service.
     */
    private SmsParserService $parser;

    /**
     * @var CommLogRepository The communication log repository.
     */
    private CommLogRepository $commRepo;

    /**
     * @var EventManager The event manager.
     */
    private EventManager $events;

    /**
     * SmsController constructor.
     *
     * @param Container         $c        The DI container.
     * @param SmsParserService  $parser   The SMS parser service.
     * @param CommLogRepository $commRepo The communication log repository.
     * @param EventManager      $events   The event manager.
     */
    public function __construct(Container $c, SmsParserService $parser, CommLogRepository $commRepo, EventManager $events)
    {
        $this->c        = $c;
        $this->parser   = $parser;
        $this->commRepo = $commRepo;
        $this->events   = $events;
    }

    /**
     * Handles receiving SMS payloads from the mobile companion app.
     * Supports both single SMS and batch arrays.
     *
     * POST /api/mobile/v1/sms/receive
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with receipt details.
     */
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

    /**
     * Lists pending outbound SMS messages waiting to be sent via the mobile app gateway.
     *
     * GET /api/mobile/v1/sms/queue
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with queue list.
     */
    public function queue(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $pending = $this->commRepo->listPendingSms($mid, 20);
        return Response::json(['success' => true, 'queue' => $pending]);
    }
}
