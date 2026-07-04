<?php

declare(strict_types=1);

namespace OwnPay\View\Theme;

use InvalidArgumentException;

/**
 * Maps a theme's declared engine name to its renderer. An empty/absent engine
 * name is treated as 'twig' for back-compat, since existing theme manifests
 * omit the engine field entirely.
 */
final class ThemeRendererRegistry
{
    /** @param array<string, ThemeRendererInterface> $renderers */
    public function __construct(private readonly array $renderers)
    {
    }

    public function get(string $engine): ThemeRendererInterface
    {
        $name = $engine === '' ? 'twig' : $engine;
        if (!isset($this->renderers[$name])) {
            throw new InvalidArgumentException("No theme renderer registered for engine: {$name}");
        }
        return $this->renderers[$name];
    }

    /**
     * Validates the output of the `theme.engines.register` filter before it is
     * handed to the constructor. A plugin's filter listener runs arbitrary
     * code and can return anything - non-array output, non-string keys, or
     * values that don't implement ThemeRendererInterface. Accepting that
     * silently would corrupt the engines array and only surface as a
     * confusing exception later, deep inside {@see self::get()}.
     *
     * Invalid entries are discarded individually; $onInvalid (when given) is
     * invoked once per discarded entry so the caller can log a warning. If
     * the filtered value isn't an array at all, or filtering leaves nothing
     * usable, the untouched $baseEngines are returned so the site never ends
     * up with zero registered rendering engines because of a broken plugin.
     *
     * @param mixed $filtered Raw return value from EventManager::applyFilter().
     * @param array<string, ThemeRendererInterface> $baseEngines Known-good fallback (twig + plain-php).
     * @param (callable(int|string, mixed): void)|null $onInvalid Called for each discarded entry.
     * @return array<string, ThemeRendererInterface>
     */
    public static function sanitizeEngines(mixed $filtered, array $baseEngines, ?callable $onInvalid = null): array
    {
        if (!is_array($filtered)) {
            if ($onInvalid !== null) {
                $onInvalid('*', $filtered);
            }
            return $baseEngines;
        }

        $validated = [];
        foreach ($filtered as $name => $renderer) {
            if (is_string($name) && $renderer instanceof ThemeRendererInterface) {
                $validated[$name] = $renderer;
                continue;
            }
            if ($onInvalid !== null) {
                $onInvalid($name, $renderer);
            }
        }

        return $validated !== [] ? $validated : $baseEngines;
    }
}
