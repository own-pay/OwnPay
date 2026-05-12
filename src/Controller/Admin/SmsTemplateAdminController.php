<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Sms\SmartSmsAnalyzer;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\SmsTemplateRepository;
use OwnPay\Repository\SmsParsedRepository;
use OwnPay\Repository\CommLogRepository;

/**
 * SMS Center — Parsing Templates, Parsed SMS log, Outbound Queue.
 */
final class SmsTemplateAdminController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private SmsTemplateRepository $tplRepo;
    private SmsParsedRepository $parsedRepo;
    private CommLogRepository $commRepo;

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
     * Main SMS Center page — 3 tabs.
     */
    public function index(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $templates  = $this->tplRepo->listForAdmin($mid);
        $parsed     = $this->parsedRepo->forTenant($mid)->findUnmatched(100);
        $queue      = $this->commRepo->listSmsQueue($mid);
        $queueStats = $this->commRepo->getSmsQueueStats($mid);

        // Get gateway list for dropdown
        $gateways = $this->c->get(\OwnPay\Core\Database::class)->fetchAll(
            "SELECT slug, name FROM op_gateways WHERE status = 'active' ORDER BY name"
        );
        // Also manual gateways
        $manualGateways = $this->c->get(\OwnPay\Core\Database::class)->fetchAll(
            "SELECT slug, name FROM op_manual_gateways WHERE merchant_id = :mid AND status = 'active' ORDER BY name",
            ['mid' => $mid]
        );

        return $this->renderAdminPage('admin/sms-center/index.twig', [
            'sms_templates'   => $templates,
            'parsed_sms'      => $parsed,
            'sms_queue'       => $queue,
            'queue_stats'     => $queueStats,
            'template_count'  => count($templates),
            'gateways'        => array_merge($gateways, $manualGateways),
            'active_page'     => 'sms-center',
        ]);
    }

    /**
     * Create new parsing template.
     */
    public function create(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $this->tplRepo->createTemplate($mid, [
            'gateway_slug'   => $req->post('gateway_slug', ''),
            'sender_pattern' => $req->post('sender_pattern', ''),
            'amount_regex'   => $req->post('amount_regex', ''),
            'trx_id_regex'   => $req->post('trx_id_regex', ''),
            'sender_regex'   => $req->post('sender_regex', ''),
            'priority'       => $req->post('priority', '10'),
            'status'         => $req->post('status', 'active'),
        ]);

        $this->session->flashSuccess('Parsing template created.');
        return Response::redirect('/admin/sms-center');
    }

    /**
     * Edit template form + save.
     */
    public function edit(Request $req): Response
    {
        $id = (int) $req->param('id');
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $tpl = $this->tplRepo->findForAdmin($id, $mid);
        if (!$tpl) {
            $this->session->flashError('Template not found.');
            return Response::redirect('/admin/sms-center');
        }

        if ($req->method() === 'POST') {
            $this->tplRepo->updateTemplate($id, $mid, [
                'gateway_slug'   => $req->post('gateway_slug', ''),
                'sender_pattern' => $req->post('sender_pattern', ''),
                'amount_regex'   => $req->post('amount_regex', ''),
                'trx_id_regex'   => $req->post('trx_id_regex', ''),
                'sender_regex'   => $req->post('sender_regex', ''),
                'priority'       => $req->post('priority', '10'),
                'status'         => $req->post('status', 'active'),
            ]);

            $this->session->flashSuccess('Template updated.');
            return Response::redirect('/admin/sms-center');
        }

        // Get gateway list for dropdown
        $gateways = $this->c->get(\OwnPay\Core\Database::class)->fetchAll(
            "SELECT slug, name FROM op_gateways WHERE status = 'active' ORDER BY name"
        );
        $manualGateways = $this->c->get(\OwnPay\Core\Database::class)->fetchAll(
            "SELECT slug, name FROM op_manual_gateways WHERE merchant_id = :mid AND status = 'active' ORDER BY name",
            ['mid' => $mid]
        );

        return $this->renderAdminPage('admin/sms-center/edit.twig', [
            'template'    => $tpl,
            'gateways'    => array_merge($gateways, $manualGateways),
            'active_page' => 'sms-center',
        ]);
    }

    /**
     * Delete template.
     */
    public function delete(Request $req): Response
    {
        $id = (int) $req->param('id');
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $this->tplRepo->deleteTemplate($id, $mid);
        $this->session->flashSuccess('Template deleted.');
        return Response::redirect('/admin/sms-center');
    }

    /**
     * Live regex test endpoint (AJAX).
     */
    public function testRegex(Request $req): Response
    {
        $smsBody  = $req->post('sms_body', '');
        $regex    = $req->post('regex', '');
        $field    = $req->post('field', 'amount'); // amount, trx_id, sender

        if (empty($regex) || empty($smsBody)) {
            return Response::json(['success' => false, 'error' => 'Both SMS body and regex required.']);
        }

        // Validate regex is safe
        if (@preg_match('/' . $regex . '/', '') === false) {
            return Response::json(['success' => false, 'error' => 'Invalid regex pattern.']);
        }

        $matches = [];
        $found = preg_match('/' . $regex . '/', $smsBody, $matches);

        return Response::json([
            'success'  => true,
            'matched'  => (bool) $found,
            'field'    => $field,
            'match'    => $matches[1] ?? ($matches[0] ?? null),
            'full'     => $matches,
        ]);
    }

    /**
     * POST /admin/sms-center/analyze — Method B: Smart heuristic extraction.
     * Requires both raw SMS body AND the actual SMS sender (From field).
     */
    public function analyze(Request $req): Response
    {
        $body   = $req->post();
        $rawSms = trim($body['raw_sms'] ?? '');
        $sender = trim($body['sender'] ?? '');

        if ($rawSms === '') {
            return Response::json(['success' => false, 'error' => 'Please paste the SMS body.']);
        }
        if ($sender === '') {
            return Response::json(['success' => false, 'error' => 'Please enter the SMS sender (From field).']);
        }

        // Load sender whitelist from DB for current brand (case-sensitive exact match)
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        $whitelist = $this->tplRepo->getSenderWhitelist($mid);

        $analyzer = new SmartSmsAnalyzer($whitelist);
        $result   = $analyzer->analyze($rawSms, $sender);

        return Response::json([
            'success' => true,
            'data'    => $result,
        ]);
    }

    /**
     * POST /admin/sms-center/ai-prompt — Method C: Generate AI-ready prompt.
     * Requires both SMS body AND the exact SMS sender (From field).
     */
    public function aiPrompt(Request $req): Response
    {
        $body   = $req->post();
        $rawSms = trim($body['raw_sms'] ?? '');
        $sender = trim($body['sender'] ?? '');

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
     * POST /admin/sms-center/save-analysis — Save analysis result as new template.
     */
    public function saveAnalysis(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $data = $req->post();
        $gatewaySlug   = trim($data['gateway_slug'] ?? '');
        $senderPattern = trim($data['sender_pattern'] ?? '');
        $amountRegex   = trim($data['amount_regex'] ?? '');
        $trxIdRegex    = trim($data['trx_id_regex'] ?? '');
        $senderRegex   = trim($data['sender_regex'] ?? '');

        if ($gatewaySlug === '') {
            $this->session->flashError('Gateway slug is required');
            return Response::redirect('/admin/sms-center');
        }

        $this->tplRepo->createTemplate($mid, [
            'gateway_slug'   => $gatewaySlug,
            'sender_pattern' => $senderPattern ?: $gatewaySlug,
            'amount_regex'   => $amountRegex ?: '',
            'trx_id_regex'   => $trxIdRegex ?: '',
            'sender_regex'   => $senderRegex ?: '',
            'priority'       => (int) ($data['priority'] ?? 10),
            'status'         => 'active',
        ]);

        $this->session->flashSuccess("Template for '{$gatewaySlug}' saved from analysis");
        return Response::redirect('/admin/sms-center');
    }
}
