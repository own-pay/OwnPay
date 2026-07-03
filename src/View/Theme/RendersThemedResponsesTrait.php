<?php
declare(strict_types=1);

namespace OwnPay\View\Theme;

use OwnPay\Http\Response;
use RuntimeException;

/**
 * Shared "resolve active theme, get its renderer, render" logic for
 * controllers that render customer-facing (checkout/invoice/payment-link)
 * pages. Requires the consuming class to expose a dependency injection
 * container via $this->c (matches the convention already used by every
 * checkout controller).
 */
trait RendersThemedResponsesTrait
{
    /**
     * Resolves the active theme for the given brand, obtains its renderer,
     * and renders the logical template with the supplied context.
     *
     * @param string $logicalTemplateName Logical template name (e.g. 'checkout/checkout.twig').
     * @param int|null $brandId The merchant/brand identifier, or null for the platform default.
     * @param array<string, mixed> $context Template render context.
     * @return \OwnPay\Http\Response The rendered HTML response.
     * @throws \RuntimeException If theme rendering services are not available in the container.
     */
    private function renderThemed(string $logicalTemplateName, ?int $brandId, array $context): Response
    {
        $resolver = $this->c->get(ActiveThemeResolver::class);
        $registry = $this->c->get(ThemeRendererRegistry::class);
        if (!$resolver instanceof ActiveThemeResolver || !$registry instanceof ThemeRendererRegistry) {
            throw new RuntimeException('Theme rendering services not available.');
        }

        $theme = $resolver->resolve($brandId);
        $renderer = $registry->get($theme->engine);

        return Response::html($renderer->render($theme->resolveTemplate($logicalTemplateName), $context));
    }
}
