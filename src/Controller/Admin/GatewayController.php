<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Repository\ManualGatewayRepository;
use OwnPay\Service\System\FilesystemService;
use OwnPay\Service\System\InputSanitizer;

/**
 * Gateway admin controller — CRUD for manual gateways, list API gateways.
 */
final class GatewayController
{
    private Container $container;
    private ManualGatewayRepository $manualGateways;
    private GatewayConfigRepository $apiConfigs;
    private FilesystemService $fs;

    public function __construct(
        Container $container,
        ManualGatewayRepository $manualGateways,
        GatewayConfigRepository $apiConfigs,
        FilesystemService $fs
    ) {
        $this->container = $container;
        $this->manualGateways = $manualGateways;
        $this->apiConfigs = $apiConfigs;
        $this->fs = $fs;
    }

    public function index(Request $request): Response
    {
        $merchantId = (int) $request->getAttribute('merchant_id');

        $apiGateways = $this->apiConfigs->forTenant($merchantId)->listActiveWithGateway();
        $manualGateways = $this->manualGateways->forTenant($merchantId)->listAll();

        // Decode JSON fields for template
        foreach ($manualGateways as &$mg) {
            $mg['input_fields'] = json_decode($mg['input_fields'] ?? '[]', true);
            $mg['colors'] = json_decode($mg['colors'] ?? '{}', true);
        }

        return $this->render('admin/gateways/index.twig', [
            'api_gateways'    => $apiGateways,
            'manual_gateways' => $manualGateways,
        ]);
    }

    public function createManual(Request $request): Response
    {
        if ($request->method() === 'GET') {
            return $this->render('admin/gateways/create-manual.twig', ['old' => []]);
        }

        // POST
        $merchantId = (int) $request->getAttribute('merchant_id');
        $data = $request->post();
        $errors = $this->validateManualGateway($data);

        if (!empty($errors)) {
            return $this->render('admin/gateways/create-manual.twig', [
                'old'    => $data,
                'errors' => $errors,
            ]);
        }

        // Handle file uploads
        $logoPath = null;
        $qrPath = null;
        if (!empty($_FILES['logo']['tmp_name'])) {
            $logoPath = $this->fs->storeUpload($_FILES['logo'], 'gateways');
        }
        if (!empty($_FILES['qr_code']['tmp_name'])) {
            $qrPath = $this->fs->storeUpload($_FILES['qr_code'], 'gateways');
        }

        // Build input fields JSON
        $fields = $this->buildFieldsJson($data['fields'] ?? []);

        // Build colors JSON
        $colors = json_encode([
            'primary'   => $data['color_primary'] ?? '#E2136E',
            'secondary' => $data['color_secondary'] ?? '#FFFFFF',
            'text'      => $data['color_text'] ?? '#FFFFFF',
        ]);

        $this->manualGateways->forTenant($merchantId)->createScoped([
            'name'             => InputSanitizer::string($data['name']),
            'slug'             => InputSanitizer::slug($data['slug']),
            'instructions'     => InputSanitizer::string($data['instructions'] ?? ''),
            'logo_path'        => $logoPath,
            'qr_code_path'     => $qrPath,
            'colors'           => $colors,
            'input_fields'     => $fields,
            'min_amount'       => InputSanitizer::decimal($data['min_amount'] ?? '0'),
            'max_amount'       => InputSanitizer::decimal($data['max_amount'] ?? '0'),
            'sms_verification' => isset($data['sms_verification']) ? 1 : 0,
            'status'           => 'active',
        ]);

        $_SESSION['flash_success'] = 'Gateway created!';
        return Response::redirect('/admin/gateways');
    }

    public function editManual(Request $request, int $id): Response
    {
        $merchantId = (int) $request->getAttribute('merchant_id');
        $gateway = $this->manualGateways->forTenant($merchantId)->findScoped($id);

        if ($gateway === null) {
            $_SESSION['flash_error'] = 'Gateway not found';
            return Response::redirect('/admin/gateways');
        }

        $gateway['input_fields'] = json_decode($gateway['input_fields'] ?? '[]', true);
        $gateway['colors'] = json_decode($gateway['colors'] ?? '{}', true);

        if ($request->method() === 'GET') {
            return $this->render('admin/gateways/edit-manual.twig', ['gateway' => $gateway]);
        }

        // POST — update
        $data = $request->post();
        $errors = $this->validateManualGateway($data, true);

        if (!empty($errors)) {
            return $this->render('admin/gateways/edit-manual.twig', [
                'gateway' => array_merge($gateway, $data),
                'errors'  => $errors,
            ]);
        }

        $update = [
            'name'             => InputSanitizer::string($data['name']),
            'instructions'     => InputSanitizer::string($data['instructions'] ?? ''),
            'input_fields'     => $this->buildFieldsJson($data['fields'] ?? []),
            'min_amount'       => InputSanitizer::decimal($data['min_amount'] ?? '0'),
            'max_amount'       => InputSanitizer::decimal($data['max_amount'] ?? '0'),
            'sms_verification' => isset($data['sms_verification']) ? 1 : 0,
            'colors'           => json_encode([
                'primary'   => $data['color_primary'] ?? '#E2136E',
                'secondary' => $data['color_secondary'] ?? '#FFFFFF',
                'text'      => $data['color_text'] ?? '#FFFFFF',
            ]),
        ];

        // Handle file uploads (optional on edit)
        if (!empty($_FILES['logo']['tmp_name'])) {
            $update['logo_path'] = $this->fs->storeUpload($_FILES['logo'], 'gateways');
        }
        if (!empty($_FILES['qr_code']['tmp_name'])) {
            $update['qr_code_path'] = $this->fs->storeUpload($_FILES['qr_code'], 'gateways');
        }

        $this->manualGateways->forTenant($merchantId)->updateScoped($id, $update);

        $_SESSION['flash_success'] = 'Gateway updated!';
        return Response::redirect('/admin/gateways');
    }

    public function toggleStatus(Request $request, int $id): Response
    {
        $merchantId = (int) $request->getAttribute('merchant_id');
        $gateway = $this->manualGateways->forTenant($merchantId)->findScoped($id);

        if ($gateway !== null) {
            $newStatus = $gateway['status'] === 'active' ? 'inactive' : 'active';
            $this->manualGateways->forTenant($merchantId)->updateScoped($id, ['status' => $newStatus]);
            $_SESSION['flash_success'] = "Gateway {$newStatus}!";
        }

        return Response::redirect('/admin/gateways');
    }

    private function validateManualGateway(array $data, bool $isEdit = false): array
    {
        $errors = [];
        if (empty($data['name'])) {
            $errors[] = 'Gateway name is required';
        }
        if (!$isEdit && empty($data['slug'])) {
            $errors[] = 'Slug is required';
        }
        if (!$isEdit && !empty($data['slug']) && !preg_match('/^[a-z0-9\-]+$/', $data['slug'])) {
            $errors[] = 'Slug must be lowercase alphanumeric with hyphens only';
        }
        return $errors;
    }

    private function buildFieldsJson(array $fields): string
    {
        $clean = [];
        foreach ($fields as $field) {
            if (!empty($field['name']) && !empty($field['label'])) {
                $clean[] = [
                    'name'     => InputSanitizer::slug($field['name']),
                    'label'    => InputSanitizer::string($field['label']),
                    'type'     => $field['type'] ?? 'text',
                    'required' => !empty($field['required']),
                ];
            }
        }
        return json_encode($clean);
    }

    private function render(string $template, array $data = []): Response
    {
        /** @var \Twig\Environment $twig */
        $twig = $this->container->get(\Twig\Environment::class);
        $data['app_name'] = $this->container->get('config.app')['name'] ?? 'Own Pay';
        $data['csrf_token'] = $_SESSION['csrf_token'] ?? '';
        $data['flash_success'] = $_SESSION['flash_success'] ?? null;
        $data['flash_error'] = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);
        return Response::html($twig->render($template, $data));
    }
}
