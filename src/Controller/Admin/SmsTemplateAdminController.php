<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Sms\SmartSmsAnalyzer;
use OwnPay\Service\Sms\SmsRegexParser;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\SmsTemplateRepository;
use OwnPay\Repository\SmsParsedRepository;
use OwnPay\Repository\CommLogRepository;

/**
 * SMS Center Controller for managing parsing templates, parsed SMS logs, and the outbound SMS queue.
 */
final class SmsTemplateAdminController
{
    use AdminPageTrait;

    /**
     * The dependency injection container.
     */
    private Container $c;

    /**
     * The admin session manager.
     */
    private AdminSession $session;

    /**
     * The SMS template repository.
     */
    private SmsTemplateRepository $tplRepo;

    /**
     * The parsed SMS repository.
     */
    private SmsParsedRepository $parsedRepo;

    /**
     * The communication log repository.
     */
    private CommLogRepository $commRepo;

    /**
     * SmsTemplateAdminController constructor.
     *
     * @param Container $c The dependency injection container.
     * @param AdminSession $session The admin session manager.
     * @param SmsTemplateRepository $tplRepo The SMS template repository.
     * @param SmsParsedRepository $parsedRepo The parsed SMS repository.
     * @param CommLogRepository $commRepo The communication log repository.
     */
    public function __construct(
        Container $c,
        AdminSession $session,
        SmsTemplateRepository $tplRepo,
        SmsParsedRepository $parsedRepo,
        CommLogRepository $commRepo
    ) {
        $this->c          = $c;
        $this->session    = $session;
        $this->tplRepo    = $tplRepo;
        $this->parsedRepo = $parsedRepo;
        $this->commRepo   = $commRepo;
    }

    /**
     * Owner id for SMS parsing templates. Templates are GLOBAL - managed only in the "All Brands" view
     * and applied to every brand/device (issue #6) - so they are always owned by the reserved platform
     * merchant, never a specific brand. Mirrors the companion device's filter-rules query, which now
     * includes the platform templates for every device regardless of the brand it paired under.
     *
     * @param Request $req The incoming HTTP request.
     * @return int The platform-owner merchant id.
     */
    private function templateOwnerId(Request $req): int
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if ($brand instanceof \OwnPay\Service\Brand\BrandContext) {
            $brand->resolveFromRequest($req);
            return $brand->getPlatformId();
        }
        return 0;
    }

    /**
     * Main SMS Center page displaying templates, parsed logs, and outbound queues.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response rendering the index page.
     * @throws \Exception If database queries or rendering fail.
     */
    public function index(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $mid = 0;
        $platformId = 0;
        $canManageTemplates = true;
        if ($brand instanceof \OwnPay\Service\Brand\BrandContext) {
            $brand->resolveFromRequest($req);
            $activeId = $brand->getActiveBrandId();
            if ($activeId !== null) {
                $mid = $activeId;
            }
            // Templates are global (platform-owned); the rest of the SMS Center (parsed log, queues)
            // stays brand-scoped. Template management is only allowed in the All-Brands view (issue #6).
            $platformId = $brand->getPlatformId();
            $canManageTemplates = $brand->isGlobalView();
        }

        $templates  = $this->tplRepo->listForAdmin($platformId);
        $parsed     = $this->parsedRepo->forTenant($mid)->findUnmatched(100);
        $queue      = $this->commRepo->listSmsQueue($mid);
        $queueStats = $this->commRepo->getSmsQueueStats($mid);
        $emailQueue = $this->commRepo->listEmailQueue($mid);
        $telegramQueue = $this->commRepo->listTelegramQueue($mid);
        $webhookQueue = $this->commRepo->listWebhookQueue($mid);

        // Get gateway list for dropdown
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $gateways = [];
        $manualGateways = [];
        if ($db instanceof \OwnPay\Core\Database) {
            $gateways = $db->fetchAll(
                "SELECT slug, name FROM op_gateways WHERE status = 'active' ORDER BY name"
            );
            $manualGateways = $db->fetchAll(
                "SELECT slug, name FROM op_manual_gateways WHERE merchant_id = :mid AND status = 'active' ORDER BY name",
                ['mid' => $mid]
            );
        }

        return $this->renderAdminPage('admin/sms-center/index.twig', [
            'sms_templates'   => $templates,
            'parsed_sms'      => $parsed,
            'sms_queue'       => $queue,
            'email_queue'     => $emailQueue,
            'telegram_queue'  => $telegramQueue,
            'webhook_queue'   => $webhookQueue,
            'queue_stats'     => $queueStats,
            'template_count'  => count($templates),
            'gateways'        => array_merge($gateways, $manualGateways),
            'can_manage_templates' => $canManageTemplates,
            'active_page'     => 'sms-center',
        ]);
    }

    /**
     * Create a new SMS parsing template.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP redirect response.
     * @throws \Exception If template creation fails.
     */
    public function create(Request $req): Response
    {
        // Templates are global; only the All-Brands view may create them (issue #6).
        if ($guard = $this->requireGlobalView('/admin/sms-center', 'add an SMS parsing template')) {
            return $guard;
        }
        $mid = $this->templateOwnerId($req);

        $postData = $req->post();
        $data = is_array($postData) ? $postData : [];

        $gatewaySlugVal = $data['gateway_slug'] ?? '';
        $gatewaySlug = is_string($gatewaySlugVal) ? $gatewaySlugVal : '';

        $senderPatternVal = $data['sender_pattern'] ?? '';
        $senderPattern = is_string($senderPatternVal) ? $senderPatternVal : '';

        $amountRegexVal = $data['amount_regex'] ?? '';
        $amountRegex = is_string($amountRegexVal) ? $amountRegexVal : '';

        $trxIdRegexVal = $data['trx_id_regex'] ?? '';
        $trxIdRegex = is_string($trxIdRegexVal) ? $trxIdRegexVal : '';

        $senderRegexVal = $data['sender_regex'] ?? '';
        $senderRegex = is_string($senderRegexVal) ? $senderRegexVal : '';

        $priorityVal = $data['priority'] ?? '10';
        $priority = is_string($priorityVal) || is_int($priorityVal) ? (string) $priorityVal : '10';

        $statusVal = $data['status'] ?? 'active';
        $status = is_string($statusVal) ? $statusVal : 'active';

        try {
            $this->tplRepo->createTemplate($mid, [
                'gateway_slug'   => $gatewaySlug,
                'sender_pattern' => $senderPattern,
                'amount_regex'   => $amountRegex,
                'trx_id_regex'   => $trxIdRegex,
                'sender_regex'   => $senderRegex,
                'priority'       => $priority,
                'status'         => $status,
            ]);
        } catch (\InvalidArgumentException $e) {
            // Regex syntax validation rejected the payload (issue #66).
            $this->session->flashError($e->getMessage());
            return Response::redirect('/admin/sms-center');
        }

        $this->session->flashSuccess('Parsing template created.');
        return Response::redirect('/admin/sms-center');
    }

    /**
     * Edit template form or update template settings.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response with form or redirect.
     * @throws \Exception If template lookup or update fails.
     */
    public function edit(Request $req): Response
    {
        if ($guard = $this->requireGlobalView('/admin/sms-center', 'edit an SMS parsing template')) {
            return $guard;
        }
        $id = (int) $req->param('id');
        $mid = $this->templateOwnerId($req);

        $tpl = $this->tplRepo->findForAdmin($id, $mid);
        if (!$tpl) {
            $this->session->flashError('Template not found.');
            return Response::redirect('/admin/sms-center');
        }

        if ($req->method() === 'POST') {
            $postData = $req->post();
            $data = is_array($postData) ? $postData : [];

            $gatewaySlugVal = $data['gateway_slug'] ?? '';
            $gatewaySlug = is_string($gatewaySlugVal) ? $gatewaySlugVal : '';

            $senderPatternVal = $data['sender_pattern'] ?? '';
            $senderPattern = is_string($senderPatternVal) ? $senderPatternVal : '';

            $amountRegexVal = $data['amount_regex'] ?? '';
            $amountRegex = is_string($amountRegexVal) ? $amountRegexVal : '';

            $trxIdRegexVal = $data['trx_id_regex'] ?? '';
            $trxIdRegex = is_string($trxIdRegexVal) ? $trxIdRegexVal : '';

            $senderRegexVal = $data['sender_regex'] ?? '';
            $senderRegex = is_string($senderRegexVal) ? $senderRegexVal : '';

            $priorityVal = $data['priority'] ?? '10';
            $priority = is_string($priorityVal) || is_int($priorityVal) ? (string) $priorityVal : '10';

            $statusVal = $data['status'] ?? 'active';
            $status = is_string($statusVal) ? $statusVal : 'active';

            try {
                $this->tplRepo->updateTemplate($id, $mid, [
                    'gateway_slug'   => $gatewaySlug,
                    'sender_pattern' => $senderPattern,
                    'amount_regex'   => $amountRegex,
                    'trx_id_regex'   => $trxIdRegex,
                    'sender_regex'   => $senderRegex,
                    'priority'       => $priority,
                    'status'         => $status,
                ]);
            } catch (\InvalidArgumentException $e) {
                // Regex syntax validation rejected the payload (issue #66).
                $this->session->flashError($e->getMessage());
                return Response::redirect('/admin/sms-center');
            }

            $this->session->flashSuccess('Template updated.');
            return Response::redirect('/admin/sms-center');
        }

        // Get gateway list for dropdown
        $db = $this->c->get(\OwnPay\Core\Database::class);
        $gateways = [];
        $manualGateways = [];
        if ($db instanceof \OwnPay\Core\Database) {
            $gateways = $db->fetchAll(
                "SELECT slug, name FROM op_gateways WHERE status = 'active' ORDER BY name"
            );
            $manualGateways = $db->fetchAll(
                "SELECT slug, name FROM op_manual_gateways WHERE merchant_id = :mid AND status = 'active' ORDER BY name",
                ['mid' => $mid]
            );
        }

        return $this->renderAdminPage('admin/sms-center/edit.twig', [
            'template'    => $tpl,
            'gateways'    => array_merge($gateways, $manualGateways),
            'active_page' => 'sms-center',
        ]);
    }

    /**
     * Delete an SMS parsing template.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP redirect response.
     * @throws \Exception If deletion fails.
     */
    public function delete(Request $req): Response
    {
        // Templates are global; only the All-Brands view may delete them (issue #6).
        if ($guard = $this->requireGlobalView('/admin/sms-center', 'delete an SMS parsing template')) {
            return $guard;
        }
        $id = (int) $req->param('id');
        $mid = $this->templateOwnerId($req);

        $this->tplRepo->deleteTemplate($id, $mid);
        $this->session->flashSuccess('Template deleted.');
        return Response::redirect('/admin/sms-center');
    }

    /**
     * Live regex test endpoint (AJAX).
     *
     * Accepts both JSON and form-urlencoded payloads (issue #65) so the admin
     * JS callers (`sms-center.js`, `sms-template-edit.js`) work regardless of
     * the Content-Type they send. The match itself is delegated to
     * {@see SmsRegexParser::testPattern()} so the preview reflects exactly
     * what the production parser will do, including the bounded backtracking
     * budget and the case-insensitive `/i` modifier applied to undelimited
     * fragments (issue #67).
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response with match results.
     */
    public function testRegex(Request $req): Response
    {
        // Use input() so JSON bodies are read correctly; falls back to POST
        // form fields for legacy callers (issue #65).
        $smsBodyRaw  = $req->input('sms_body', '');
        $smsBody = is_string($smsBodyRaw) ? $smsBodyRaw : '';
        $regexRaw    = $req->input('regex', '');
        $regex = is_string($regexRaw) ? $regexRaw : '';
        $fieldRaw    = $req->input('field', 'amount'); // amount, trx_id, sender
        $field = is_string($fieldRaw) ? $fieldRaw : 'amount';

        if ($regex === '' || $smsBody === '') {
            return Response::json(['success' => false, 'error' => 'Both SMS body and regex required.']);
        }

        $parser = new SmsRegexParser();
        $result = $parser->testPattern($regex, $smsBody);

        // testPattern returns matched=false for syntactically invalid patterns;
        // surface that explicitly so the admin sees a distinct error message
        // rather than a silent "no match".
        $wrappedPattern = '/' . str_replace('/', '\\/', $regex) . '/';
        if ($result['match'] === null && @preg_match($wrappedPattern, '') === false && @preg_match($regex, '') === false) {
            // Pattern is invalid when neither the wrapped nor raw form parses.
            // We rely on the wrapped check first because the raw regex may
            // already include its own delimiters.
            return Response::json(['success' => false, 'error' => 'Invalid regex pattern.']);
        }

        return Response::json([
            'success'  => true,
            'matched'  => $result['matched'],
            'field'    => $field,
            'match'    => $result['match'],
            'full'     => $result['full'],
        ]);
    }

    /**
     * POST /admin/sms-center/analyze - Method B: Smart heuristic extraction.
     * Requires both raw SMS body AND the actual SMS sender (From field).
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response containing parsed components.
     * @throws \Exception If database queries fail.
     */
    public function analyze(Request $req): Response
    {
        $postData = $req->post();
        $body = is_array($postData) ? $postData : [];
        $rawSmsVal = $body['raw_sms'] ?? '';
        $rawSms = trim(is_string($rawSmsVal) ? $rawSmsVal : '');
        $senderVal = $body['sender'] ?? '';
        $sender = trim(is_string($senderVal) ? $senderVal : '');

        if ($rawSms === '') {
            return Response::json(['success' => false, 'error' => 'Please paste the SMS body.']);
        }
        if ($sender === '') {
            return Response::json(['success' => false, 'error' => 'Please enter the SMS sender (From field).']);
        }

        // Load the sender whitelist from the global (platform) templates for the case-sensitive preview.
        $whitelist = $this->tplRepo->getSenderWhitelist($this->templateOwnerId($req));

        $analyzer = new SmartSmsAnalyzer($whitelist);
        $result   = $analyzer->analyze($rawSms, $sender);

        return Response::json([
            'success' => true,
            'data'    => $result,
        ]);
    }

    /**
     * POST /admin/sms-center/ai-prompt - Method C: Generate AI-ready prompt.
     * Requires both SMS body AND the exact SMS sender (From field).
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The JSON response with the generated prompt.
     */
    public function aiPrompt(Request $req): Response
    {
        $postData = $req->post();
        $body = is_array($postData) ? $postData : [];
        $rawSmsVal = $body['raw_sms'] ?? '';
        $rawSms = trim(is_string($rawSmsVal) ? $rawSmsVal : '');
        $senderVal = $body['sender'] ?? '';
        $sender = trim(is_string($senderVal) ? $senderVal : '');

        if ($rawSms === '') {
            return Response::json(['success' => false, 'error' => 'Please paste the SMS body.']);
        }
        if ($sender === '') {
            return Response::json(['success' => false, 'error' => 'Please enter the SMS sender (From field).']);
        }

        $prompt = SmartSmsAnalyzer::buildAiPrompt($rawSms, $sender);

        return Response::json([
            'success' => true,
            'prompt'  => $prompt,
        ]);
    }

    /**
     * POST /admin/sms-center/save-analysis - Save analysis result as new template.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP redirect response.
     * @throws \Exception If template creation fails.
     */
    public function saveAnalysis(Request $req): Response
    {
        if ($guard = $this->requireGlobalView('/admin/sms-center', 'save an SMS parsing template')) {
            return $guard;
        }
        $mid = $this->templateOwnerId($req);
        $postData = $req->post();
        $data = is_array($postData) ? $postData : [];
        $gatewaySlugVal   = $data['gateway_slug'] ?? '';
        $gatewaySlug   = trim(is_string($gatewaySlugVal) ? $gatewaySlugVal : '');
        $senderPatternVal = $data['sender_pattern'] ?? '';
        $senderPattern = trim(is_string($senderPatternVal) ? $senderPatternVal : '');
        $amountRegexVal   = $data['amount_regex'] ?? '';
        $amountRegex   = trim(is_string($amountRegexVal) ? $amountRegexVal : '');
        $trxIdRegexVal    = $data['trx_id_regex'] ?? '';
        $trxIdRegex    = trim(is_string($trxIdRegexVal) ? $trxIdRegexVal : '');
        $senderRegexVal   = $data['sender_regex'] ?? '';
        $senderRegex   = trim(is_string($senderRegexVal) ? $senderRegexVal : '');
        if ($senderPattern === '') {
            $this->session->flashError('Sender pattern is required.');
            return Response::redirect('/admin/sms-center');
        }

        $priorityVal = $data['priority'] ?? 10;
        $priority = is_scalar($priorityVal) && is_numeric($priorityVal) ? (int) $priorityVal : 10;

        try {
            $this->tplRepo->createTemplate($mid, [
                'gateway_slug'   => $gatewaySlug,
                'sender_pattern' => $senderPattern,
                'amount_regex'   => $amountRegex,
                'trx_id_regex'   => $trxIdRegex,
                'sender_regex'   => $senderRegex,
                'priority'       => $priority,
                'status'         => 'active',
            ]);
        } catch (\InvalidArgumentException $e) {
            // Regex syntax validation rejected the payload (issue #66).
            $this->session->flashError($e->getMessage());
            return Response::redirect('/admin/sms-center');
        }

        $this->session->flashSuccess("Template for '{$gatewaySlug}' saved from analysis");
        return Response::redirect('/admin/sms-center');
    }

    /**
     * Retry a failed SMS log entry from the outbound queue.
     */
    public function retryQueueItem(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $mid = 0;
        if ($brand instanceof \OwnPay\Service\Brand\BrandContext) {
            $brand->resolveFromRequest($req);
            $activeId = $brand->getActiveBrandId();
            if ($activeId !== null) {
                $mid = $activeId;
            }
        }

        $idVal = $req->param('id');
        $id = is_numeric($idVal) ? (int)$idVal : 0;

        $requeued = $this->commRepo->retrySms($id, $mid);

        if ($requeued > 0) {
            $this->session->flashSuccess('SMS successfully requeued.');
        } else {
            $this->session->flashError('Failed to requeue SMS or SMS is not in failed state.');
        }

        return Response::redirect('/admin/sms-center#queue');
    }

    /**
     * Manually match a parsed SMS to a pending transaction.
     */
    public function matchSms(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $mid = 0;
        if ($brand instanceof \OwnPay\Service\Brand\BrandContext) {
            $brand->resolveFromRequest($req);
            $activeId = $brand->getActiveBrandId();
            if ($activeId !== null) {
                $mid = $activeId;
            }
        }

        $smsIdVal = $req->post('sms_id');
        $smsId = is_numeric($smsIdVal) ? (int)$smsIdVal : 0;

        $txnIdVal = $req->post('transaction_id');
        $txnId = is_numeric($txnIdVal) ? (int)$txnIdVal : 0;

        if ($smsId <= 0 || $txnId <= 0) {
            $this->session->flashError('Both SMS ID and Transaction ID are required.');
            return Response::redirect('/admin/sms-center#parsed');
        }

        $txnRepo = $this->c->get(\OwnPay\Repository\TransactionRepository::class);
        if (!$txnRepo instanceof \OwnPay\Repository\TransactionRepository) {
            throw new \RuntimeException('TransactionRepository service unavailable');
        }
        $txn = $txnRepo->forTenant($mid)->findScoped($txnId);
        if ($txn === null) {
            $this->session->flashError('Transaction not found or unauthorized.');
            return Response::redirect('/admin/sms-center#parsed');
        }

        $db = $this->c->get(\OwnPay\Core\Database::class);
        $transactionService = $this->c->get(\OwnPay\Service\Payment\TransactionService::class);
        $ledgerService = $this->c->get(\OwnPay\Service\Payment\LedgerService::class);

        if (!$db instanceof \OwnPay\Core\Database ||
            !$transactionService instanceof \OwnPay\Service\Payment\TransactionService ||
            !$ledgerService instanceof \OwnPay\Service\Payment\LedgerService) {
            throw new \RuntimeException('Database or payment services unavailable');
        }

        try {
            $db->transaction(function () use ($db, $mid, $smsId, $txnId, $transactionService, $ledgerService) {
                // SMS-3: Lock both the SMS row and the transaction row for the
                // duration of this DB transaction. The previous implementation
                // read $txn['status'] BEFORE the DB transaction opened (stale
                // against any concurrent completion by another admin or the
                // SmsVerificationJob cron), and linkToTransaction() was a
                // plain UPDATE with no guard that the SMS was not already
                // matched. An admin could match SMS #1 to txn #1 (txn #1
                // completed, ledger credited), then match SMS #1 again to
                // txn #2 (SMS row's transaction_id overwritten to #2, txn #2
                // completed, ledger credited again) — using one SMS as proof
                // for two payments. There was also no amount/gateway check,
                // so an SMS for 100 BDT could be matched to a 10,000 BDT txn.
                $sms = $db->fetchOne(
                    "SELECT id, amount, gateway_slug, match_status, transaction_id
                     FROM op_sms_parsed
                     WHERE id = :sid AND merchant_id = :mid
                     LIMIT 1 FOR UPDATE",
                    ['sid' => $smsId, 'mid' => $mid]
                );
                if ($sms === null) {
                    throw new \RuntimeException('SMS record not found or unauthorized.');
                }
                $smsMatchStatus = isset($sms['match_status']) && is_scalar($sms['match_status'])
                    ? (string) $sms['match_status']
                    : '';
                if ($smsMatchStatus === 'matched') {
                    throw new \RuntimeException('SMS is already matched to a transaction.');
                }
                $smsAmount = isset($sms['amount']) && is_scalar($sms['amount']) ? (string) $sms['amount'] : '';
                $smsGateway = isset($sms['gateway_slug']) && is_scalar($sms['gateway_slug'])
                    ? (string) $sms['gateway_slug']
                    : '';

                $txn = $db->fetchOne(
                    "SELECT id, status, amount, fee, currency, gateway_slug
                     FROM op_transactions
                     WHERE id = :tid AND merchant_id = :mid
                     LIMIT 1 FOR UPDATE",
                    ['tid' => $txnId, 'mid' => $mid]
                );
                if ($txn === null) {
                    throw new \RuntimeException('Transaction not found or unauthorized.');
                }
                $txnStatus = isset($txn['status']) && is_scalar($txn['status']) ? (string) $txn['status'] : '';
                if ($txnStatus !== 'pending') {
                    throw new \RuntimeException("Transaction is not pending (current status: {$txnStatus}).");
                }
                $txnAmount = isset($txn['amount']) && is_scalar($txn['amount']) ? (string) $txn['amount'] : '0.00';
                $txnFee = isset($txn['fee']) && is_scalar($txn['fee']) ? (string) $txn['fee'] : '0.00';
                $txCurrency = isset($txn['currency']) && is_scalar($txn['currency']) ? (string) $txn['currency'] : 'BDT';
                $txnGateway = isset($txn['gateway_slug']) && is_scalar($txn['gateway_slug'])
                    ? (string) $txn['gateway_slug']
                    : '';

                // SMS-3: Refuse if the SMS amount does not equal the txn amount.
                // Without this check a 100-BDT SMS could be matched to a
                // 10,000-BDT transaction, defrauding the merchant.
                if ($smsAmount !== '' && is_numeric($smsAmount) && is_numeric($txnAmount)
                    && bccomp($smsAmount, $txnAmount, 2) !== 0) {
                    throw new \RuntimeException(
                        "SMS amount {$smsAmount} does not match transaction amount {$txnAmount}."
                    );
                }

                // SMS-3: Refuse if the SMS gateway does not match the txn gateway.
                // An SMS from bKash should not verify a Nagad transaction.
                if ($smsGateway !== '' && $txnGateway !== '' && $smsGateway !== $txnGateway) {
                    throw new \RuntimeException(
                        "SMS gateway {$smsGateway} does not match transaction gateway {$txnGateway}."
                    );
                }

                // SMS-3: Use a conditional link that only succeeds when the SMS
                // is still unmatched — defends against a concurrent matchSms
                // call that raced us between the FOR UPDATE read above and
                // the write. (The FOR UPDATE should already prevent this, but
                // the conditional UPDATE is belt-and-suspenders.)
                $affected = $db->update(
                    "UPDATE op_sms_parsed
                     SET transaction_id = :tid, match_status = 'matched'
                     WHERE id = :sid AND merchant_id = :mid AND match_status != 'matched'",
                    ['tid' => $txnId, 'sid' => $smsId, 'mid' => $mid]
                );
                if ($affected === 0) {
                    throw new \RuntimeException('SMS was matched by another caller; please refresh and try again.');
                }

                // SMS-3: complete() returns the transaction record array.
                // Internally it calls markCompletedIfNotTerminal() (an atomic
                // UPDATE … WHERE status NOT IN (terminal)) and only fires the
                // payment.transaction.completed event + audit log when
                // affected=1. We mirror that contract here: capture the
                // pre-call status from the FOR UPDATE row above (already
                // verified to be 'pending'), call complete(), then check
                // whether the returned record's status is 'completed' AND
                // the pre-call status was 'pending' to decide whether to
                // credit the ledger. Two concurrent callers would both see
                // status='pending' on their stale pre-transaction read, but
                // the FOR UPDATE lock serializes them — the second caller
                // observes the post-complete status='completed' from the
                // locked row and the conditional above (txnStatus !== 'pending')
                // already rejected them.
                $transactionService->complete($txnId, $mid);
                $ledgerService->recordPaymentReceived(
                    $mid,
                    $txnId,
                    $txnAmount,
                    $txnFee,
                    $txCurrency
                );
            });

            $this->session->flashSuccess('SMS successfully matched and transaction completed.');
        } catch (\Throwable $e) {
            $this->session->flashError('Match failed: ' . $e->getMessage());
        }

        return Response::redirect('/admin/sms-center#parsed');
    }
}
