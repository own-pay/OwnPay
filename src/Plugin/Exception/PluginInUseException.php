<?php

declare(strict_types=1);

namespace OwnPay\Plugin\Exception;

use RuntimeException;

/**
 * Exception thrown when attempting to uninstall/delete a plugin that is currently
 * active on one or more brands.
 *
 * @package OwnPay\Plugin\Exception
 */
final class PluginInUseException extends RuntimeException
{
}
