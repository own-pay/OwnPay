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
 * Class GatewayController
 *
 * Administrative portal controller handling discovery, configuration, toggling, and CRUD management
 * of payment gateways, encompassing both dynamic API integrations and custom manual (offline) options.
 *
 * @package OwnPay\Controller\Admin
 */
final class GatewayController
{
    use AdminPageTrait;

    /**
     * @var Container The dependency injection container.
     */
    private Container $c;

    /**
     * @var AdminSession The administrative session service.
     */
    private AdminSession $session;

    /**
     * @var ManualGatewayRepository The manual gateway repository.
     */
    private ManualGatewayRepository $manualGateways;

    /**
     * @var GatewayConfigRepository The API gateway configuration repository.
     */
    private GatewayConfigRepository $apiConfigs;

    /**
     * @var FilesystemService The filesystem operations service.
     */
    private FilesystemService $fs;

    /**
     * @var AuditService The application audit logging service.
     */
    private AuditService $audit;

    /**
     * GatewayController constructor.
     *
     * @param Container               $c              The dependency injection container.
     * @param AdminSession            $session        The administrative session service.
     * @param ManualGatewayRepository $manualGateways The manual gateway repository.
     * @param GatewayConfigRepository $apiConfigs     The API gateway configuration repository.
     * @param FilesystemService       $fs             The filesystem operations service.
     * @param AuditService            $audit          The application audit logging service.
     */
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

    /**
     * Displays the dashboard interface listing both API and manual gateways for the active brand.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The gateways dashboard page response.
     */
    public function index(Request $request): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service not found.');
        }
        $brand->resolveFromRequest($request);
        $merchantId = $brand->getActiveBrandId();
        if ($merchantId === null) {
            throw new \RuntimeException('Brand ID not resolved.');
        }

        $manualGateways = $this->manualGateways->forTenant($merchantId)->listAll();

        foreach ($manualGateways as &$mg) {
            $inputFieldsVal = $mg['input_fields'] ?? '[]';
            $mg['input_fields'] = json_decode(is_string($inputFieldsVal) ? $inputFieldsVal : '[]', true);
            $colorsVal = $mg['colors'] ?? '{}';
            $mg['colors'] = json_decode(is_string($colorsVal) ? $colorsVal : '{}', true);
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
        $paginated = $pluginRepo->paginate(1, 200);
        foreach ($paginated['items'] as $p) {
            if (isset($p['slug']) && is_string($p['slug'])) {
                $installedPlugins[$p['slug']] = $p;
            }
        }

        // Also get gateway configs (credentials) per slug
        $configuredGateways = [];
        foreach ($this->apiConfigs->forTenant($merchantId)->listActive() as $g) {
            if (isset($g['slug']) && is_string($g['slug'])) {
                $configuredGateways[$g['slug']] = $g;
            }
        }

        $apiGateways = [];
        foreach ($discovered as $manifest) {
            if ($manifest->type !== 'gateway') {
                continue;
            }

            $installed = $installedPlugins[$manifest->slug] ?? null;
            $configured = $configuredGateways[$manifest->slug] ?? null;

            $isBrandActive = $pluginRepo->isPluginActiveForBrand($manifest->slug, $merchantId);

            // Determine display status
            if ($installed === null) {
                $status = 'uninstalled';
            } elseif ($isBrandActive) {
                $status = $configured ? 'active' : 'installed'; // active+configured vs just activated
            } else {
                $status = in_array($installed['status'], ['trashed', 'error'], true) ? $installed['status'] : 'inactive';
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

    /**
     * Renders creation form or handles dynamic submission for creating a new manual gateway.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The gateway creation page or redirect response.
     */
    public function createManual(Request $request): Response
    {
        if ($request->method() === 'GET') {
            return $this->renderAdminPage('admin/gateways/create-manual.twig', ['old' => [], 'active_page' => 'gateways']);
        }

        $merchantId = $this->resolveMerchant($request);
        $postData = $request->post();
        $data = is_array($postData) ? $postData : [];
        $errors = $this->validateManualGateway($data);

        if (!empty($errors)) {
            return $this->renderAdminPage('admin/gateways/create-manual.twig', ['old' => $data, 'errors' => $errors, 'active_page' => 'gateways']);
        }

        $record = $this->buildGatewayRecord($data);
        $slugVal = $data['slug'] ?? '';
        $record['slug']   = InputSanitizer::slug(is_string($slugVal) ? $slugVal : '');
        $record['status'] = 'active';
        $this->applyUploads($record);

        $gid = $this->manualGateways->forTenant($merchantId)->createScoped($record);
        $this->audit->log('gateway.created', 'manual_gateway', (int) $gid, null, ['name' => $record['name']]);
        $this->session->flashSuccess('Gateway created!');
        return Response::redirect('/admin/gateways');
    }

    /**
     * Renders edit form or handles updating an existing manual gateway under the active brand context.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The edit page or redirect response.
     */
    public function editManual(Request $request): Response
    {
        $merchantId = $this->resolveMerchant($request);
        $id = (int) $request->param('id');
        $gateway = $this->manualGateways->forTenant($merchantId)->findScoped($id);

        if ($gateway === null) {
            $this->session->flashError('Gateway not found');
            return Response::redirect('/admin/gateways');
        }

        $inputFieldsVal = $gateway['input_fields'] ?? '[]';
        $gateway['input_fields'] = json_decode(is_string($inputFieldsVal) ? $inputFieldsVal : '[]', true);
        $colorsVal = $gateway['colors'] ?? '{}';
        $gateway['colors'] = json_decode(is_string($colorsVal) ? $colorsVal : '{}', true);

        if ($request->method() === 'GET') {
            return $this->renderAdminPage('admin/gateways/edit-manual.twig', ['gateway' => $gateway, 'active_page' => 'gateways']);
        }

        $postData = $request->post();
        $data = is_array($postData) ? $postData : [];
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

    /**
     * Toggles the active/inactive state of a specific manual gateway.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
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

    /**
     * Alias route handler for storing a new manual gateway.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The API/page response.
     */
    public function storeManual(Request $request): Response { return $this->createManual($request); }

    /**
     * Alias route handler for updating a manual gateway.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The API/page response.
     */
    public function updateManual(Request $request): Response { return $this->editManual($request); }

    /**
     * Alias route handler for toggling manual gateway status.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The API/page response.
     */
    public function toggle(Request $request): Response { return $this->toggleStatus($request); }

    /**
     * Permanently deletes a manual gateway.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return Response The HTTP redirect response.
     */
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

    /**
     * Resolves the active merchant context ID from the request.
     *
     * @param Request $request The incoming HTTP request.
     *
     * @return int The resolved merchant ID.
     */
    private function resolveMerchant(Request $request): int
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service not found.');
        }
        $brand->resolveFromRequest($request);
        $merchantId = $brand->getActiveBrandId();
        if ($merchantId === null) {
            throw new \RuntimeException('Merchant ID not resolved.');
        }
        return $merchantId;
    }

    /**
     * Formats incoming POST data into a normalized database record array for manual gateways.
     *
     * @param array<string, mixed> $data Raw input data from request payload.
     *
     * @return array<string, mixed> Normalized manual gateway database payload.
     */
    private function buildGatewayRecord(array $data): array
    {
        $nameVal = $data['name'] ?? '';
        $instructionsVal = $data['instructions'] ?? '';
        $fieldsVal = $data['fields'] ?? [];
        $minVal = $data['min_amount'] ?? '0';
        $maxVal = $data['max_amount'] ?? '0';

        /** @var array<int, array<string, mixed>> $fieldsArray */
        $fieldsArray = [];
        if (is_array($fieldsVal)) {
            foreach ($fieldsVal as $fv) {
                if (is_array($fv)) {
                    $fieldsArray[] = $fv;
                }
            }
        }

        return [
            'name'             => InputSanitizer::string(is_string($nameVal) ? $nameVal : ''),
            'instructions'     => $this->buildInstructionsJson(is_string($instructionsVal) ? $instructionsVal : ''),
            'colors'           => $this->buildColorsJson($data),
            'input_fields'     => $this->buildFieldsJson($fieldsArray),
            'min_amount'       => InputSanitizer::decimal(is_string($minVal) ? $minVal : '0'),
            'max_amount'       => InputSanitizer::decimal(is_string($maxVal) ? $maxVal : '0'),
            'sms_verification' => isset($data['sms_verification']) ? 1 : 0,
        ];
    }

    /**
     * Builds gateway brand color settings as a JSON string.
     *
     * @param array<string, mixed> $data Raw input data from request payload.
     * @return string Serialized colors JSON.
     */
    private function buildColorsJson(array $data): string
    {
        $json = json_encode([
            'primary'   => $data['color_primary'] ?? '#E2136E',
            'secondary' => $data['color_secondary'] ?? '#FFFFFF',
            'text'      => $data['color_text'] ?? '#FFFFFF',
        ]);
        return is_string($json) ? $json : '{}';
    }

    /**
     * Cleans and formats step-by-step raw multi-line instruction strings into JSON.
     *
     * @param string $raw Unfiltered raw instruction string separated by line breaks.
     *
     * @return string Serialized instruction steps mapping.
     */
    private function buildInstructionsJson(string $raw): string
    {
        if (empty($raw)) {
            return '{"steps":[]}';
        }
        $json = json_encode(['steps' => array_filter(array_map('trim', explode("\n", $raw)))]);
        return is_string($json) ? $json : '{"steps":[]}';
    }

    /**
     * Processes and stores uploaded merchant gateways branding files (logos, QRs).
     *
     * @param array<string, mixed> $record Reference to the gateway payload array to inject storage paths.
     *
     * @return void
     */
    private function applyUploads(array &$record): void
    {
        if (isset($_FILES['logo']) && is_array($_FILES['logo']) && !empty($_FILES['logo']['tmp_name']) && is_string($_FILES['logo']['tmp_name'])) {
            /** @var array{error: int, name: string, tmp_name: string} $file */
            $file = $_FILES['logo'];
            $record['logo_path'] = $this->fs->storeUpload($file, 'gateways');
        }
        if (isset($_FILES['qr_code']) && is_array($_FILES['qr_code']) && !empty($_FILES['qr_code']['tmp_name']) && is_string($_FILES['qr_code']['tmp_name'])) {
            /** @var array{error: int, name: string, tmp_name: string} $file */
            $file = $_FILES['qr_code'];
            $record['qr_code_path'] = $this->fs->storeUpload($file, 'gateways');
        }
    }

    /**
     * Validates manual gateway inputs.
     *
     * @param array<string, mixed> $data   The input data.
     * @param bool                 $isEdit Flag indicating if it is an update operation.
     *
     * @return string[] Array of validation error strings.
     */
    private function validateManualGateway(array $data, bool $isEdit = false): array
    {
        $errors = [];
        if (empty($data['name'])) {
            $errors[] = 'Gateway name is required';
        }
        if (!$isEdit && empty($data['slug'])) {
            $errors[] = 'Slug is required';
        }
        $slugVal = $data['slug'] ?? '';
        if (!$isEdit && !empty($slugVal) && is_string($slugVal) && !preg_match('/^[a-z0-9\-]+$/', $slugVal)) {
            $errors[] = 'Slug must be lowercase alphanumeric with hyphens only';
        }
        return $errors;
    }

    /**
     * Validates and compiles customizable manual gateway form fields to serialized JSON structure.
     *
     * @param array<int, array<string, mixed>> $fields Configurable fields array.
     *
     * @return string Serialized custom field configuration mappings.
     */
    private function buildFieldsJson(array $fields): string
    {
        $clean = [];
        foreach ($fields as $field) {
            if (!empty($field['name']) && is_string($field['name']) && !empty($field['label']) && is_string($field['label'])) {
                $typeVal = $field['type'] ?? 'text';
                $clean[] = [
                    'name'     => InputSanitizer::slug($field['name']),
                    'label'    => InputSanitizer::string($field['label']),
                    'type'     => is_string($typeVal) ? $typeVal : 'text',
                    'required' => !empty($field['required']),
                ];
            }
        }
        $json = json_encode($clean);
        return is_string($json) ? $json : '[]';
    }
}
