<?php
declare(strict_types=1);

namespace OwnPay\Core;

/**
 * Class FormattingHelper
 *
 * Provides static utility methods for password generation, ID formatting,
 * name character extraction, and localization resolution.
 *
 * @package OwnPay\Core
 */
final class FormattingHelper
{
    /**
     * Generates a cryptographically secure strong random password.
     *
     * @param int $length The length of the password.
     * @return string The generated password.
     */
    public static function generateStrongPassword(int $length = 16): string
    {
        $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lower = 'abcdefghijklmnopqrstuvwxyz';
        $digits = '0123456789';
        $symbols = '@#$%&!*^-_=+';
        $all = $upper . $lower . $digits . $symbols;

        $password = $upper[random_int(0, strlen($upper) - 1)];
        $password .= $lower[random_int(0, strlen($lower) - 1)];
        $password .= $digits[random_int(0, strlen($digits) - 1)];
        $password .= $symbols[random_int(0, strlen($symbols) - 1)];

        for ($i = 4; $i < $length; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        $chars = str_split($password);
        for ($i = count($chars) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }

    /**
     * Generates a random numeric string identifier.
     *
     * @param int $length    The desired length.
     * @param int $maxLength The maximum bounds.
     * @return string The numeric ID string.
     */
    public static function generateItemID(int $length = 10, int $maxLength = 10): string
    {
        $length = min($length, $maxLength);

        $id = '';
        for ($i = 0; $i < $length; $i++) {
            $id .= random_int(0, 9);
        }

        return $id;
    }

    /**
     * Extracts initials or name characters from a full name string.
     *
     * @param string $fullName The full name.
     * @param int    $length   The desired number of characters/initials.
     * @return string The uppercase name characters.
     */
    public static function getNameChars(string $fullName, int $length = 2): string
    {
        $fullName = trim($fullName);

        if ($fullName === '' || $length <= 0) {
            return '';
        }

        $parts = array_values(array_filter(explode(' ', $fullName)));

        if (count($parts) > 1) {
            return strtoupper(
                substr($parts[0], 0, 1) .
                substr(end($parts), 0, max(0, $length - 1))
            );
        }

        return strtoupper(substr($parts[0], 0, $length));
    }

    /**
     * Resolves the target language code for a module from available translations.
     *
     * @param string      $brandLanguage      The merchant brand's preferred language.
     * @param array<string, mixed> $supportedLanguages The key-value array of supported languages.
     * @param string|null $uiLanguage         The user interface override language.
     * @return string The resolved language code.
     */
    public static function resolveModuleLanguage(string $brandLanguage, array $supportedLanguages, ?string $uiLanguage = null): string
    {
        if ($uiLanguage !== null && isset($supportedLanguages[$uiLanguage])) {
            return $uiLanguage;
        }

        if (isset($supportedLanguages[$brandLanguage])) {
            return $brandLanguage;
        }

        return (string) array_key_first($supportedLanguages);
    }

    /**
     * Builds a flattened localized language key-value array.
     *
     * @param array<string, array<string, string>> $langText The raw nested translations array.
     * @param string|null                          $language The target language code.
     * @return array<string, string> The localized translation key-value mapping.
     */
    public static function buildLangArray(array $langText, ?string $language = 'en'): array
    {
        $lang = [];
        foreach ($langText as $key => $translations) {
            $val = $translations[$language] ?? reset($translations);
            $lang[$key] = is_string($val) ? $val : '';
        }
        return $lang;
    }
}
