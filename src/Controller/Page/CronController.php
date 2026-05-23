<?php
declare(strict_types=1);

namespace OwnPay\Controller\Page;

use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

/**
 * Class CronController
 *
 * Handles HTTP requests to trigger background cron jobs using a security secret.
 *
 * @package OwnPay\Controller\Page
 */
final class CronController
{
    /**
     * @var Container The dependency injection container.
     */
    private Container $c;

    /**
     * CronController constructor.
     *
     * @param Container $c The DI container.
     */
    public function __construct(Container $c)
    {
        $this->c = $c;
    }

    /**
     * Triggers the background cron execution pipeline.
     *
     * GET /cron/{secret}
     *
     * @param Request $req The incoming HTTP request.
     * @return Response The HTTP response confirming how many jobs ran.
     */
    public function run(Request $req): Response
    {
        // 1. Validate secret against env/config/db
        $secret = $req->param('secret');
        $settingsRepo = $this->c->has(\OwnPay\Repository\SettingsRepository::class) ? $this->c->get(\OwnPay\Repository\SettingsRepository::class) : null;
        $dbSecret = $settingsRepo ? $settingsRepo->get('general', 'cron_secret') : null;
        $expected = getenv('CRON_SECRET') ?: $dbSecret ?: $this->c->get('config.app')['cron_secret'] ?? '';

        if ($secret !== $expected || empty($secret)) {
            return Response::json(['error' => 'Invalid secret'], 401);
        }

        // 2. Run cron jobs
        $runner = $this->c->get(\OwnPay\Cron\CronJobRunner::class);
        $results = $runner->run();
        $count = count($results);

        return Response::plain("OK: {$count} jobs run");
    }
}
