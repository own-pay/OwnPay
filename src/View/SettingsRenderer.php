<?php
declare(strict_types=1);

namespace OwnPay\View;

use OwnPay\Plugin\PluginInterface;
use OwnPay\Repository\PluginRepository;

/**
 * Class SettingsRenderer
 *
 * Provides automated form rendering logic to dynamically build administrative setting controls
 * based on configuration fields defined by individual gateway or addon plugins.
 * Enforces secure request flows by automatically injecting CSRF protection tokens resolved via the
 * system-wide `SecurityHelpers::csrfToken()` helper and guarantees output sanitization across all input types.
 *
 * @package OwnPay\View
 */
final class SettingsRenderer
{
    /**
     * Render the HTML settings form using settings fields declared by the target plugin.
     *
     * Iterates over field definitions and maps them to their respective HTML templates
     * (e.g. text, textarea, select, toggle, checkbox) while pre-populating existing saved configurations.
     *
     * @param \OwnPay\Plugin\PluginInterface $plugin The target plugin instance to extract fields from.
     * @param array<string, string> $currentValues The current saved setting values from storage.
     * @param string $action The target URL endpoint for the form post action.
     * @return string The generated HTML form string.
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
            /** @phpstan-ignore-next-line */
            $name = $field['name'] ?? '';
            /** @phpstan-ignore-next-line */
            $label = $field['label'] ?? $name;
            /** @phpstan-ignore-next-line */
            $type = $field['type'] ?? 'text';
            /** @phpstan-ignore-next-line */
            $default = $field['default'] ?? '';
            /** @phpstan-ignore-next-line */
            $options = $field['options'] ?? [];
            /** @phpstan-ignore-next-line */
            $required = $field['required'] ?? false;
            /** @phpstan-ignore-next-line */
            $help = $field['help'] ?? '';
            /** @phpstan-ignore-next-line */
            $rawDefault = $field['default'] ?? '';
            
            /**
             * Safe fallback parsing: if the default parameter is configured as an array,
             * serialize it to a JSON-compliant string to avoid scalar-to-array type conversions.
             */
            if (is_array($rawDefault)) {
                $defaultStr = json_encode($rawDefault, JSON_UNESCAPED_UNICODE) ?: '';
            } elseif (is_scalar($rawDefault)) {
                $defaultStr = (string) $rawDefault;
            } else {
                $defaultStr = '';
            }
            $value = $currentValues[$name] ?? $defaultStr;

            $html .= '<div class="op-field-group field-group-' . self::e($name) . '">';
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

            if ($help !== '' /** @phpstan-ignore notIdentical.alwaysFalse */) {
                $html .= '<small class="op-field-help">' . self::e($help) . '</small>';
            }

            $html .= '</div>';
        }

        $html .= '<div class="op-form-actions">';
        $html .= '<button type="submit" class="op-btn op-btn-primary">Save Settings</button>';
        $html .= '</div>';
        $html .= '</form>';

        $meta = get_class($plugin)::metadata();
        $slug = $meta['slug'];
        if ($slug === 'sms-gateway') {
            $html .= self::injectSmsGatewayToggleScript();
        }

        return $html;
    }

    /**
     * Render a standard HTML input field element.
     *
     * @param string $name The name attribute of the input control.
     * @param string $value The pre-populated value.
     * @param string $type The specific HTML5 input type (e.g. text, password, number, email, color).
     * @param bool $required Flag indicating whether the field must be completed.
     * @return string The generated input element HTML markup.
     */
    private static function input(string $name, string $value, string $type, bool $required): string
    {
        $req = $required ? ' required' : '';
        return '<input type="' . $type . '" name="settings[' . self::e($name) . ']" '
            . 'id="setting_' . self::e($name) . '" '
            . 'value="' . self::e($value) . '" '
            . 'class="op-input"' . $req . '>';
    }

    /**
     * Render an HTML textarea element.
     *
     * @param string $name The name attribute of the textarea control.
     * @param string $value The pre-populated content.
     * @param bool $required Flag indicating whether the field must be completed.
     * @return string The generated textarea element HTML markup.
     */
    private static function textarea(string $name, string $value, bool $required): string
    {
        $req = $required ? ' required' : '';
        return '<textarea name="settings[' . self::e($name) . ']" '
            . 'id="setting_' . self::e($name) . '" '
            . 'class="op-textarea" rows="4"' . $req . '>'
            . self::e($value) . '</textarea>';
    }

    /**
     * Render an HTML select dropdown element.
     *
     * @param string $name The name attribute of the select control.
     * @param string $value The currently active option key.
     * @param array<string|int, string> $options An associative mapping of option values to option labels.
     * @param bool $required Flag indicating whether the field must be completed.
     * @return string The generated select element HTML markup.
     */
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

    /**
     * Render a custom boolean checkbox/toggle element.
     *
     * Includes a hidden input fallback to ensure standard form submissions submit a zero-value
     * when the toggle switch is not active.
     *
     * @param string $name The name attribute of the toggle control.
     * @param string $value The current string state representing the boolean toggle.
     * @return string The generated checkbox toggle HTML markup.
     */
    private static function toggle(string $name, string $value): string
    {
        $checked = ($value === '1' || $value === 'true') ? ' checked' : '';
        return '<label class="op-toggle">'
            . '<input type="hidden" name="settings[' . self::e($name) . ']" value="0">'
            . '<input type="checkbox" name="settings[' . self::e($name) . ']" value="1"' . $checked . '>'
            . '<span class="op-toggle-slider"></span>'
            . '</label>';
    }

    /**
     * Securely escapes content for inclusion in HTML attributes.
     *
     * Uses htmlspecialchars with ENT_QUOTES and UTF-8 encoding.
     *
     * @param string $value The raw content string.
     * @return string The escaped string safe for HTML output.
     */
    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Injects the dynamic client-side Javascript block to toggle provider field visibilities.
     *
     * @return string The inline HTML script block.
     */
    private static function injectSmsGatewayToggleScript(): string
    {
        return '
<script>
document.addEventListener("DOMContentLoaded", function() {
    var providerSelect = document.getElementById("setting_provider");
    if (!providerSelect) return;

    var twilioFields = [".field-group-twilio_sid", ".field-group-twilio_token", ".field-group-twilio_from"];
    var vonageFields = [".field-group-vonage_key", ".field-group-vonage_secret", ".field-group-vonage_from"];
    var customFields = [".field-group-custom_api_url", ".field-group-custom_api_key", ".field-group-custom_api_method", ".field-group-custom_api_body_template"];

    function updateVisibility() {
        var selected = providerSelect.value;

        // Helper to toggle a list of fields
        function toggleFields(fields, show) {
            fields.forEach(function(selector) {
                var el = document.querySelector(selector);
                if (el) {
                    el.style.display = show ? "block" : "none";
                }
            });
        }

        // Hide all first
        toggleFields(twilioFields, false);
        toggleFields(vonageFields, false);
        toggleFields(customFields, false);

        // Show selected
        if (selected === "twilio") {
            toggleFields(twilioFields, true);
        } else if (selected === "vonage") {
            toggleFields(vonageFields, true);
        } else if (selected === "custom") {
            toggleFields(customFields, true);
        }
    }

    providerSelect.addEventListener("change", updateVisibility);
    updateVisibility();
});
</script>
';
    }
}
