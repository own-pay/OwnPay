<?php

declare(strict_types=1);

namespace OwnPay\Core;

final class FormattingHelper
{
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

    public static function generateItemID(int $length = 10, int $maxLength = 10): string
    {
        $length = min($length, $maxLength);

        $id = '';
        for ($i = 0; $i < $length; $i++) {
            $id .= random_int(0, 9);
        }

        return $id;
    }

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

    public static function resolveModuleLanguage(string $brandLanguage, array $supportedLanguages): string
    {
        if (!empty($_SESSION['ui_language']) && isset($supportedLanguages[$_SESSION['ui_language']])) {
            return $_SESSION['ui_language'];
        }

        if (isset($supportedLanguages[$brandLanguage])) {
            return $brandLanguage;
        }

        return array_key_first($supportedLanguages);
    }

    public static function buildLangArray(array $langText, ?string $language = 'en'): array
    {
        $lang = [];
        foreach ($langText as $key => $translations) {
            $lang[$key] = $translations[$language] ?? reset($translations);
        }
        return $lang;
    }
}
