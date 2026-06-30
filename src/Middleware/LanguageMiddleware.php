<?php
declare(strict_types=1);

namespace OwnPay\Middleware;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Service\System\TranslationService;
use OwnPay\Service\Admin\AdminSession;
use OwnPay\Core\Database;

/**
 * Middleware resolving the active locale context for administrative requests.
 */
final class LanguageMiddleware
{
    private Container $container;

    /**
     * Constructs a new LanguageMiddleware instance.
     *
     * @param Container $container The container instance.
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Handles the request translation resolution.
     *
     * @param Request $request The request instance.
     * @param callable(Request): Response $next Next handler.
     * @return Response
     */
    public function handle(Request $request, callable $next): Response
    {
        /** @var TranslationService $trans */
        $trans = $this->container->get(TranslationService::class);

        // 1. Resolve default global language (fallback)
        $locale = $trans->getDefaultLanguage();

        // 2. If staff is logged in, check override preference in DB
        $session = $this->container->has(AdminSession::class) ? $this->container->get(AdminSession::class) : null;
        if ($session instanceof AdminSession) {
            $userId = $session->userId();
            if ($userId !== null) {
                try {
                    /** @var Database $db */
                    $db = $this->container->get(Database::class);
                    $userVal = $db->fetchOne("SELECT language FROM op_merchant_users WHERE id = :id AND status = 'active'", ['id' => $userId]);
                    if ($userVal !== null) {
                        $langCode = $userVal['language'] ?? null;
                        if (is_string($langCode) && $langCode !== '') {
                            $locale = $langCode;
                        }
                    }
                } catch (\Throwable) {
                    // Ignore DB lookup errors in bootstrapping/tests
                }
            }
        }

        // Bind active locale context
        $trans->setLocale($locale);

        return $next($request);
    }
}
