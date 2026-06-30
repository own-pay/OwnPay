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
        if ($secret === '') {
            $secret = $req->header('X-Cron-Secret');
        }
        if ($secret === '') {
            $authHeader = $req->header('Authorization');
            if (str_starts_with(strtolower($authHeader), 'bearer ')) {
                $secret = substr($authHeader, 7);
            }
        }

        $settingsRepo = $this->c->has(\OwnPay\Repository\SettingsRepository::class) ? $this->c->get(\OwnPay\Repository\SettingsRepository::class) : null;
        if (!$settingsRepo instanceof \OwnPay\Repository\SettingsRepository) {
            $settingsRepo = null;
        }
        $dbSecret = ($settingsRepo !== null) ? $settingsRepo->get('general', 'cron_secret') : null;
        
        $configApp = $this->c->get('config.app');
        $configCronSecret = (is_array($configApp) && isset($configApp['cron_secret']) && is_string($configApp['cron_secret'])) ? $configApp['cron_secret'] : '';
        
        $envSecret = getenv('CRON_SECRET');
        $dbSecretStr = is_string($dbSecret) ? $dbSecret : '';
        $expected = (is_string($envSecret) && $envSecret !== '') ? $envSecret : ($dbSecretStr !== '' ? $dbSecretStr : $configCronSecret);
 
        if ($secret === '' || !hash_equals($expected, $secret)) {
            return Response::json(['error' => 'Invalid secret'], 401);
        }
 
        // 2. Run cron jobs
        $runner = $this->c->get(\OwnPay\Cron\CronJobRunner::class);
        if (!$runner instanceof \OwnPay\Cron\CronJobRunner) {
            throw new \RuntimeException('CronJobRunner service not found.');
        }
        $results = $runner->run();
        $count = count($results);

        return Response::plain("OK: {$count} jobs run");
    }
}
