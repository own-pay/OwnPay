<?php

declare(strict_types=1);

namespace AnirbanPay\Service;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\JsonFormatter;
use Monolog\Processor\PsrLogMessageProcessor;

final class Logger
{
    private static array $loggers = [];

    public static function app(): MonologLogger
    {
        return self::getLogger('app');
    }

    public static function security(): MonologLogger
    {
        return self::getLogger('security');
    }

    public static function payment(): MonologLogger
    {
        return self::getLogger('payment');
    }

    private static function getLogger(string $channel): MonologLogger
    {
        if (!isset(self::$loggers[$channel])) {
            $logger = new MonologLogger($channel);

            $logDir = defined('AP_ROOT') ? AP_ROOT . '/logs' : __DIR__ . '/../../logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }

            $handler = new RotatingFileHandler(
                $logDir . '/' . $channel . '.log',
                30,
                MonologLogger::DEBUG
            );
            $handler->setFormatter(new JsonFormatter());

            $logger->pushHandler($handler);
            $logger->pushProcessor(new PsrLogMessageProcessor());

            self::$loggers[$channel] = $logger;
        }

        return self::$loggers[$channel];
    }
}
