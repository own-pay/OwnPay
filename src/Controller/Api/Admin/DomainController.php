<?php
declare(strict_types=1);

namespace OwnPay\Controller\Api\Admin;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\Domain\DomainService;

/**
 * Class DomainController
 *
 * Handles API actions related to domain verification.
 *
 * @package OwnPay\Controller\Api\Admin
 */
final class DomainController
{
    /**
     * @var Container The dependency injection container.
     */
    /** @phpstan-ignore property.onlyWritten */
    private Container $c;

    /**
     * @var DomainService The domain management service.
     */
    private DomainService $domains;

    /**
     * DomainController constructor.
     *
     * @param Container     $c       The DI container.
     * @param DomainService $domains The domain management service.
     */
    public function __construct(Container $c, DomainService $domains)
    {
        $this->c = $c;
        $this->domains = $domains;
    }

    /**
     * Verifies domain ownership and configuration.
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response indicating whether verification was successful.
     */
    public function verify(Request $req): Response
    {
        $midVal = $req->getAttribute('merchant_id');
        $mid = (is_int($midVal) || is_string($midVal)) ? (int) $midVal : 0;
        $body = $req->json();
        $bodyArr = is_array($body) ? $body : [];
        $domainIdVal = $bodyArr['domain_id'] ?? 0;
        $domainId = (is_int($domainIdVal) || is_string($domainIdVal)) ? (int) $domainIdVal : 0;
        if ($domainId <= 0) {
            return Response::apiError('DOMAIN_ID_REQUIRED', 'domain_id required', 'domain_id', 422);
        }

        // verifyDomain(int $domainId, int $merchantId) - params were swapped
        $result = $this->domains->verifyDomain($domainId, $mid);
        return Response::apiSuccess(['verified' => $result]);
    }
}
