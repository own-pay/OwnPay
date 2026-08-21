<?php
declare(strict_types=1);

namespace OwnPay\Support;

/**
 * Class Version
 *
 * Single source of truth for the OwnPay core application version. Every runtime
 * fallback default, installer seed value, and CLI tool that needs the current
 * version reads this constant - never hardcode a duplicate version string
 * elsewhere. Bumping a release means changing this one line.
 *
 * @package OwnPay\Support
 */
final class Version
{
    /**
     * @var string Current OwnPay core semantic version.
     */
    public const CURRENT = '0.2.1';
}
