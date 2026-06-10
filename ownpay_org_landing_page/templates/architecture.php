<?php
declare(strict_types=1);
include TEMPLATE_PATH . '/header.php';
?>

<section class="section page-hero">
    <div class="container reveal">
        <span class="badge mb-4">Engineering</span>
        <h1>Architecture Deep Dive</h1>
        <p style="max-width: 600px; margin: 0 auto;">Understand OwnPay's custom lightweight PHP 8.2+ service-oriented engine.</p>
    </div>
</section>

<section class="page-content">
    <div class="container reveal" style="max-width: 800px; text-align: left;">
        
        <div class="feature-card mb-6" style="padding: var(--space-8);">
            <h3 class="mb-4">Why a Custom MVC Framework?</h3>
            <p class="mb-4">
                OwnPay is built on a custom foundation rather than Laravel or Symfony. Offline payments handle high-frequency webhook callbacks, dynamic domain resolving, and secure plugins loading. We required a boot pipeline with zero dead code, reflection-based autowiring, and an event hook engine we owned completely.
            </p>
            <p>Our custom framework compiles and dispatches in under 12ms, maintaining absolute control over the secure container boundary.</p>
        </div>

        <div class="feature-card mb-6" style="padding: var(--space-8);">
            <h3 class="mb-4">The Boot Pipeline &amp; Container</h3>
            <p class="mb-4">
                All requests enter the index.php front controller. The `Container` resolves dependencies automatically using PHP Reflection API:
            </p>
            <pre style="background: #06070a; color: var(--color-primary); padding: var(--space-4); border-radius: var(--radius-sm); font-family: var(--font-mono); font-size: 0.8rem; overflow-x: auto; margin-bottom: var(--space-4);">
// Example of Autowired Constructor
class TransactionService 
{
    public function __construct(
        private LedgerRepository $ledger,
        private GatewayAdapter $gateway
    ) {}
}
            </pre>
            <p>Services are registered in `config/services.php` and autowired on the fly, preventing complex manual initialization.</p>
        </div>

        <div class="feature-card mb-6" style="padding: var(--space-8);">
            <h3 class="mb-4">Multi-Brand Domain Isolation</h3>
            <p class="mb-4">
                Every request flows through `DomainMiddleware`. It resolves the incoming HTTP Host headers against configured domains inside the `op_domains` database table:
            </p>
            <pre style="background: #06070a; color: var(--color-primary); padding: var(--space-4); border-radius: var(--radius-sm); font-family: var(--font-mono); font-size: 0.8rem; overflow-x: auto; margin-bottom: var(--space-4);">
// Enforcing Domain Scoping
$activeDomain = DomainUrlService::resolveHost($_SERVER['HTTP_HOST']);
if ($activeDomain->dns_verified === false) {
    throw new DomainNotFoundException();
}
            </pre>
            <p>This allows single owners to host multiple white-labeled brands, keeping the administrative app domain private.</p>
        </div>

        <div class="feature-card mb-6" style="padding: var(--space-8);">
            <h3 class="mb-4">Sandboxed Plugin Execution</h3>
            <p class="mb-4">
                Plugins are loaded dynamically from ZIP archives and isolated from the core codebase. The `PluginSandbox` limits file reads, prevents database alterations outside defined hooks, and verifies gateway APIs before execution.
            </p>
        </div>

    </div>
</section>

<?php
include TEMPLATE_PATH . '/footer.php';
?>
