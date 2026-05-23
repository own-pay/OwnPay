<?php
declare(strict_types=1);

namespace OwnPay\Security;

use OwnPay\Http\Request;
use OwnPay\Http\Dto\BaseDto;
use OwnPay\Service\System\InputSanitizer;
use InvalidArgumentException;

/**
 * Class RequestValidator
 *
 * Implements validation and automatic model binding of HTTP Request data structures to Data Transfer Objects (DTOs),
 * applying global input sanitization filters while preserving cryptographic keys and password formatting.
 *
 * @package OwnPay\Security
 */
final class RequestValidator
{
    /**
     * Binds incoming Request parameters (POST body or JSON payload) to a target DTO class.
     *
     * Automatically applies sanitization criteria, filtering standard string inputs
     * but skipping strip_tags for designated sensitive fields (e.g. passwords, API keys, and hashes)
     * to protect raw credentials.
     *
     * @template T of BaseDto
     * @param \OwnPay\Http\Request $request The current HTTP Request instance.
     * @param class-string<T> $dtoClass The class name of the target DTO extending BaseDto.
     * @return T The populated and validated DTO instance.
     * @throws \InvalidArgumentException If the target DTO class does not extend BaseDto.
     */
    public static function bind(Request $request, string $dtoClass): BaseDto
    {
        if (!is_subclass_of($dtoClass, BaseDto::class)) {
            throw new InvalidArgumentException("Class {$dtoClass} must extend BaseDto.");
        }

        $raw = $request->expectsJson() ? $request->json() : $request->post();
        $data = is_array($raw) ? $raw : [];
        
        // Apply global sanitization rules to incoming string inputs before binding.
        $sensitivePatterns = ['password', 'secret', 'key', 'token', 'signature', 'hash', 'credential'];
        $sanitizedData = [];
        foreach ($data as $key => $value) {
            $keyStr = (string) $key;
            if (is_string($value)) {
                // Check if this field matches a sensitive pattern to skip HTML tag removal.
                $lowerKey = strtolower($keyStr);
                $isSensitive = false;
                foreach ($sensitivePatterns as $pattern) {
                    if (str_contains($lowerKey, $pattern)) {
                        $isSensitive = true;
                        break;
                    }
                }
                if ($isSensitive) {
                    $sanitizedData[$keyStr] = trim($value); // Preserve original formatting for cryptographic data.
                } elseif (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $sanitizedData[$keyStr] = InputSanitizer::email($value);
                } else {
                    $sanitizedData[$keyStr] = InputSanitizer::string($value);
                }
            } else {
                $sanitizedData[$keyStr] = $value;
            }
        }

        return $dtoClass::fromArray($sanitizedData);
    }
}
