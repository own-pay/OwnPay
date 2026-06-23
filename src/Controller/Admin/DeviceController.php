<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\SettingsRepository;
use OwnPay\Service\System\AuditService;

/**
 * Class DeviceController
 *
 * Handles administrative companion-app device pairing, pairing code generation, and device revocations.
 *
 * @package OwnPay\Controller\Admin
 */
final class DeviceController
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
     * @var SettingsRepository The settings repository.
     */
    private SettingsRepository $settingsRepo;

    /**
     * @var AuditService The application audit logging service.
     */
    private AuditService $audit;

    /**
     * DeviceController constructor.
     *
     * @param Container          $c            The dependency injection container.
     * @param AdminSession       $session      The administrative session service.
     * @param SettingsRepository $settingsRepo The settings repository.
     * @param AuditService       $audit        The application audit logging service.
     */
    public function __construct(Container $c, AdminSession $session, SettingsRepository $settingsRepo, AuditService $audit)
    {
        $this->c = $c;
        $this->session = $session;
        $this->settingsRepo = $settingsRepo;
        $this->audit = $audit;
    }

    /**
     * Resolves the DevicePairingService instance from the dependency injection container.
     *
     * @return \OwnPay\Service\Device\DevicePairingService|null The pairing service instance, or null if resolution fails.
     */
    private function getService(): ?\OwnPay\Service\Device\DevicePairingService
    {
        try {
            $svc = $this->c->get(\OwnPay\Service\Device\DevicePairingService::class);
            return $svc instanceof \OwnPay\Service\Device\DevicePairingService ? $svc : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Displays a list of all devices paired with the active brand.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The devices listing overview response.
     */
    public function index(Request $req): Response
    {
        $svc = $this->getService();
        
        $smsSettings = $this->settingsRepo->getGroup('sms');
        $sms_positive_keywords = $smsSettings['positive_keywords'] ?? '';
        $sms_negative_keywords = $smsSettings['negative_keywords'] ?? '';
        $sms_filter_rules_check_interval_hours = $smsSettings['filter_rules_check_interval_hours'] ?? '24';

        if ($svc === null) {
            return $this->renderAdminPage('admin/devices/index.twig', [
                'devices'      => [],
                'stats'        => ['total' => 0, 'active' => 0, 'revoked' => 0],
                'active_page'  => 'devices',
                'config_error' => 'Device pairing requires ENCRYPTION_KEY and JWT_SECRET in your .env file. Run the installer or add them manually.',
                'sms_positive_keywords' => $sms_positive_keywords,
                'sms_negative_keywords' => $sms_negative_keywords,
                'sms_filter_rules_check_interval_hours' => $sms_filter_rules_check_interval_hours,
            ]);
        }

        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

        $list = $svc->listDevices($mid);

        $stats = [
            'total'   => count($list),
            'active'  => count(array_filter($list, fn($d) => ($d['status'] ?? '') === 'active')),
            'revoked' => count(array_filter($list, fn($d) => ($d['status'] ?? '') === 'revoked')),
        ];

        return $this->renderAdminPage('admin/devices/index.twig', [
            'devices'     => $list,
            'stats'       => $stats,
            'active_page' => 'devices',
            'sms_positive_keywords' => $sms_positive_keywords,
            'sms_negative_keywords' => $sms_negative_keywords,
            'sms_filter_rules_check_interval_hours' => $sms_filter_rules_check_interval_hours,
        ]);
    }

    /**
     * Generates a temporary pairing OTP for companion mobile devices.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The OTP response in JSON.
     */
    public function generateOtp(Request $req): Response
    {
        $svc = $this->getService();
        if ($svc === null) {
            return Response::json(['success' => false, 'error' => 'Device service not configured']);
        }

        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

        try {
            // Bind the OTP to the admin who created it. Without this the pairing
            // token's created_by is null and the stateless pairing API would fall
            // back to the brand's first superadmin — a privilege escalation where
            // a low-privileged staffer's device gets a superadmin-scoped token.
            $adminId = $this->session->userId();
            if ($adminId === null) {
                return Response::json(['success' => false, 'error' => 'Not authenticated']);
            }
            $result = $svc->generatePairingOtp($mid, $adminId);
            if (!isset($result['otp'])) {
                return Response::json(['success' => false, 'error' => $result['error']]);
            }

            // Generate QR Code SVG base64 URI
            $urlService = $this->c->get(\OwnPay\Service\Domain\DomainUrlService::class);
            if (!$urlService instanceof \OwnPay\Service\Domain\DomainUrlService) {
                throw new \RuntimeException('DomainUrlService unavailable');
            }
            $serverUrl = $urlService->resolveBaseUrl($mid, $req);

            $qrPayload = json_encode([
                'server_url' => $serverUrl,
                'otp'        => $result['otp']
            ]);

            if (!is_string($qrPayload)) {
                return Response::json(['success' => false, 'error' => 'Failed to serialize QR payload']);
            }

            $options = new \chillerlan\QRCode\QROptions([
                'version'    => 5,
                'outputType' => \chillerlan\QRCode\QRCode::OUTPUT_MARKUP_SVG,
                'eccLevel'   => \chillerlan\QRCode\QRCode::ECC_L,
            ]);
            $qrcode = new \chillerlan\QRCode\QRCode($options);
            $qrSvg = $qrcode->render($qrPayload);

            return Response::json([
                'success'    => true,
                'otp'        => $result['otp'],
                'expires_in' => $result['expires_in'],
                'qr_svg'     => $qrSvg,
                'csrf_token' => \OwnPay\Security\SecurityHelpers::csrfToken(),
            ]);
        } catch (\Throwable $e) {
            return Response::json(['success' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Revokes access permissions for a specific companion mobile device.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response.
     */
    public function revoke(Request $req): Response
    {
        $svc = $this->getService();
        if ($svc === null) {
            $this->session->flashError('Device service not configured.');
            return Response::redirect('/admin/devices');
        }

        $uuid = (string) $req->param('id');
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

        $svc->revoke($uuid, $mid);
        $this->session->flashSuccess('Device revoked');
        return Response::redirect('/admin/devices');
    }

    /**
     * Revokes access permissions for multiple companion mobile devices in a single batch operation.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response.
     */
    public function bulkRevoke(Request $req): Response
    {
        $svc = $this->getService();
        if ($svc === null) {
            $this->session->flashError('Device service not configured.');
            return Response::redirect('/admin/devices');
        }

        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

        $idsVal = $req->post('device_ids', []);
        $ids = is_array($idsVal) ? $idsVal : [];
        if (!empty($ids)) {
            $count = 0;
            foreach ($ids as $uuid) {
                if (is_string($uuid)) {
                    $svc->revoke($uuid, $mid);
                    $count++;
                }
            }
            $this->session->flashSuccess($count . ' devices revoked.');
        } else {
            $this->session->flashError('No devices selected.');
        }
        return Response::redirect('/admin/devices');
    }

    /**
     * Checks if any active device is paired for the current brand.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The JSON status check response.
     */
    public function checkStatus(Request $req): Response
    {
        $svc = $this->getService();
        if ($svc === null) {
            return Response::json(['success' => false, 'error' => 'Device service not configured']);
        }

        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

        $devices = $svc->listDevices($mid);
        $activeCount = count(array_filter($devices, fn($d) => ($d['status'] ?? '') === 'active'));

        return Response::json([
            'success' => true,
            'paired'  => $activeCount > 0
        ]);
    }

    /**
     * Saves companion mobile app settings.
     *
     * @param Request $req The incoming HTTP request.
     *
     * @return Response The redirect response.
     */
    public function saveSettings(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        if (!$brand instanceof \OwnPay\Service\Brand\BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

        $postData = $req->post();
        $data = is_array($postData) ? $postData : [];

        // Save settings under 'sms' group
        if (isset($data['sms_positive_keywords'])) {
            $this->settingsRepo->set('sms', 'positive_keywords', \OwnPay\Service\System\InputSanitizer::string(is_scalar($data['sms_positive_keywords']) ? (string) $data['sms_positive_keywords'] : ''));
        }
        if (isset($data['sms_negative_keywords'])) {
            $this->settingsRepo->set('sms', 'negative_keywords', \OwnPay\Service\System\InputSanitizer::string(is_scalar($data['sms_negative_keywords']) ? (string) $data['sms_negative_keywords'] : ''));
        }
        if (isset($data['sms_filter_rules_check_interval_hours'])) {
            $this->settingsRepo->set('sms', 'filter_rules_check_interval_hours', \OwnPay\Service\System\InputSanitizer::string(is_scalar($data['sms_filter_rules_check_interval_hours']) ? (string) $data['sms_filter_rules_check_interval_hours'] : '24'));
        }

        $this->audit->log('brand.settings.saved', 'devices', $mid, null, ['tab' => 'mobile']);
        $this->session->flashSuccess('Mobile settings saved successfully.');

        return Response::redirect('/admin/devices#tab-settings');
    }
}
