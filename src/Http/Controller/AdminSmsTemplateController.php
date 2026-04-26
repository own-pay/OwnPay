<?php

declare(strict_types=1);

namespace OwnPay\Http\Controller;

use OwnPay\Http\JsonResponse;
use OwnPay\Repository\SmsTemplateRepository;
use OwnPay\Service\SmsRegexParser;

/**
 * AdminSmsTemplateController — CRUD for SMS regex templates.
 *
 * Endpoints (Bearer-auth admin routes):
 *   GET    /v1/admin/sms-templates           — List all templates
 *   GET    /v1/admin/sms-templates/{id}      — Get single template
 *   POST   /v1/admin/sms-templates           — Create template
 *   PUT    /v1/admin/sms-templates/{id}      — Update template
 *   DELETE /v1/admin/sms-templates/{id}      — Delete template
 *   POST   /v1/admin/sms-templates/test      — Test a regex against sample text
 */
final class AdminSmsTemplateController
{
    private SmsTemplateRepository $repo;

    public function __construct()
    {
        $this->repo = new SmsTemplateRepository();
    }

    /**
     * GET /v1/admin/sms-templates
     *
     * List all templates (active + inactive), ordered by priority.
     */
    public function index(array $params): void
    {
        $templates = $this->repo->findAllActive();
        JsonResponse::success(['templates' => $templates, 'count' => count($templates)]);
    }

    /**
     * GET /v1/admin/sms-templates/{id}
     */
    public function show(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            JsonResponse::error('INVALID_ID', 'Template ID is required.', 400);
            return;
        }

        $template = $this->repo->findById($id);
        if (!$template) {
            JsonResponse::error('NOT_FOUND', 'Template not found.', 404);
            return;
        }

        JsonResponse::success(['template' => $template]);
    }

    /**
     * POST /v1/admin/sms-templates
     *
     * Required: sender_pattern, regex_pattern, provider_name
     * Optional: transaction_type (default: credit), priority (default: 100), is_active, description
     */
    public function create(array $params): void
    {
        $body = JsonResponse::parseRequestBody();
        if ($body === null) {
            JsonResponse::error('INVALID_JSON', 'Request body must be valid JSON.', 400);
            return;
        }

        // Validate required fields
        $required = ['sender_pattern', 'regex_pattern', 'provider_name'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                JsonResponse::error('MISSING_FIELD', "Field '{$field}' is required.", 400);
                return;
            }
        }

        // Validate regex is compilable
        $regexError = $this->validateRegex($body['regex_pattern']);
        if ($regexError !== null) {
            JsonResponse::error('INVALID_REGEX', $regexError, 400);
            return;
        }

        $id = $this->repo->create($body);
        $template = $this->repo->findById($id);

        JsonResponse::success(['template' => $template], 201);
    }

    /**
     * PUT /v1/admin/sms-templates/{id}
     */
    public function update(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            JsonResponse::error('INVALID_ID', 'Template ID is required.', 400);
            return;
        }

        $existing = $this->repo->findById($id);
        if (!$existing) {
            JsonResponse::error('NOT_FOUND', 'Template not found.', 404);
            return;
        }

        $body = JsonResponse::parseRequestBody();
        if ($body === null) {
            JsonResponse::error('INVALID_JSON', 'Request body must be valid JSON.', 400);
            return;
        }

        // Validate regex if provided
        if (!empty($body['regex_pattern'])) {
            $regexError = $this->validateRegex($body['regex_pattern']);
            if ($regexError !== null) {
                JsonResponse::error('INVALID_REGEX', $regexError, 400);
                return;
            }
        }

        $this->repo->update($id, $body);
        $template = $this->repo->findById($id);

        JsonResponse::success(['template' => $template]);
    }

    /**
     * DELETE /v1/admin/sms-templates/{id}
     */
    public function destroy(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            JsonResponse::error('INVALID_ID', 'Template ID is required.', 400);
            return;
        }

        $existing = $this->repo->findById($id);
        if (!$existing) {
            JsonResponse::error('NOT_FOUND', 'Template not found.', 404);
            return;
        }

        $this->repo->delete($id);
        JsonResponse::success(['deleted' => true, 'id' => $id]);
    }

    /**
     * POST /v1/admin/sms-templates/test
     *
     * Test a regex pattern against sample SMS text.
     * Body: { "regex_pattern": "...", "sample_text": "...", "transaction_type": "credit" }
     *
     * Returns matched groups and what the parser would extract.
     */
    public function testRegex(array $params): void
    {
        $body = JsonResponse::parseRequestBody();
        if ($body === null) {
            JsonResponse::error('INVALID_JSON', 'Request body must be valid JSON.', 400);
            return;
        }

        $pattern = $body['regex_pattern'] ?? '';
        $sample  = $body['sample_text'] ?? '';
        $type    = $body['transaction_type'] ?? 'credit';

        if (empty($pattern) || empty($sample)) {
            JsonResponse::error('MISSING_FIELDS', 'Both "regex_pattern" and "sample_text" are required.', 400);
            return;
        }

        $regexError = $this->validateRegex($pattern);
        if ($regexError !== null) {
            JsonResponse::error('INVALID_REGEX', $regexError, 400);
            return;
        }

        // Run the regex parser on the sample
        $parser = new SmsRegexParser();
        $templates = [[
            'id'               => 0,
            'sender_pattern'   => 'test',
            'regex_pattern'    => $pattern,
            'transaction_type' => $type,
        ]];

        $result = $parser->parse($sample, $templates);

        // Also capture raw regex matches for debugging
        $rawMatches = [];
        if (preg_match($pattern, $sample, $matches)) {
            // Filter named captures only
            foreach ($matches as $k => $v) {
                if (is_string($k)) {
                    $rawMatches[$k] = $v;
                }
            }
        }

        JsonResponse::success([
            'matched'       => $result !== null,
            'parsed_result' => $result,
            'raw_captures'  => $rawMatches,
            'sample_text'   => $sample,
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * Validate a regex pattern is compilable.
     * Returns error string on failure, null on success.
     */
    private function validateRegex(string $pattern): ?string
    {
        // Suppress warnings from invalid regex
        set_error_handler(fn() => true);
        $result = @preg_match($pattern, '');
        restore_error_handler();

        if ($result === false) {
            return 'Invalid regex pattern: ' . preg_last_error_msg();
        }

        return null;
    }
}
