<?php

declare(strict_types=1);

namespace OwnPay\Service\Payment;

use OwnPay\Core\Database;
use OwnPay\Core\UuidGenerator;
use OwnPay\Repository\TransactionRepository;
use OwnPay\Repository\PaymentIntentRepository;
use OwnPay\Repository\CustomerRepository;
use InvalidArgumentException;
use RuntimeException;

/**
 * PaymentService — orchestrates the payment lifecycle.
 *
 * State machine:
 *   initiated → pending → completed → refunded
 *                       ↘ canceled
 *                       ↘ failed
 *
 * Only forward transitions are allowed. No reverse.
 */
final class PaymentService
{
    /**
     * Valid state transitions: [currentState => [allowedNextStates]].
     */
    private const TRANSITIONS = [
        'initiated' => ['pending', 'canceled', 'failed'],
        'pending' => ['completed', 'canceled', 'failed'],
        'completed' => ['refunded', 'disputed', 'settled'],
        'settled' => ['disputed'],
        'disputed' => ['resolved_won', 'resolved_lost'],
        // Terminal states: canceled, failed, refunded, resolved_won, resolved_lost — no further transitions
    ];

    private Database $db;
    private TransactionRepository $transactions;
    private PaymentIntentRepository $intents;
    private CustomerRepository $customers;
    private LedgerService $ledger;
    private AuditLogger $audit;

    public function __construct(
        ?Database $db = null,
        ?TransactionRepository $transactions = null,
        ?PaymentIntentRepository $intents = null,
        ?CustomerRepository $customers = null,
        ?LedgerService $ledger = null,
        ?AuditLogger $audit = null
    ) {
        $this->db = $db ?? Database::getInstance();
        $this->transactions = $transactions ?? new TransactionRepository();
        $this->intents = $intents ?? new PaymentIntentRepository();
        $this->customers = $customers ?? new CustomerRepository();
        $this->ledger = $ledger ?? new LedgerService();
        $this->audit = $audit ?? new AuditLogger();
    }

    /**
     * Create a payment intent (checkout session).
     *
     * @param int    $merchantId
     * @param string $amount        DECIMAL string, e.g. "1500.0000"
     * @param string $currency      ISO 4217, e.g. "BDT"
     * @param array  $customerInfo  ['name' => ..., 'email' => ..., 'phone' => ...]
     * @param array  $metadata      Arbitrary key-value metadata
     * @param string|null $idempotencyKey
     * @return array The created payment intent row
     */
    public function createIntent(
        int $merchantId,
        string $amount,
        string $currency,
        array $customerInfo,
        array $metadata = [],
        ?string $idempotencyKey = null
    ): array {
        // Validate amount
        if (bccomp($amount, '0', 4) <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }

        // Validate currency (basic check)
        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException('Currency must be a 3-letter ISO 4217 code.');
        }

        return $this->db->transactional(function () use ($merchantId, $amount, $currency, $customerInfo, $metadata, $idempotencyKey) {
            // Find or create customer
            $email = $customerInfo['email'] ?? null;
            $customerId = null;

            if ($email) {
                $customer = $this->customers->findOrCreate(
                    $merchantId,
                    $email,
                    $customerInfo['name'] ?? null,
                    $customerInfo['phone'] ?? null
                );
                $customerId = $customer['id'];
            }

            // Create intent
            $intentId = $this->intents->insert([
                'merchant_id' => $merchantId,
                'customer_id' => $customerId,
                'amount' => $amount,
                'currency' => $currency,
                'customer_info' => json_encode($customerInfo),
                'metadata' => !empty($metadata) ? json_encode($metadata) : null,
                'idempotency_key' => $idempotencyKey,
                'status' => 'initiated',
            ]);

            $intent = $this->intents->findById($intentId);

            // Audit
            $this->audit->log(
                $merchantId,
                'payment_intent.created',
                'payment_intent',
                $intent['public_id'],
                'system',
                'payment_service',
                null,
                ['amount' => $amount, 'currency' => $currency]
            );

            return $intent;
        });
    }

    /**
     * Process a payment: create a transaction from an intent with gateway response.
     *
     * @param int    $intentId       Internal intent ID
     * @param int    $gatewayConfigId Gateway config used
     * @param string $status         Result status: 'completed', 'failed', 'pending'
     * @param array  $gatewayResponse Raw gateway response data
     * @return array The created transaction row
     */
    public function processPayment(
        int $intentId,
        int $gatewayConfigId,
        string $status,
        array $gatewayResponse = []
    ): array {
        $intent = $this->intents->findById($intentId);
        if ($intent === null) {
            throw new InvalidArgumentException("Payment intent #{$intentId} not found.");
        }

        return $this->db->transactional(function () use ($intent, $gatewayConfigId, $status, $gatewayResponse) {
            // Generate unique reference
            $reference = strtoupper(bin2hex(random_bytes(14))); // 28 char hex

            // Create transaction
            $txnId = $this->transactions->insert([
                'merchant_id' => $intent['merchant_id'],
                'payment_intent_id' => $intent['id'],
                'customer_id' => $intent['customer_id'],
                'gateway_config_id' => $gatewayConfigId,
                'reference' => $reference,
                'amount' => $intent['amount'],
                'currency' => $intent['currency'],
                'customer_info' => $intent['customer_info'],
                'gateway_response' => json_encode($gatewayResponse),
                'status' => $status,
            ]);

            $txn = $this->transactions->findById($txnId);

            // Update intent status
            $this->intents->updateStatus($intent['id'], $status === 'completed' ? 'completed' : 'pending');

            // Post ledger entry on successful payment
            if ($status === 'completed') {
                $this->ledger->postPaymentCompleted(
                    (int) $intent['merchant_id'],
                    $txn['public_id'],
                    $intent['amount'],
                    $intent['currency']
                );
            }

            // Audit
            $this->audit->log(
                (int) $intent['merchant_id'],
                'transaction.created',
                'transaction',
                $txn['public_id'],
                'system',
                'payment_service',
                null,
                ['status' => $status, 'amount' => $intent['amount']]
            );

            return $txn;
        });
    }

    /**
     * Transition a transaction to a new status.
     * Enforces the state machine — throws on invalid transition.
     */
    public function transitionStatus(int $txnId, string $newStatus): array
    {
        $txn = $this->transactions->findById($txnId);
        if ($txn === null) {
            throw new InvalidArgumentException("Transaction #{$txnId} not found.");
        }

        $currentStatus = $txn['status'];

        // Validate transition
        $allowed = self::TRANSITIONS[$currentStatus] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            throw new InvalidArgumentException(
                "Invalid state transition: '{$currentStatus}' → '{$newStatus}'. " .
                "Allowed: [" . implode(', ', $allowed) . "]"
            );
        }

        $this->transactions->updateStatus(
            (int) $txn['id'],
            $txn['created_at'],
            $newStatus
        );

        // Audit the transition
        $this->audit->log(
            (int) $txn['merchant_id'],
            'transaction.status_changed',
            'transaction',
            $txn['public_id'],
            'system',
            'payment_service',
            ['status' => $currentStatus],
            ['status' => $newStatus]
        );

        return $this->transactions->findById($txnId);
    }

    /**
     * Get a transaction by its public UUID.
     */
    public function getTransaction(string $publicId): ?array
    {
        return $this->transactions->findByPublicId($publicId);
    }

    /**
     * Get a transaction by its unique reference string.
     */
    public function getTransactionByReference(string $reference): ?array
    {
        return $this->transactions->findByReference($reference);
    }
}
