<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Controller\Api\Mobile\SmsController;
use OwnPay\Core\Database;
use OwnPay\Http\Request;

/**
 * Regression coverage for SmsController::receive(). Added while removing a PHPStan-flagged
 * redundant is_array($msg) check in the message-parsing loop - every $msg is already proven to
 * be an array by the earlier validation loop in the same method (which returns 422 for any
 * non-array entry before parsing ever starts). This test locks in that every valid message in a
 * batch still reaches the parser (none silently dropped) after that check is removed.
 */
final class SmsControllerBatchParsingTest extends IntegrationTestCase
{
    private SmsController $controller;
    private int $brandId = 1;

    protected function setUp(): void
    {
        $_ENV['ENCRYPTION_KEY'] = 'def000003f9ea8d8ce30e46b9a896d84a362243d414a938c0fcfbeea0f46c6ea9b54c86e24687d89dbdf685c4b4a6b26ec588e7d7a188f6a4a6b2eec58ef7d7a';
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $db = Database::getInstance();
        $c = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($c);
        $c->instance(Database::class, $db);
        $c->instance(Container::class, $c);

        $controller = $c->get(SmsController::class);
        $this->assertInstanceOf(SmsController::class, $controller);
        $this->controller = $controller;
    }

    private function receiveBatch(array $messages): array
    {
        $request = new Request([], [], [], [], [], json_encode(['messages' => $messages]));
        $request->setAttribute('merchant_id', $this->brandId);
        $request->setAttribute('device_id', 'zztest-unknown-device-uuid');

        $response = $this->controller->receive($request);
        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        return $body;
    }

    public function testEveryValidMessageInBatchReachesTheParserNoneDropped(): void
    {
        $messages = [
            ['sender' => 'bKash', 'body' => 'You received Tk 100', 'local_id' => 1],
            ['sender' => 'Nagad', 'body' => 'You received Tk 200', 'local_id' => 2],
            ['sender' => 'Rocket', 'body' => 'You received Tk 300', 'local_id' => 3],
        ];

        $body = $this->receiveBatch($messages);

        $this->assertTrue($body['success']);
        $results = $body['data']['results'];
        $this->assertCount(3, $results, 'All 3 valid messages must reach the parser - none silently dropped');
        foreach ($results as $result) {
            $this->assertSame('rejected', $result['status']);
            $this->assertSame('DEVICE_NOT_FOUND', $result['error']);
        }
    }

    public function testSingleMessageStillWorksAfterBatchLoopChange(): void
    {
        $body = $this->receiveBatch([['sender' => 'bKash', 'body' => 'You received Tk 50', 'local_id' => 9]]);

        $this->assertTrue($body['success']);
        $this->assertCount(1, $body['data']['results']);
    }
}
