<?php
declare(strict_types=1);

namespace OwnPay\Controller\Page;
use OwnPay\Container;
use OwnPay\Http\Request;
use OwnPay\Http\Response;

final class CronController
{
    private Container $c;

    public function __construct(Container $c)
    {
        $this->c = $c;
    }

    public function run(Request $req): Response
    {
        // 1. Validate secret against env/config
        $secret = $req->param('secret');
        $expected = getenv('CRON_SECRET') ?: $this->c->get('config.app')['cron_secret'] ?? '';

        if ($secret !== $expected) {
            return Response::json(['error' => 'Invalid secret'], 401);
        }

        // 2. Run cron jobs
        $runner = $this->c->get(\OwnPay\Cron\CronJobRunner::class);
        $count = $runner->run();

        return Response::plain("OK: {$count} jobs run");
    }
}
