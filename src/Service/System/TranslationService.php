<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

use OwnPay\Core\Database;

/**
 * Service managing translation strings, locale resolution, and placeholder replacements.
 */
final class TranslationService
{
    private Database $db;
    
    /**
     * @var string Current resolved locale.
     */
    private string $locale = 'en';

    /**
     * @var array<string, string> Cached translations for the active locale.
     */
    private array $translations = [];

    /**
     * @var array<string, string> Cached fallback English translations.
     */
    private array $fallbackTranslations = [];

    /**
     * @var bool Whether translations have been loaded from the database.
     */
    private bool $loaded = false;

    /**
     * Constructs a new TranslationService instance.
     *
     * @param Database $db The database instance.
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Sets the active locale and resets loaded cache.
     *
     * @param string $locale The language code.
     * @return self
     */
    public function setLocale(string $locale): self
    {
        // The locale flows into storage file paths (storage/languages/{code}.json), so an
        // unvalidated value would be a path-traversal vector (CWE-22). Silently ignore an unsafe
        // code and keep the current locale.
        if (!$this->isValidCode($locale)) {
            return $this;
        }
        if ($this->locale !== $locale) {
            $this->locale = $locale;
            $this->translations = [];
            $this->loaded = false;
        }
        return $this;
    }

    /**
     * Validates a language code is a safe BCP-47-style identifier (letters, digits, '-', '_').
     *
     * Codes are interpolated into storage file paths, so they MUST NOT contain path separators or
     * traversal sequences. This is the single guard every file-path operation below relies on.
     *
     * @param string $code The language code to validate.
     * @return bool True when the code is a safe identifier.
     */
    private function isValidCode(string $code): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,35}$/', $code) === 1;
    }

    /**
     * Gets the active resolved locale.
     *
     * @return string Current locale code.
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Clears in-memory translation caches.
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->translations = [];
        $this->fallbackTranslations = [];
        $this->loaded = false;
    }

    /**
     * Translates a given key with optional placeholder replacements.
     *
     * @param string $key The translation key.
     * @param array<string, mixed> $replace Placeholder values to replace.
     * @return string The translated string.
     */
    public function trans(string $key, array $replace = []): string
    {
        $this->ensureLoaded();

        $value = $this->translations[$key] ?? $this->fallbackTranslations[$key] ?? $key;

        if (!empty($replace)) {
            foreach ($replace as $k => $v) {
                $vStr = is_scalar($v) ? (string)$v : '';
                $value = str_replace(':' . $k, $vStr, $value);
            }
        }

        return $value;
    }

    /**
     * Retrieves all active languages list.
     *
     * @return array<int, array{code: string, name: string, is_default: int, status: string}>
     */
    public function getActiveLanguages(): array
    {
        try {
            $rows = $this->db->fetchAll("SELECT code, name, is_default, status FROM op_languages WHERE status = 'active' ORDER BY is_default DESC, name ASC");
            $langs = [];
            foreach ($rows as $row) {
                $code = $row['code'] ?? '';
                $name = $row['name'] ?? '';
                $isDefault = $row['is_default'] ?? 0;
                $status = $row['status'] ?? '';
                $langs[] = [
                    'code' => is_string($code) ? $code : '',
                    'name' => is_string($name) ? $name : '',
                    'is_default' => is_numeric($isDefault) ? (int)$isDefault : 0,
                    'status' => is_string($status) ? $status : ''
                ];
            }
            return $langs;
        } catch (\Throwable) {
            return [['code' => 'en', 'name' => 'English', 'is_default' => 1, 'status' => 'active']];
        }
    }

    /**
     * Retrieves all languages list, active or inactive.
     *
     * @return array<int, array{id: int, code: string, name: string, is_default: int, status: string}>
     */
    public function getAllLanguages(): array
    {
        try {
            $rows = $this->db->fetchAll("SELECT id, code, name, is_default, status FROM op_languages ORDER BY is_default DESC, name ASC");
            $langs = [];
            foreach ($rows as $row) {
                $id = $row['id'] ?? 0;
                $code = $row['code'] ?? '';
                $name = $row['name'] ?? '';
                $isDefault = $row['is_default'] ?? 0;
                $status = $row['status'] ?? '';
                $langs[] = [
                    'id' => is_numeric($id) ? (int)$id : 0,
                    'code' => is_string($code) ? $code : '',
                    'name' => is_string($name) ? $name : '',
                    'is_default' => is_numeric($isDefault) ? (int)$isDefault : 0,
                    'status' => is_string($status) ? $status : ''
                ];
            }
            return $langs;
        } catch (\Throwable) {
            return [['id' => 1, 'code' => 'en', 'name' => 'English', 'is_default' => 1, 'status' => 'active']];
        }
    }

    /**
     * Retrieves the default system language code.
     *
     * @return string System default language code.
     */
    public function getDefaultLanguage(): string
    {
        try {
            $row = $this->db->fetchOne("SELECT code FROM op_languages WHERE is_default = 1 LIMIT 1");
            if ($row === null) {
                return 'en';
            }
            $code = $row['code'] ?? 'en';
            return is_string($code) ? $code : 'en';
        } catch (\Throwable) {
            return 'en';
        }
    }

    /**
     * Sets the default system language.
     *
     * @param string $code Language code.
     * @return void
     */
    public function setDefaultLanguage(string $code): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->execute("UPDATE op_languages SET is_default = 0");
            $this->db->execute("UPDATE op_languages SET is_default = 1 WHERE code = :code", ['code' => $code]);
            $this->db->commit();
            $this->clearCache();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Checks if a language code exists in the database.
     *
     * @param string $code Language code.
     * @return bool
     */
    public function exists(string $code): bool
    {
        try {
            $row = $this->db->fetchOne("SELECT id FROM op_languages WHERE code = :code", ['code' => $code]);
            return $row !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Manually creates a new language, copying standard English strings.
     *
     * @param string $code Language code (e.g. bn).
     * @param string $name Language name (e.g. Bengali).
     * @return void
     */
    public function createLanguage(string $code, string $name): void
    {
        if (!$this->isValidCode($code)) {
            throw new \InvalidArgumentException('Invalid language code');
        }
        $enData = $this->db->fetchOne("SELECT translations FROM op_languages WHERE code = 'en'");
        $translations = '{}';
        if ($enData !== null) {
            $transRaw = $enData['translations'] ?? '{}';
            if (is_string($transRaw)) {
                $translations = $transRaw;
            }
        }

        $this->db->execute(
            "INSERT INTO op_languages (code, name, status, is_default, translations) 
             VALUES (:code, :name, 'active', 0, :translations)",
            [
                'code' => $code,
                'name' => $name,
                'translations' => $translations
            ]
        );

        // Write to storage file
        $storageDir = dirname(__DIR__, 3) . '/storage/languages';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }
        @file_put_contents($storageDir . '/' . $code . '.json', $translations, LOCK_EX);
        @chmod($storageDir . '/' . $code . '.json', 0664);
    }

    /**
     * Uploads a new JSON translations file for a language.
     *
     * @param string $code Language code.
     * @param string $name Language name.
     * @param array<string, mixed> $translationsRaw Raw translations array (nested or flat).
     * @return void
     */
    public function uploadLanguage(string $code, string $name, array $translationsRaw): void
    {
        if (!$this->isValidCode($code)) {
            throw new \InvalidArgumentException('Invalid language code');
        }
        $flat = $this->flattenArray($translationsRaw);
        $json = json_encode($flat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if ($this->exists($code)) {
            $this->db->execute(
                "UPDATE op_languages SET name = :name, translations = :translations WHERE code = :code",
                [
                    'code' => $code,
                    'name' => $name,
                    'translations' => $json
                ]
            );
        } else {
            $this->db->execute(
                "INSERT INTO op_languages (code, name, status, is_default, translations) 
                 VALUES (:code, :name, 'active', 0, :translations)",
                [
                    'code' => $code,
                    'name' => $name,
                    'translations' => $json
                ]
            );
        }

        // Write to storage file
        $storageDir = dirname(__DIR__, 3) . '/storage/languages';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }
        @file_put_contents($storageDir . '/' . $code . '.json', $json, LOCK_EX);
        @chmod($storageDir . '/' . $code . '.json', 0664);

        $this->clearCache();
    }

    /**
     * Deletes a language. English cannot be deleted.
     *
     * @param string $code Language code to delete.
     * @return void
     */
    public function deleteLanguage(string $code): void
    {
        if (!$this->isValidCode($code)) {
            throw new \InvalidArgumentException('Invalid language code');
        }
        if ($code === 'en') {
            throw new \InvalidArgumentException("Cannot delete base English language");
        }
        
        $this->db->execute("DELETE FROM op_languages WHERE code = :code", ['code' => $code]);

        // Delete storage file
        $filePath = dirname(__DIR__, 3) . '/storage/languages/' . $code . '.json';
        if (file_exists($filePath)) {
            @unlink($filePath);
        }

        $this->clearCache();
    }

    /**
     * Retrieves the entire translation catalog for a language code.
     *
     * @param string $code Language code.
     * @return array<string, string>
     */
    public function getTranslations(string $code): array
    {
        try {
            $row = $this->db->fetchOne("SELECT translations FROM op_languages WHERE code = :code", ['code' => $code]);
            if ($row === null) {
                return [];
            }
            $translationsStr = $row['translations'] ?? '{}';
            $decoded = json_decode(is_string($translationsStr) ? $translationsStr : '{}', true);
            
            $result = [];
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    $result[(string)$k] = is_scalar($v) ? (string)$v : '';
                }
            }
            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Saves updated translation strings.
     *
     * @param string $code Language code.
     * @param array<string, string> $strings Flat array of key-value translation strings.
     * @return void
     */
    public function saveTranslations(string $code, array $strings): void
    {
        if (!$this->isValidCode($code)) {
            throw new \InvalidArgumentException('Invalid language code');
        }
        $json = json_encode($strings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->db->execute("UPDATE op_languages SET translations = :translations WHERE code = :code", [
            'code' => $code,
            'translations' => $json
        ]);

        // Write to storage file
        $storageDir = dirname(__DIR__, 3) . '/storage/languages';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }
        @file_put_contents($storageDir . '/' . $code . '.json', $json, LOCK_EX);
        @chmod($storageDir . '/' . $code . '.json', 0664);

        $this->clearCache();
    }

    /**
     * Ensures translation files are loaded from database.
     *
     * @return void
     */
    private function ensureLoaded(): void
    {
        if ($this->loaded) {
            return;
        }

        // 1. Load English fallback always
        if (empty($this->fallbackTranslations)) {
            $this->fallbackTranslations = $this->loadTranslations('en');
        }

        // 2. Load active locale
        if ($this->locale === 'en') {
            $this->translations = $this->fallbackTranslations;
        } else {
            $this->translations = $this->loadTranslations($this->locale);
        }

        $this->loaded = true;
    }

    /**
     * Loads translations for a locale from its storage file, copying/recovering it from config or database if missing.
     *
     * @param string $code Locale code.
     * @return array<string, string> Flat key-value translations map.
     */
    private function loadTranslations(string $code): array
    {
        $rootDir = dirname(__DIR__, 3);
        $storageDir = $rootDir . '/storage/languages';

        if (!$this->isValidCode($code)) {
            return $this->decodeTranslationFile($rootDir . '/config/languages/en.json');
        }

        // Ensure storage directory exists
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0755, true);
        }

        $filePath = $storageDir . '/' . $code . '.json';

        $base = [];
        if ($code === 'en') {
            $base = $this->decodeTranslationFile($rootDir . '/config/languages/en.json');

            if (!file_exists($filePath) && $base !== []) {
                $json = json_encode($base, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json !== false) {
                    @file_put_contents($filePath, $json, LOCK_EX);
                    @chmod($filePath, 0664);
                }
            }
        }

        if (!file_exists($filePath)) {
            $dbTranslations = $this->loadTranslationsFromDb($code);
            if (!empty($dbTranslations)) {
                $json = json_encode($dbTranslations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json !== false) {
                    @file_put_contents($filePath, $json, LOCK_EX);
                    @chmod($filePath, 0664);
                }
                return array_merge($base, $dbTranslations);
            }
            return $base;
        }

        $stored = $this->decodeTranslationFile($filePath);
        if ($stored !== []) {
            return array_merge($base, $stored);
        }

        return array_merge($base, $this->loadTranslationsFromDb($code));
    }

    /**
     * Reads a JSON translation file into a flat key-value string map.
     *
     * @param string $filePath Absolute path to the JSON file.
     * @return array<string, string> Flat translations map (empty on missing/invalid file).
     */
    private function decodeTranslationFile(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }
        $content = @file_get_contents($filePath);
        if ($content === false || $content === '') {
            return [];
        }
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [];
        }
        $result = [];
        foreach ($decoded as $k => $v) {
            $result[(string)$k] = is_scalar($v) ? (string)$v : '';
        }
        return $result;
    }

    /**
     * Load translations JSON from DB.
     *
     * @param string $code Language code.
     * @return array<string, string> Flat key-value translations map.
     */
    private function loadTranslationsFromDb(string $code): array
    {
        try {
            $row = $this->db->fetchOne("SELECT translations FROM op_languages WHERE code = :code AND status = 'active'", ['code' => $code]);
            if ($row !== null) {
                $translationsStr = $row['translations'] ?? '{}';
                $data = json_decode(is_string($translationsStr) ? $translationsStr : '{}', true);
                if (is_array($data)) {
                    $result = [];
                    foreach ($data as $k => $v) {
                        $result[(string)$k] = is_scalar($v) ? (string)$v : '';
                    }
                    return $result;
                }
            }
        } catch (\Throwable) {
            
        }
        return [];
    }

    /**
     * Flattens a nested array to a dot-notation single level array.
     *
     * @param array<mixed> $array
     * @param string $prefix
     * @return array<string, string>
     */
    private function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $newKey = $prefix !== '' ? $prefix . '.' . $key : $key;
            if (is_array($value)) {
                $result = array_merge($result, $this->flattenArray($value, $newKey));
            } else {
                $result[$newKey] = is_scalar($value) ? (string)$value : '';
            }
        }
        return $result;
    }
}
