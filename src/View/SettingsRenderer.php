<?php
declare(strict_types=1);

namespace OwnPay\View;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Repository\PluginRepository;

/**
 * Settings auto-renderer â€” generates admin settings form from plugin fields().
 *
 * Plugins define fields as:
 *   [['name' => 'api_key', 'label' => 'API Key', 'type' => 'text', 'default' => '']]
 *
 * Supported types: text, textarea, number, email, password, select, checkbox, toggle, color
 */
final class SettingsRenderer
{
    /**
     * Render settings form HTML from plugin fields().
     *
     * @param PluginInterface $plugin
     * @param array<string, string> $currentValues Saved values from op_plugin_settings
     * @param string $action Form action URL
     * @return string HTML
     */
    public static function render(PluginInterface $plugin, array $currentValues, string $action): string
    {
        $fields = $plugin->fields();
        if (empty($fields)) {
            return '<p class="op-settings-empty">This plugin has no configurable settings.</p>';
        }

        $html = '<form method="POST" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '" class="op-settings-form">';
        $csrfToken = \OwnPay\Security\SecurityHelpers::csrfToken();
        $html .= '<input type="hidden" name="_csrf_token" value="' . self::e($csrfToken) . '">';

        foreach ($fields as $field) {
            $name = $field['name'] ?? '';
            $label = $field['label'] ?? $name;
            $type = $field['type'] ?? 'text';
            $default = $field['default'] ?? '';
            $options = $field['options'] ?? [];
            $required = $field['required'] ?? false;
            $help = $field['help'] ?? '';
            $value = $currentValues[$name] ?? (string) $default;

            $html .= '<div class="op-field-group">';
            $html .= '<label for="setting_' . self::e($name) . '">' . self::e($label) . '</label>';

            $html .= match ($type) {
                'textarea' => self::textarea($name, $value, $required),
                'select'   => self::select($name, $value, $options, $required),
                'checkbox', 'toggle' => self::toggle($name, $value),
                'password' => self::input($name, $value, 'password', $required),
                'number'   => self::input($name, $value, 'number', $required),
                'email'    => self::input($name, $value, 'email', $required),
                'color'    => self::input($name, $value, 'color', $required),
                default    => self::input($name, $value, 'text', $required),
            };

            if ($help !== '') {
                $html .= '<small class="op-field-help">' . self::e($help) . '</small>';
            }

            $html .= '</div>';
        }

        $html .= '<div class="op-form-actions">';
        $html .= '<button type="submit" class="op-btn op-btn-primary">Save Settings</button>';
        $html .= '</div>';
        $html .= '</form>';

        return $html;
    }

    private static function input(string $name, string $value, string $type, bool $required): string
    {
        $req = $required ? ' required' : '';
        return '<input type="' . $type . '" name="settings[' . self::e($name) . ']" '
            . 'id="setting_' . self::e($name) . '" '
            . 'value="' . self::e($value) . '" '
            . 'class="op-input"' . $req . '>';
    }

    private static function textarea(string $name, string $value, bool $required): string
    {
        $req = $required ? ' required' : '';
        return '<textarea name="settings[' . self::e($name) . ']" '
            . 'id="setting_' . self::e($name) . '" '
            . 'class="op-textarea" rows="4"' . $req . '>'
            . self::e($value) . '</textarea>';
    }

    private static function select(string $name, string $value, array $options, bool $required): string
    {
        $req = $required ? ' required' : '';
        $html = '<select name="settings[' . self::e($name) . ']" '
            . 'id="setting_' . self::e($name) . '" '
            . 'class="op-select"' . $req . '>';

        foreach ($options as $optValue => $optLabel) {
            $selected = ((string) $optValue === $value) ? ' selected' : '';
            $html .= '<option value="' . self::e((string) $optValue) . '"' . $selected . '>'
                . self::e($optLabel) . '</option>';
        }

        $html .= '</select>';
        return $html;
    }

    private static function toggle(string $name, string $value): string
    {
        $checked = ($value === '1' || $value === 'true') ? ' checked' : '';
        return '<label class="op-toggle">'
            . '<input type="hidden" name="settings[' . self::e($name) . ']" value="0">'
            . '<input type="checkbox" name="settings[' . self::e($name) . ']" value="1"' . $checked . '>'
            . '<span class="op-toggle-slider"></span>'
            . '</label>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
