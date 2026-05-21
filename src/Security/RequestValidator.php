<?php
declare(strict_types=1);

namespace OwnPay\Security;

use OwnPay\Http\Request;
use OwnPay\Http\Dto\BaseDto;
use OwnPay\Service\System\InputSanitizer;
use InvalidArgumentException;

final class RequestValidator
{
    /**
     * Bind a Request (POST data or JSON) to a specified DTO class.
     * Sanitizes inputs automatically using InputSanitizer before mapping.
     *
     * @template T of BaseDto
     * @param Request $request
     * @param class-string<T> $dtoClass
     * @return T
     * @throws InvalidArgumentException
     */
    public static function bind(Request $request, string $dtoClass): BaseDto
    {
        if (!is_subclass_of($dtoClass, BaseDto::class)) {
            throw new InvalidArgumentException("Class {$dtoClass} must extend BaseDto.");
        }

        $data = $request->expectsJson() ? $request->json() : $request->post();
        
        // Sanitize strings globally before binding
        // BUG-23 FIX: Skip strip_tags for sensitive fields that may contain
        // special characters (passwords, API keys, signatures, tokens).
        $sensitivePatterns = ['password', 'secret', 'key', 'token', 'signature', 'hash', 'credential'];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                // Check if this field should skip strip_tags
                $lowerKey = strtolower($key);
                $isSensitive = false;
                foreach ($sensitivePatterns as $pattern) {
                    if (str_contains($lowerKey, $pattern)) {
                        $isSensitive = true;
                        break;
                    }
                }
                if ($isSensitive) {
                    $data[$key] = trim($value); // Only trim, no strip_tags
                } elseif (filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $data[$key] = InputSanitizer::email($value);
                } else {
                    $data[$key] = InputSanitizer::string($value);
                }
            }
        }

        return $dtoClass::fromArray($data);
    }
}
