<?php
declare(strict_types=1);

namespace OwnPay\Modules\Addons\ExampleKit;

use OwnPay\Container;
use OwnPay\Event\EventManager;
use OwnPay\Http\Request;
use OwnPay\Http\Response;
use OwnPay\Plugin\Capability;
use OwnPay\Plugin\PluginInterface;
use OwnPay\Modules\Addons\ExampleKit\Service\PingTracker;

/**
 * Example Kit - a reference addon exercising every plugin extension point in one place:
 *  - multi-file classes autoloaded from this plugin's directory (Service/, Cron/);
 *  - manifest-declared routes, including an authenticated admin route (see manifest.json);
 *  - a manifest-declared cron job (Cron/HeartbeatJob);
 *  - a declarative admin-menu entry (manifest "admin_menu");
 *  - an EventManager hook listener (payment.transaction.completed).
 *
 * It is inactive until an owner activates it, so it contributes no routes, cron, or menu until then.
 */
final class Plugin implements PluginInterface
{
    private PingTracker $tracker;
    private ?Container $container = null;

    public function __construct()
    {
        // Instantiated via `new Plugin()` at load time. Initialise the multi-file collaborator here
        // (boot() is not guaranteed to have run before the first hook fires).
        $this->tracker = new PingTracker();
    }

    public static function metadata(): array
    {
        return [
            'name'        => 'Example Kit',
            'slug'        => 'example-kit',
            'version'     => '1.0.0',
            'description' => 'Reference addon exercising routes, cron, admin menu, hooks, and multi-file classes.',
            'author'      => 'OwnPay Core',
            'type'        => 'addon',
        ];
    }

    public function capabilities(): array
    {
        return [Capability::ADDON, Capability::HOOKS, Capability::CRON];
    }

    public function register(EventManager $events, Container $container): void
    {
        // React to completed payments - delegates to the autoloaded PingTracker collaborator.
        $events->addAction('payment.transaction.completed', [$this, 'onTransactionCompleted'], 20);
    }

    public function boot(Container $container): void
    {
        $this->container = $container;
    }

    public function deactivate(Container $container): void
    {
        // Nothing to tear down - routes/cron/menu are only wired while the plugin is active.
    }

    public function uninstall(Container $container): void
    {
        // This example creates no tables or settings, so there is nothing to purge.
    }

    public function fields(): array
    {
        return [
            [
                'name'    => 'greeting',
                'label'   => 'Ping Greeting',
                'type'    => 'text',
                'default' => 'pong',
            ],
        ];
    }

    /**
     * Public route handler: GET /plugins/example-kit/ping (defaults to the api-public group).
     *
     * @param Request $req The incoming HTTP request.
     * @return Response JSON heartbeat including the number of completed payments seen this process.
     */
    public function ping(Request $req): Response
    {
        return Response::json([
            'ok'       => true,
            'greeting' => $this->configuredGreeting(),
            'pings'    => $this->tracker->count(),
        ]);
    }

    /**
     * Reads the plugin's configured greeting from scoped settings - demonstrates pulling a core
     * service (SettingsRepository) from the DI container captured in boot().
     *
     * @return string The configured greeting, or the default.
     */
    private function configuredGreeting(): string
    {
        if ($this->container === null || !$this->container->has(\OwnPay\Repository\SettingsRepository::class)) {
            return 'pong';
        }
        $repo = $this->container->get(\OwnPay\Repository\SettingsRepository::class);
        if (!$repo instanceof \OwnPay\Repository\SettingsRepository) {
            return 'pong';
        }
        $value = $repo->getGroup('plugin.example-kit')['greeting'] ?? '';
        return $value !== '' ? $value : 'pong';
    }

    /**
     * Authenticated route handler: GET /admin/example-kit (declared with the `admin` middleware group).
     *
     * @param Request $req The incoming HTTP request.
     * @return Response A minimal admin page body.
     */
    public function adminHome(Request $req): Response
    {
        return Response::html(
            '<h1>Example Kit</h1>'
            . '<p>Served by a plugin route protected by the <code>admin</code> middleware group.</p>'
            . '<p>Completed payments observed this process: ' . (string) $this->tracker->count() . '</p>'
        );
    }

    /**
     * Hook listener for payment.transaction.completed.
     *
     * @param array<string, mixed> $txn The completed transaction payload.
     * @return void
     */
    public function onTransactionCompleted(array $txn): void
    {
        $this->tracker->record();
    }

    /**
     * Exposes the collaborator so callers (and the integration test) can read the observed count.
     */
    public function tracker(): PingTracker
    {
        return $this->tracker;
    }
}
