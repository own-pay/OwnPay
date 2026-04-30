<?php
declare(strict_types=1);

namespace OwnPay\Controller\Checkout;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Payment\PaymentService;
use OwnPay\Event\EventManager;

/**
 * Handles gateway-specific payment flow after gateway selection.
 */
final class PaymentCheckoutController
{
    private Container $c;
    private PaymentService $payments;
    private EventManager $events;

    public function __construct(Container $c, PaymentService $payments, EventManager $events)
    {
        $this->c = $c; $this->payments = $payments; $this->events = $events;
    }

    /**
     * POST /checkout/{ref}/pay — processes payment via selected gateway.
     */
    public function process(Request $req, string $ref): Response
    {
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $txn = $db->fetchOne("SELECT * FROM op_transactions WHERE trx_id = :ref AND status = 'pending'", ['ref' => $ref]);
        if (!$txn) return Response::redirect("/checkout/{$ref}/status");

        $gateway = $req->post('gateway', '');
        $this->events->doAction('checkout.gateway.selected', $txn, $gateway);

        try {
            $result = $this->payments->processGateway($txn, $gateway, $req->post());
            if (!empty($result['redirect_url'])) {
                return Response::redirect($result['redirect_url']);
            }
            return Response::redirect("/checkout/{$ref}/status");
        } catch (\Throwable $e) {
            $this->c->get(\OwnPay\Core\Logger::class)->error('Checkout payment failed', ['ref' => $ref, 'error' => $e->getMessage()]);
            return Response::redirect("/checkout/{$ref}?error=payment_failed");
        }
    }
}
