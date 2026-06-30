<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\WebhookRepository;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Service\Brand\BrandContext;

/**
 * Controller managing brand webhooks CRUD actions.
 */
final class WebhookController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private WebhookRepository $webhookRepo;

    /**
     * WebhookController constructor.
     */
    public function __construct(Container $c, AdminSession $session, WebhookRepository $webhookRepo)
    {
        $this->c = $c;
        $this->session = $session;
        $this->webhookRepo = $webhookRepo;
    }

    /**
     * Stores a new webhook endpoint or updates an existing one.
     */
    public function store(Request $req): Response
    {
        $brand = $this->c->get(BrandContext::class);
        if (!$brand instanceof BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

        $idVal = $req->post('id', '0');
        $id = is_numeric($idVal) ? (int)$idVal : 0;

        $urlVal = $req->post('url', '');
        $url = is_string($urlVal) ? trim($urlVal) : '';

        $secretVal = $req->post('secret', '');
        $secret = is_string($secretVal) ? trim($secretVal) : '';

        $eventsPost = $req->post('events');
        $events = is_array($eventsPost) ? $eventsPost : [];

        if ($url === '' || !\OwnPay\Security\UrlValidator::isValidWebhookUrl($url)) {
            $this->session->flashError('A valid Webhook URL is required.');
            return Response::redirect('/admin/developer#webhooks');
        }

        if ($secret === '') {
            $secret = bin2hex(random_bytes(16));
        }

        $data = [
            'merchant_id' => $mid,
            'url'         => $url,
            'secret'      => $secret,
            'events'      => json_encode($events),
            'status'      => 'active'
        ];

        $scopedRepo = $this->webhookRepo->forTenant($mid);

        if ($id > 0) {
            $scopedRepo->updateScoped($id, $data);
            $this->session->flashSuccess('Webhook endpoint updated successfully.');
        } else {
            $scopedRepo->createScoped($data);
            $this->session->flashSuccess('Webhook endpoint created successfully.');
        }

        return Response::redirect('/admin/developer#webhooks');
    }

    /**
     * Deletes a webhook endpoint.
     */
    public function delete(Request $req): Response
    {
        $brand = $this->c->get(BrandContext::class);
        if (!$brand instanceof BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

        $idVal = $req->param('id');
        $id = is_numeric($idVal) ? (int)$idVal : 0;

        $scopedRepo = $this->webhookRepo->forTenant($mid);
        $scopedRepo->deleteScoped($id);

        $this->session->flashSuccess('Webhook endpoint deleted successfully.');
        return Response::redirect('/admin/developer#webhooks');
    }

    /**
     * Toggles status (active/inactive) of a webhook endpoint.
     */
    public function toggle(Request $req): Response
    {
        $brand = $this->c->get(BrandContext::class);
        if (!$brand instanceof BrandContext) {
            throw new \RuntimeException('BrandContext service unavailable');
        }
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();
        if ($mid === null) {
            throw new \RuntimeException('No active brand found.');
        }

        $idVal = $req->param('id');
        $id = is_numeric($idVal) ? (int)$idVal : 0;

        $scopedRepo = $this->webhookRepo->forTenant($mid);
        $webhook = $scopedRepo->findScoped($id);

        if ($webhook) {
            $currentStatus = $webhook['status'] ?? 'active';
            $newStatus = $currentStatus === 'active' ? 'inactive' : 'active';
            $scopedRepo->updateScoped($id, ['status' => $newStatus]);
            $this->session->flashSuccess("Webhook endpoint set to {$newStatus}.");
        }

        return Response::redirect('/admin/developer#webhooks');
    }
}
