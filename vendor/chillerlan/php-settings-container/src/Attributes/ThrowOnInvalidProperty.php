<?php
/**
 * Class ThrowOnInvalidProperty
 *
 * @created      31/10/2025
 * @author       smiley <smiley@chillerlan.net>
 * @copyright    2025 smiley
 * @license      MIT
 */
declare(strict_types=1);

namespace chillerlan\Settings\Attributes;

use Attribute;

/**
 * Tells the magic get/set methods whether to throw when a properety is inaccessible
 *
 * @see \chillerlan\Settings\SettingsContainerAbstract::throwOnInvalidProperty()
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ThrowOnInvalidProperty{

	public function __construct(
		public readonly bool $throwOnInvalid,
	){}

}
