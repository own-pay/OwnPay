<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Mobile;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Sms\SmsParserService;
use OwnPay\Event\EventManager;

/**
 * Mobile SMS API — receive SMS from companion app, return send queue.
 * OWASP: Validate sender format, sanitize body.
 */
final class SmsController
{
    private Container $c;
    private SmsParserService $parser;
    private EventManager $events;

    public function __construct(Container $c, SmsParserService $parser, EventManager $events)
    {
        $this->c = $c;
        $this->parser = $parser;
        $this->events = $events;
    }

    /**
     * POST /api/mobile/v1/sms
     * Body: { sender, body, received_at? }
     */
    public function receive(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $deviceId = (int) $req->getAttribute('device_id');
        $body = $req->jsonBody();

        if (empty($body['sender']) || empty($body['body'])) {
            return Response::json(['success' => false, 'error' => 'sender and body required'], 422);
        }

        $this->events->doAction('sms.received.before', $body);

        $result = $this->parser->parseAndStore($mid, [
            'sender'      => $body['sender'],
            'body'        => $body['body'],
            'received_at' => $body['received_at'] ?? date('Y-m-d H:i:s'),
            'device_id'   => $deviceId,
        ]);

        $this->events->doAction('sms.received.after', $result);

        return Response::json([
            'success'  => true,
            'parsed'   => $result['parsed'] ?? false,
            'matched'  => $result['matched'] ?? false,
            'trx_id'   => $result['trx_id'] ?? null,
        ]);
    }

    /**
     * GET /api/mobile/v1/sms/queue
     * Returns SMS to be sent by the companion app.
     */
    public function queue(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $db = $this->c->get(\OwnPay\Core\Database::class);

        $pending = $db->fetchAll(
            "SELECT id, `to`, body FROM op_comm_log WHERE channel = 'sms' AND status = 'pending' AND merchant_id = :mid ORDER BY created_at ASC LIMIT 20",
            ['mid' => $mid]
        );

        return Response::json(['success' => true, 'queue' => $pending]);
    }
}
