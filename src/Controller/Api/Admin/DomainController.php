<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Domain\DomainService;

final class DomainController
{
    private Container $c;
    private DomainService $domains;
    public function __construct(Container $c, DomainService $domains) { $this->c = $c; $this->domains = $domains; }

    public function verify(Request $req): Response
    {
        $mid = (int) $req->getAttribute('merchant_id');
        $body = $req->json();
        $domainId = (int) ($body['domain_id'] ?? 0);
        if ($domainId <= 0) return Response::json(['success' => false, 'error' => 'domain_id required'], 422);

        $result = $this->domains->verifyDomain($mid, $domainId);
        return Response::json(['success' => true, 'verified' => $result]);
    }
}
