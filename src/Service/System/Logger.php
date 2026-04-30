<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

use OwnPay\Security\LogSanitizer;

/**
 * Logger — PSR-3 compatible file logger with log rotation.
 *
 * Per PCI-DSS/OWASP: all logs sanitized via LogSanitizer, no raw PII.
 */
final class Logger
{
    private string $logDir;
    private string $channel;
    private LogSanitizer $sanitizer;

    public const EMERGENCY = 'emergency';
    public const ALERT     = 'alert';
    public const CRITICAL  = 'critical';
    public const ERROR     = 'error';
    public const WARNING   = 'warning';
    public const NOTICE    = 'notice';
    public const INFO      = 'info';
    public const DEBUG     = 'debug';

    public function __construct(string $channel = 'app', ?string $logDir = null)
    {
        $this->channel = $channel;
        $this->logDir = $logDir ?? dirname(__DIR__, 3) . '/storage/logs';
        $this->sanitizer = new LogSanitizer();

        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log(self::EMERGENCY, $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log(self::ALERT, $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log(self::CRITICAL, $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log(self::NOTICE, $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        if (!EnvironmentService::debugEnabled()) {
            return; // Skip debug logs in production
        }
        $this->log(self::DEBUG, $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        // Sanitize log data (PCI-DSS requirement)
        $message = $this->sanitizer->sanitize($message);
        $context = $this->sanitizer->sanitizeArray($context);

        $entry = sprintf(
            "[%s] %s.%s: %s %s\n",
            date('Y-m-d H:i:s'),
            $this->channel,
            strtoupper($level),
            $message,
            !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
        );

        $file = $this->logDir . '/' . $this->channel . '-' . date('Y-m-d') . '.log';
        @file_put_contents($file, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Rotate logs — remove files older than N days.
     */
    public function rotate(int $daysToKeep = 30): int
    {
        $cutoff = time() - ($daysToKeep * 86400);
        $files = glob($this->logDir . '/' . $this->channel . '-*.log') ?: [];
        $deleted = 0;

        foreach ($files as $file) {
            if (filemtime($file) < $cutoff) {
                @unlink($file);
                $deleted++;
            }
        }

        return $deleted;
    }
}
