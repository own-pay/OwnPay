<?php

declare(strict_types=1);

namespace OwnPay\Http;

/**
 * Global exception-to-JSON error handler for the API.
 *
 * Maps exception types to HTTP status codes and JSON error responses.
 * Never leaks stack traces in production.
 */
final class ErrorHandler
{
    /**
     * Register as the global exception & error handler.
     */
    public static function register(): void
    {
        set_exception_handler([self::class, 'handleException']);

        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) {
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        });
    }

    /**
     * Handle an uncaught exception.
     */
    public static function handleException(\Throwable $e): void
    {
        $status = self::mapStatusCode($e);
        $code = self::mapErrorCode($e);

        // Log the full error for debugging
        error_log(sprintf(
            "[OwnPay API] %s: %s in %s:%d\n%s",
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        ));

        // Return safe message to client
        $message = $status >= 500
            ? 'An internal server error occurred. Please try again later.'
            : $e->getMessage();

        JsonResponse::error($code, $message, $status);
    }

    /**
     * Map exception class to HTTP status code.
     */
    private static function mapStatusCode(\Throwable $e): int
    {
        return match (true) {
            $e instanceof \InvalidArgumentException => 400,
            $e instanceof \DomainException => 422,
            $e instanceof \LogicException => 409,
            $e instanceof \OverflowException => 429,
            $e instanceof \RuntimeException => 500,
            default => 500,
        };
    }

    /**
     * Map exception class to machine-readable error code.
     */
    private static function mapErrorCode(\Throwable $e): string
    {
        return match (true) {
            $e instanceof \InvalidArgumentException => 'VALIDATION_ERROR',
            $e instanceof \DomainException => 'DOMAIN_ERROR',
            $e instanceof \LogicException => 'CONFLICT',
            $e instanceof \OverflowException => 'RATE_LIMITED',
            default => 'INTERNAL_ERROR',
        };
    }
}
