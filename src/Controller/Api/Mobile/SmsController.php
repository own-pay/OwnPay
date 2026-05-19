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
        $deviceId = (int) $req->getAttribute('device_id');
        $body = $req->json();

        if (empty($body['sender']) || empty($body['body'])) {
            return Response::json(['success' => false, 'error' => 'sender and body required'], 422);
        }

        $this->events->doAction('sms.received.before', $body);

        $result = $this->parser->parseAndStore((string) $deviceId, $mid, [
            'sender'      => $body['sender'],
            'body'        => $body['body'],
            'received_at' => $body['received_at'] ?? DateHelper::now(),
            'device_id'   => $deviceId,
        ]);

        $this->events->doAction('sms.received.after', $result);

        return Response::json([
            'success' => true,
            'parsed'  => $result['parsed'] ?? false,
            'matched' => $result['matched'] ?? false,
            'trx_id'  => $result['trx_id'] ?? null,
        ]);
    }

    public function queue(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $pending = $this->commRepo->listPendingSms($mid, 20);
        return Response::json(['success' => true, 'queue' => $pending]);
    }
}
