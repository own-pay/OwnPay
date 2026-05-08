<?php
declare(strict_types=1);

namespace OwnPay\Controller\Admin;

use OwnPay\Container;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Repository\SmsTemplateRepository;
use OwnPay\Repository\CommLogRepository;

final class SmsTemplateAdminController
{
    use AdminPageTrait;

    private Container $c;
    private AdminSession $session;
    private SmsTemplateRepository $tplRepo;
    private CommLogRepository $commRepo;

    public function __construct(Container $c, AdminSession $session, SmsTemplateRepository $tplRepo, CommLogRepository $commRepo)
    {
        $this->c        = $c;
        $this->session  = $session;
        $this->tplRepo  = $tplRepo;
        $this->commRepo = $commRepo;
    }

    public function index(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $templates = $this->tplRepo->listForAdmin($mid);
        $queue     = $this->commRepo->listSmsQueue($mid);
        $stats     = $this->commRepo->getSmsQueueStats($mid);

        return $this->renderAdminPage('admin/sms-center/index.twig', [
            'sms_templates' => $templates,
            'sms_queue'     => $queue,
            'queue_stats'   => $stats,
            'active_page'   => 'sms-center',
        ]);
    }

    public function edit(Request $req): Response
    {
        $id = (int) $req->param('id');
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $tpl = $this->tplRepo->findForAdmin($id, $mid);
        if (!$tpl) {
            $this->session->flashError('Not found');
            return Response::redirect('/admin/sms-center');
        }

        if ($req->method() === 'POST') {
            $this->tplRepo->updateTemplate($id, $mid, $req->post('body', ''), (bool) $req->post('enabled'));
            $this->session->flashSuccess('Template updated');
            return Response::redirect('/admin/sms-center');
        }

        return $this->renderAdminPage('admin/sms-center/edit.twig', ['template' => $tpl, 'active_page' => 'sms-center']);
    }

    public function templates(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $templates = $this->tplRepo->listForAdmin($mid, 'event ASC');
        return $this->renderAdminPage('admin/sms-center/index.twig', [
            'sms_templates' => $templates,
            'active_page'   => 'sms-center',
        ]);
    }

    public function save(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $templates = $req->post('templates', []);
        foreach ($templates as $id => $data) {
            $this->tplRepo->updateTemplate((int) $id, $mid, $data['body'] ?? '', isset($data['enabled']));
        }

        $this->session->flashSuccess('Templates saved');
        return Response::redirect('/admin/sms-center/templates');
    }

    public function queue(Request $req): Response
    {
        $brand = $this->c->get(\OwnPay\Service\Brand\BrandContext::class);
        $brand->resolveFromRequest($req);
        $mid = $brand->getActiveBrandId();

        $queue = $this->commRepo->listSmsQueue($mid, 100);
        $stats = $this->commRepo->getSmsQueueStats($mid);

        return $this->renderAdminPage('admin/sms-center/index.twig', [
            'sms_queue'   => $queue,
            'queue_stats' => $stats,
            'active_page' => 'sms-center',
        ]);
    }
}
