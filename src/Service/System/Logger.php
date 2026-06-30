<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

use OwnPay\Security\LogSanitizer;

/**
 * Logger - A lightweight PSR-3 compatible file logger.
 *
 * Implements log security requirements under PCI-DSS standards,
 * sanitizing all messages and contextual objects to eliminate PII leakage.
 */
final class Logger
{
    /**
     * Absolute directory path where log files will be written.
     *
     * @var string
     */
    private string $logDir;

    /**
     * Selected channel name identifier for file segmentation.
     *
     * @var string
     */
    private string $channel;

    /**
     * Log filter to redact PII and sensitive parameters before file write.
     *
     * @var LogSanitizer
     * @phpstan-ignore property.onlyWritten
     */
    private LogSanitizer $sanitizer;

    public const EMERGENCY = 'emergency';
    public const ALERT     = 'alert';
    public const CRITICAL  = 'critical';
    public const ERROR     = 'error';
    public const WARNING   = 'warning';
    public const NOTICE    = 'notice';
    public const INFO      = 'info';
    public const DEBUG     = 'debug';

    /**
     * Initialises the Logger service.
     *
     * Creates the target log folder if it does not exist.
     *
     * @param string $channel Target logging channel identifier (defaults to 'app').
     * @param string|null $logDir Override directory for log outputs. Defaults to system storage/logs.
     */
    public function __construct(string $channel = 'app', ?string $logDir = null)
    {
        $this->channel = $channel;
        $this->logDir = $logDir ?? dirname(__DIR__, 3) . '/storage/logs';
        $this->sanitizer = new LogSanitizer();

        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0755, true);
        }
    }

    /**
     * Logs an emergency level message.
     *
     * System is unusable.
     *
     * @param string $message Narrative text.
     * @param array<string, mixed> $context Key-value context payload.
     * @return void
     */
    public function emergency(string $message, array $context = []): void
    {
        $this->log(self::EMERGENCY, $message, $context);
    }

    /**
     * Logs an alert level message.
     *
     * Action must be taken immediately.
     *
     * @param string $message Narrative text.
     * @param array<string, mixed> $context Key-value context payload.
     * @return void
     */
    public function alert(string $message, array $context = []): void
    {
        $this->log(self::ALERT, $message, $context);
    }

    /**
     * Logs a critical level message.
     *
     * Critical conditions.
     *
     * @param string $message Narrative text.
     * @param array<string, mixed> $context Key-value context payload.
     * @return void
     */
    public function critical(string $message, array $context = []): void
    {
        $this->log(self::CRITICAL, $message, $context);
    }

    /**
     * Logs an error level message.
     *
     * Runtime errors that do not require immediate action but should typically
     * be logged and monitored.
     *
     * @param string $message Narrative text.
     * @param array<string, mixed> $context Key-value context payload.
     * @return void
     */
    public function error(string $message, array $context = []): void
    {
        $this->log(self::ERROR, $message, $context);
    }

    /**
     * Logs a warning level message.
     *
     * Exceptional occurrences that are not errors.
     *
     * @param string $message Narrative text.
     * @param array<string, mixed> $context Key-value context payload.
     * @return void
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log(self::WARNING, $message, $context);
    }

    /**
     * Logs a notice level message.
     *
     * Normal but significant events.
     *
     * @param string $message Narrative text.
     * @param array<string, mixed> $context Key-value context payload.
     * @return void
     */
    public function notice(string $message, array $context = []): void
    {
        $this->log(self::NOTICE, $message, $context);
    }

    /**
     * Logs an info level message.
     *
     * Interesting events.
     *
     * @param string $message Narrative text.
     * @param array<string, mixed> $context Key-value context payload.
     * @return void
     */
    public function info(string $message, array $context = []): void
    {
        $this->log(self::INFO, $message, $context);
    }

    /**
     * Logs a debug level message.
     *
     * Detailed debug information.
     * Skips writing to log file if debug mode is disabled in environment settings.
     *
     * @param string $message Narrative text.
     * @param array<string, mixed> $context Key-value context payload.
     * @return void
     */
    public function debug(string $message, array $context = []): void
    {
        if (!EnvironmentService::debugEnabled()) {
            return;
        }
        $this->log(self::DEBUG, $message, $context);
    }

    /**
     * Redacts and sanitizes raw elements before appending a log entry.
     *
     * Applies LogSanitizer to purge PII parameters (PCI-DSS compliance rule).
     *
     * @param string $level Log level text identifier.
     * @param string $message Raw message narrative.
     * @param array<string, mixed> $context Key-value contextual dataset.
     * @return void
     */
    public function log(string $level, string $message, array $context = []): void
    {
        // Sanitize log data (PCI-DSS requirement to prevent cardholder data leak)
        $message = LogSanitizer::sanitizeMessage($message);
        $context = LogSanitizer::sanitize($context);

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
     * Rotates files within the channel by removing files older than N days.
     *
     * @param int $daysToKeep Retained window size in days (defaults to 30).
     * @return int Total number of rotated/deleted files.
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
