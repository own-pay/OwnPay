<?php

declare(strict_types=1);

namespace Tests\Integration;

use OwnPay\Container;
use OwnPay\Core\Database;
use OwnPay\Http\Request;
use OwnPay\Controller\Api\CustomerController;
use OwnPay\Service\Customer\CustomerPiiService;

final class CustomerApiIntegrationTest extends IntegrationTestCase
{
    private Database $db;
    private Container $container;
    private CustomerPiiService $pii;
    private CustomerController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        if (!static::$dbAvailable) {
            $this->markTestSkipped('Database not available');
        }

        $_ENV['ENCRYPTION_KEY'] = $_ENV['PII_ENCRYPTION_KEY'] ?? 'cd4c6edf857c4ad19cb41784e849adf79ec3fc20319c28e735bd3fbd801eca33';

        $this->db = Database::getInstance();
        $this->container = new Container();
        $bootstrap = require dirname(__DIR__, 2) . '/config/services.php';
        $bootstrap($this->container);
        $this->container->instance(Database::class, $this->db);

        $this->pii = $this->container->get(CustomerPiiService::class);
        $this->controller = $this->container->get(CustomerController::class);

        $this->db->execute("DELETE FROM op_customers WHERE merchant_id = 99997");
        $this->db->execute("DELETE FROM op_merchants WHERE id = 99997");

        $this->db->execute(
            "INSERT INTO op_merchants (id, uuid, name, slug, email, status, settings)
             VALUES (99997, 'test-merchant-uuid-99997', 'Customer Test Merchant', 'cust-test', 'cust@test.com', 'active', '{}')"
        );
    }

    protected function tearDown(): void
    {
        if (static::$dbAvailable) {
            $this->db->execute("DELETE FROM op_customers WHERE merchant_id = 99997");
            $this->db->execute("DELETE FROM op_merchants WHERE id = 99997");
        }
        parent::tearDown();
    }

    public function testCustomerListReturnsUnmaskedFields(): void
    {
        $email = 'john.doe@example.com';
        $phone = '+8801700000000';
        $name = 'John Doe';

        $customer = $this->pii->create(99997, [
            'name' => $name,
            'email' => $email,
            'phone' => $phone
        ]);

        $this->assertNotNull($customer['id'] ?? null);

        $req = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/customers'
        ]);
        $req->setAttribute('merchant_id', 99997);

        $res = $this->controller->index($req);
        $this->assertSame(200, $res->getStatusCode());

        $body = json_decode($res->getBody(), true);
        $this->assertTrue($body['success']);
        $items = $body['data'];

        $this->assertCount(1, $items);
        $this->assertSame($name, $items[0]['name']);
        $this->assertSame($email, $items[0]['email']);
        $this->assertSame($phone, $items[0]['phone']);
        $this->assertNotEmpty($items[0]['email_masked']);
        $this->assertNotEmpty($items[0]['phone_masked']);
    }

    public function testCustomerShowWorksWithEmailAndPhone(): void
    {
        $email = 'jane.doe@example.com';
        $phone = '+8801711111111';
        $name = 'Jane Doe';

        $this->pii->create(99997, [
            'name' => $name,
            'email' => $email,
            'phone' => $phone
        ]);

        $reqEmail = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/customers/' . $email
        ]);
        $reqEmail->setAttribute('merchant_id', 99997);
        $reqEmail->setRouteParams(['identifier' => $email]);

        $resEmail = $this->controller->show($reqEmail);
        $this->assertSame(200, $resEmail->getStatusCode());
        $bodyEmail = json_decode($resEmail->getBody(), true);
        $this->assertTrue($bodyEmail['success']);
        $this->assertSame($name, $bodyEmail['data']['name']);
        $this->assertSame($email, $bodyEmail['data']['email']);
        $this->assertSame($phone, $bodyEmail['data']['phone']);

        $reqPhone = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/customers/' . urlencode($phone)
        ]);
        $reqPhone->setAttribute('merchant_id', 99997);
        $reqPhone->setRouteParams(['identifier' => urlencode($phone)]);

        $resPhone = $this->controller->show($reqPhone);
        $this->assertSame(200, $resPhone->getStatusCode());
        $bodyPhone = json_decode($resPhone->getBody(), true);
        $this->assertTrue($bodyPhone['success']);
        $this->assertSame($name, $bodyPhone['data']['name']);
        $this->assertSame($email, $bodyPhone['data']['email']);
        $this->assertSame($phone, $bodyPhone['data']['phone']);

        $reqEncodedEmail = new Request([], [], [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/customers/' . urlencode($email)
        ]);
        $reqEncodedEmail->setAttribute('merchant_id', 99997);
        $reqEncodedEmail->setRouteParams(['identifier' => urlencode($email)]);

        $resEncodedEmail = $this->controller->show($reqEncodedEmail);
        $this->assertSame(200, $resEncodedEmail->getStatusCode());
        $bodyEncodedEmail = json_decode($resEncodedEmail->getBody(), true);
        $this->assertTrue($bodyEncodedEmail['success']);
        $this->assertSame($name, $bodyEncodedEmail['data']['name']);
        $this->assertSame($email, $bodyEncodedEmail['data']['email']);
        $this->assertSame($phone, $bodyEncodedEmail['data']['phone']);
    }
}
