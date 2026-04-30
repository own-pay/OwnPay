<?php
declare(strict_types=1);

namespace OwnPay\View;

use Twig\Environment;

/**
 * Content loader — renders Twig fragments for SPA-style AJAX loading.
 * Replaces old ContentLoader.php.
 */
final class FragmentRenderer
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    /**
     * Render a template fragment (no layout wrapper).
     */
    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }

    /**
     * Render and return as Response for AJAX calls.
     */
    public function fragment(string $template, array $data = []): \OwnPay\Http\Response
    {
        $html = $this->render($template, $data);
        return \OwnPay\Http\Response::html($html);
    }

    /**
     * Check if request is AJAX/fragment request.
     */
    public static function isFragmentRequest(): bool
    {
        return (
            ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
            || isset($_GET['_fragment'])
        );
    }
}
