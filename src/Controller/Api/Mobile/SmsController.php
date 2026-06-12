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
     * POST /api/mobile/v1/sms
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with receipt details.
     */
    public function receive(Request $req): Response
    {
        $midVal = $req->getAttribute('merchant_id');
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        $deviceIdVal = $req->getAttribute('device_id');
        $deviceId = is_string($deviceIdVal) ? $deviceIdVal : '';
        $body = $req->json();
        $bodyArr = is_array($body) ? $body : [];

        // Check if batch payload or single
        $isBatch = false;
        $messages = [];

        if (isset($bodyArr['messages']) && is_array($bodyArr['messages'])) {
            $isBatch = true;
            $messages = $bodyArr['messages'];
        } elseif (isset($bodyArr[0]) && is_array($bodyArr[0])) {
            $isBatch = true;
            $messages = $bodyArr;
        } else {
            $messages = [$bodyArr];
        }

        if (empty($messages)) {
            return Response::apiError('MESSAGES_REQUIRED', 'No messages provided', 'messages', 422);
        }

        // Validate messages
        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                return Response::apiError('INVALID_MESSAGE_FORMAT', 'Invalid message format', 'messages', 422);
            }
            $senderVal = $msg['sender'] ?? null;
            $encryptedPayloadVal = $msg['encrypted_payload'] ?? null;
            $bodyVal = $msg['body'] ?? null;
            if (empty($senderVal) || (empty($encryptedPayloadVal) && empty($bodyVal))) {
                return Response::apiError('INVALID_MESSAGE_PAYLOAD', 'sender and encrypted_payload/body required', 'messages', 422);
            }
        }

        $this->events->doAction('sms.received.before', $body);

        $parsedMessages = [];
        foreach ($messages as $msg) {
            if (is_array($msg)) {
                $localIdVal = $msg['local_id'] ?? null;
                $localId = (is_int($localIdVal) || is_string($localIdVal)) ? (int) $localIdVal : null;
                $senderVal = $msg['sender'] ?? '';
                $sender = is_string($senderVal) ? $senderVal : '';
                $encryptedPayloadVal = $msg['encrypted_payload'] ?? $msg['body'] ?? '';
                $encryptedPayload = is_string($encryptedPayloadVal) ? $encryptedPayloadVal : '';
                $receivedAtVal = $msg['received_at'] ?? DateHelper::now();
                $receivedAt = is_string($receivedAtVal) ? $receivedAtVal : DateHelper::now();

                $parsedMessages[] = [
                    'local_id'          => $localId,
                    'sender'            => $sender,
                    'encrypted_payload' => $encryptedPayload,
                    'received_at'       => $receivedAt,
                    'device_id'         => $deviceId,
                ];
            }
        }

        $results = $this->parser->processBatch($deviceId, $mid, $parsedMessages);

        $this->events->doAction('sms.received.after', $results);

        if ($isBatch) {
            return Response::apiSuccess($results);
        }

        // For single message compatibility, return top-level keys
        $singleResult = $results[0] ?? [];
        $status = $singleResult['status'] ?? 'rejected';
        $success = ($status === 'accepted' || $status === 'duplicate');

        $data = [
            'status'     => $status,
            'server_ref' => $singleResult['server_ref'] ?? null,
            'error'      => $singleResult['error'] ?? null,
        ];

        return Response::apiSuccess($data, null, $success ? 200 : 400);
    }

    /**
     * Lists pending outbound SMS messages waiting to be sent via the mobile app gateway.
     *
     * GET /api/mobile/v1/sms/queues
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with queue list.
     */
    public function queue(Request $req): Response
    {
        $midVal = $req->getAttribute('merchant_id');
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        $pending = $this->commRepo->listPendingSms($mid, 20);
        return Response::apiSuccess($pending);
    }
}
