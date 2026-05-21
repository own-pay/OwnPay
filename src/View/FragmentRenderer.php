<?php
declare(strict_types=1);

namespace OwnPay\View;

use OwnPay\Http\Request;
use Twig\Environment;

/**
 * Class FragmentRenderer
 *
 * Provides enterprise-grade modular UI fragment rendering capabilities for SPA-like AJAX requests.
 * Orchestrates template chunk rendering without layout wrappers to enable micro-frontend components,
 * dynamic modal loaders, and fast partial DOM updates under custom merchant domains.
 *
 * @package OwnPay\View
 */
final class FragmentRenderer
{
    /**
     * @var \Twig\Environment The Twig template engine environment.
     */
    private Environment $twig;

    /**
     * FragmentRenderer constructor.
     *
     * @param \Twig\Environment $twig The Twig template engine environment.
     */
    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    /**
     * Render a template fragment (no layout wrapper).
     *
     * @param string $template The relative path or name of the Twig template.
     * @param array<string, mixed> $data Key-value pairs of context variables to pass to the template.
     * @return string The rendered HTML fragment.
     * @throws \Twig\Error\LoaderError When the template cannot be found.
     * @throws \Twig\Error\RuntimeError When a runtime error occurs during rendering.
     * @throws \Twig\Error\SyntaxError When there is a syntax error in the template.
     */
    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }

    /**
     * Render and return as Response for AJAX calls.
     *
     * @param string $template The relative path or name of the Twig template.
     * @param array<string, mixed> $data Key-value pairs of context variables to pass to the template.
     * @return \OwnPay\Http\Response The HTTP HTML response wrapper container.
     * @throws \Twig\Error\LoaderError When the template cannot be found.
     * @throws \Twig\Error\RuntimeError When a runtime error occurs during rendering.
     * @throws \Twig\Error\SyntaxError When there is a syntax error in the template.
     */
    public function fragment(string $template, array $data = []): \OwnPay\Http\Response
    {
        $html = $this->render($template, $data);
        return \OwnPay\Http\Response::html($html);
    }

    /**
     * Check if request is AJAX/fragment request.
     *
     * Checks for the standard X-Requested-With header or a specific query parameter to determine
     * if the request targets a partial DOM update (fragment) rather than a full page reload.
     *
     * @param \OwnPay\Http\Request $request The HTTP request object.
     * @return bool True if the request is an AJAX/fragment request, false otherwise.
     */
    public static function isFragmentRequest(Request $request): bool
    {
        return $request->header('X-Requested-With') === 'XMLHttpRequest'
            || $request->query('_fragment') !== null;
    }
}

