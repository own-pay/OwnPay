<?php
declare(strict_types=1);
include TEMPLATE_PATH . '/header.php';
?>

<section class="section page-hero" style="min-height: 70vh; display: flex; align-items: center; justify-content: center;">
    <div class="container text-center reveal" style="max-width: 480px; margin: 0 auto;">
        <div style="font-size: 5rem; font-weight: 900; color: var(--color-primary); line-height: 1; margin-bottom: var(--space-4);">404</div>
        <h2 class="mb-4">Page Not Found</h2>
        <p class="mb-8">The page you are looking for does not exist or has been moved. No internal server details are disclosed.</p>
        <a href="/" class="btn btn-primary">Return to Homepage &rarr;</a>
    </div>
</section>

<?php
include TEMPLATE_PATH . '/footer.php';
?>
