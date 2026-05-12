<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\GatewayConfigRepository;
use OwnPay\Repository\ManualGatewayRepository;
use OwnPay\Service\System\FilesystemService;
use OwnPay\Service\System\InputSanitizer;
use OwnPay\Service\System\AuditService;

/**
 * Gateway admin controller — CRUD for manual gateways, list API gateways.
 */
final class GatewayController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private ManualGatewayRepository $manualGateways;
    private GatewayConfigRepository $apiConfigs;
    private FilesystemService $fs;
    private AuditService $audit;

    public function __construct(
        Container $c,
        AdminSession $session,
        ManualGatewayRepository $manualGateways,
        GatewayConfigRepository $apiConfigs,
        FilesystemService $fs,
        AuditService $audit
    ) {
        $this->c              = $c;
        $this->session        = $session;
        $this->manualGateways = $manualGateways;
        $this->apiConfigs     = $apiConfigs;
        $this->fs             = $fs;
        $this->audit          = $audit;
    }

    public function index(Request $request): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($request);
        $merchantId = $brand->getActiveBrandId();

        $manualGateways = $this->manualGateways->forTenant($merchantId)->listAll();

        foreach ($manualGateways as &$mg) {
            $mg['input_fields'] = json_decode($mg['input_fields'] ?? '[]', true);
            $mg['colors'] = json_decode($mg['colors'] ?? '{}', true);
        }
        unset($mg);

        // Build API gateway list: filesystem discovery + op_plugins status
        /** @var \OwnPay\Repository\PluginRepository $pluginRepo */
        $pluginRepo = $this->c->get(\OwnPay\Repository\PluginRepository::class);

        /** @var \OwnPay\Plugin\PluginLoader $loader */
        $loader = $this->c->get(\OwnPay\Plugin\PluginLoader::class);
        $discovered = $loader->discover();

        // Index installed plugins by slug for O(1) lookup
        $installedPlugins = [];
        foreach ($pluginRepo->paginate(1, 200)['items'] as $p) {
            $installedPlugins[$p['slug']] = $p;
        }

        // Also get gateway configs (credentials) per slug
        $configuredGateways = [];
        foreach ($this->apiConfigs->forTenant($merchantId)->listActive() as $g) {
            $configuredGateways[$g['slug']] = $g;
        }

        $apiGateways = [];
        foreach ($discovered as $manifest) {
            if ($manifest->type !== 'gateway') {
                continue;
            }

            $installed = $installedPlugins[$manifest->slug] ?? null;
            $configured = $configuredGateways[$manifest->slug] ?? null;

            // Determine display status
            if ($installed === null) {
                $status = 'uninstalled';
            } elseif ($installed['status'] === 'active') {
                $status = $configured ? 'active' : 'installed'; // active+configured vs just activated
            } else {
                $status = $installed['status']; // inactive, etc.
            }

            $apiGateways[] = [
                'slug'        => $manifest->slug,
                'name'        => $manifest->name,
                'description' => $manifest->description,
                'version'     => $manifest->version,
                'status'      => $status,
                'logo'        => $configured['logo_path'] ?? '',
                'mode'        => $configured['mode'] ?? '',
            ];
        }

        return $this->renderAdminPage('admin/gateways/index.twig', [
            'api_gateways'    => $apiGateways,
            'manual_gateways' => $manualGateways,
            'active_page'     => 'gateways',
        ]);
    }


    public function createManual(Request $request): Response
    {
        if ($request->method() === 'GET') {
            return $this->renderAdminPage('admin/gateways/create-manual.twig', ['old' => [], 'active_page' => 'gateways']);
        }

        $merchantId = $this->resolveMerchant($request);
        $data = $request->post();
        $errors = $this->validateManualGateway($data);

        if (!empty($errors)) {
            return $this->renderAdminPage('admin/gateways/create-manual.twig', ['old' => $data, 'errors' => $errors, 'active_page' => 'gateways']);
        }

        $record = $this->buildGatewayRecord($data);
        $record['slug']   = InputSanitizer::slug($data['slug'] ?? '');
        $record['status'] = 'active';
        $this->applyUploads($record);

        $gid = $this->manualGateways->forTenant($merchantId)->createScoped($record);
        $this->audit->log('gateway.created', 'manual_gateway', (int) $gid, null, ['name' => $record['name']]);
        $this->session->flashSuccess('Gateway created!');
        return Response::redirect('/admin/gateways');
    }

    public function editManual(Request $request): Response
    {
        $merchantId = $this->resolveMerchant($request);
        $id = (int) $request->param('id');
        $gateway = $this->manualGateways->forTenant($merchantId)->findScoped($id);

        if ($gateway === null) {
            $this->session->flashError('Gateway not found');
            return Response::redirect('/admin/gateways');
        }

        $gateway['input_fields'] = json_decode($gateway['input_fields'] ?? '[]', true);
        $gateway['colors'] = json_decode($gateway['colors'] ?? '{}', true);

        if ($request->method() === 'GET') {
            return $this->renderAdminPage('admin/gateways/edit-manual.twig', ['gateway' => $gateway, 'active_page' => 'gateways']);
        }

        $data = $request->post();
        $errors = $this->validateManualGateway($data, true);
        if (!empty($errors)) {
            return $this->renderAdminPage('admin/gateways/edit-manual.twig', ['gateway' => array_merge($gateway, $data), 'errors' => $errors, 'active_page' => 'gateways']);
        }

        $update = $this->buildGatewayRecord($data);
        $this->applyUploads($update);
        $this->manualGateways->forTenant($merchantId)->updateScoped($id, $update);
        $this->audit->log('gateway.updated', 'manual_gateway', $id, null, ['name' => $update['name'] ?? '']);

        $this->session->flashSuccess('Gateway updated!');
        return Response::redirect('/admin/gateways');
    }

    public function toggleStatus(Request $request): Response
    {
        $merchantId = $this->resolveMerchant($request);
        $id = (int) $request->param('id');
        $gateway = $this->manualGateways->forTenant($merchantId)->findScoped($id);
        if ($gateway !== null) {
            $newStatus = $gateway['status'] === 'active' ? 'inactive' : 'active';
            $this->manualGateways->forTenant($merchantId)->updateScoped($id, ['status' => $newStatus]);
            $this->audit->log('gateway.status_toggled', 'manual_gateway', $id, ['status' => $gateway['status']], ['status' => $newStatus]);
            $this->session->flashSuccess("Gateway {$newStatus}!");
        }
        return Response::redirect('/admin/gateways');
    }

    public function storeManual(Request $request): Response { return $this->createManual($request); }
    public function updateManual(Request $request): Response { return $this->editManual($request); }
    public function toggle(Request $request): Response { return $this->toggleStatus($request); }

    public function delete(Request $request): Response
    {
        $merchantId = $this->resolveMerchant($request);
        $gid = (int) $request->param('id');
        $this->manualGateways->forTenant($merchantId)->deleteScoped($gid);
        $this->audit->log('gateway.deleted', 'manual_gateway', $gid);
        $this->session->flashSuccess('Gateway deleted.');
        return Response::redirect('/admin/gateways');
    }

    // ── Extracted Helpers ─────────────────────────────────────────

    private function resolveMerchant(Request $request): int
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($request);
        return $brand->getActiveBrandId();
    }

    private function buildGatewayRecord(array $data): array
    {
        return [
            'name'             => InputSanitizer::string($data['name'] ?? ''),
            'instructions'     => $this->buildInstructionsJson($data['instructions'] ?? ''),
            'colors'           => $this->buildColorsJson($data),
            'input_fields'     => $this->buildFieldsJson($data['fields'] ?? []),
            'min_amount'       => InputSanitizer::decimal($data['min_amount'] ?? '0'),
            'max_amount'       => InputSanitizer::decimal($data['max_amount'] ?? '0'),
            'sms_verification' => isset($data['sms_verification']) ? 1 : 0,
        ];
    }

    private function buildColorsJson(array $data): string
    {
        return json_encode([
            'primary'   => $data['color_primary'] ?? '#E2136E',
            'secondary' => $data['color_secondary'] ?? '#FFFFFF',
            'text'      => $data['color_text'] ?? '#FFFFFF',
        ]);
    }

    private function buildInstructionsJson(string $raw): string
    {
        if (empty($raw)) return '{"steps":[]}';
        return json_encode(['steps' => array_filter(array_map('trim', explode("\n", $raw)))]);
    }

    private function applyUploads(array &$record): void
    {
        if (!empty($_FILES['logo']['tmp_name'])) {
            $record['logo_path'] = $this->fs->storeUpload($_FILES['logo'], 'gateways');
        }
        if (!empty($_FILES['qr_code']['tmp_name'])) {
            $record['qr_code_path'] = $this->fs->storeUpload($_FILES['qr_code'], 'gateways');
        }
    }

    private function validateManualGateway(array $data, bool $isEdit = false): array
    {
        $errors = [];
        if (empty($data['name'])) $errors[] = 'Gateway name is required';
        if (!$isEdit && empty($data['slug'])) $errors[] = 'Slug is required';
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
}
