<?php
/**
 * Class SettingsContainerAbstract
 *
 * @created      28.08.2018
 * @author       Smiley <smiley@chillerlan.net>
 * @copyright    2018 Smiley
 * @license      MIT
 */
declare(strict_types=1);

namespace chillerlan\Settings;

use chillerlan\Settings\Attributes\ThrowOnInvalidProperty;
use InvalidArgumentException, JsonException, ReflectionException, ReflectionObject,
	ReflectionProperty, ReflectionAttribute, RuntimeException;
use function is_object, json_decode, json_encode, json_last_error_msg,
	method_exists, property_exists, serialize, sprintf, unserialize;
use const JSON_THROW_ON_ERROR, PHP_VERSION_ID;

abstract class SettingsContainerAbstract implements SettingsContainerInterface{

	protected const SET_PREFIX = 'set_';
	protected const GET_PREFIX = 'get_';

	/**
	 * SettingsContainerAbstract constructor.
	 *
	 * @phpstan-param array<string, mixed> $properties
	 */
	public function __construct(iterable|null $properties = null){

		if(!empty($properties)){
			$this->fromIterable($properties);
		}

		$this->construct();
	}

	/**
	 * calls a method with trait name as replacement constructor for each used trait
	 * (remember pre-php5 classname constructors? yeah, basically this.)
	 */
	protected function construct():void{
		$traits = (new ReflectionObject($this))->getTraits();

		foreach($traits as $trait){
			$method = $trait->getShortName();

			if(method_exists($this, $method)){
				$this->{$method}();
			}
		}

	}

	public function __get(string $property):mixed{
		// back out if the property is inaccessible
		if(!property_exists($this, $property) || $this->isPrivate($property)){

			if($this->throwOnInvalidProperty()){
				throw new RuntimeException(sprintf('attempt to read invalid property: "$%s"', $property));
			}

			return null;
		}
		// call an existing custom method, skip if the property has a hook
		if(method_exists($this, static::GET_PREFIX.$property) && !$this->hasGetHook($property)){
			return $this->{static::GET_PREFIX.$property}();
		}
		// retrieve the value (triggers an existing property hook)
		return $this->{$property};
	}

	public function __set(string $property, mixed $value):void{

		if(!property_exists($this, $property) || $this->isPrivate($property)){

			if($this->throwOnInvalidProperty()){
				throw new RuntimeException(sprintf('attempt to write invalid property: "$%s"', $property));
			}

			return;
		}

		if(method_exists($this, static::SET_PREFIX.$property) && !$this->hasSetHook($property)){
			$this->{static::SET_PREFIX.$property}($value);

			return;
		}

		$this->{$property} = $value;
	}

	public function __isset(string $property):bool{
		return isset($this->{$property}) && !$this->isPrivate($property);
	}

	public function __unset(string $property):void{

		if($this->__isset($property)){
			unset($this->{$property});
		}

	}

	public function __toString():string{
		return $this->toJSON();
	}

	/**
	 * @internal Checks if a property is private
	 */
	final protected function isPrivate(string $property):bool{
		return (new ReflectionProperty($this, $property))->isPrivate();
	}

	/**
	 * @internal Checks if a property has a "set" hook
	 */
	final protected function hasSetHook(string $property):bool{

		if(PHP_VERSION_ID < 80400){
			return false;
		}
		/** @phan-suppress-next-line PhanUndeclaredMethod, PhanUndeclaredClassConstant */
		return (new ReflectionProperty($this, $property))->hasHook(\PropertyHookType::Set);
	}

	/**
	 * @internal Checks if a property has a "get" hook
	 */
	final protected function hasGetHook(string $property):bool{

		if(PHP_VERSION_ID < 80400){
			return false;
		}
		/** @phan-suppress-next-line PhanUndeclaredMethod, PhanUndeclaredClassConstant */
		return (new ReflectionProperty($this, $property))->hasHook(\PropertyHookType::Get);
	}

	/**
	 * @internal Checks for the attribute "ThrowOnInvalidProperty", used in the magic get/set
	 *
	 * @see \chillerlan\Settings\Attributes\ThrowOnInvalidProperty
	 */
	final protected function throwOnInvalidProperty():bool{

		$attributes = (new ReflectionObject($this))
			->getAttributes(ThrowOnInvalidProperty::class, ReflectionAttribute::IS_INSTANCEOF)
		;

		if($attributes === []){
			return false;
		}
		/** @var \chillerlan\Settings\Attributes\ThrowOnInvalidProperty $attr */
		$attr = $attributes[0]->newInstance();

		return $attr->throwOnInvalid;
	}

	public function toArray():array{

		$properties = (new ReflectionObject($this))
			->getProperties(~(ReflectionProperty::IS_STATIC | ReflectionProperty::IS_READONLY | ReflectionProperty::IS_PRIVATE))
		;

		$data = [];

		foreach($properties as $reflectionProperty){
			// the magic getter is called intentionally here, so that any existing hook methods are called on export
			$data[$reflectionProperty->name] = $this->__get($reflectionProperty->name);
		}

		return $data;
	}

	/**
	 * @param iterable<string, mixed> $properties
	 */
	public function fromIterable(iterable $properties):static{

		foreach($properties as $key => $value){
			$this->__set($key, $value);
		}

		return $this;
	}

	public function toJSON(int|null $jsonOptions = null):string{
		$json = json_encode($this, ($jsonOptions ?? 0));

		if($json === false){
			throw new JsonException(json_last_error_msg()); // @codeCoverageIgnore
		}

		return $json;
	}

	public function fromJSON(string $json):static{
		/** @phpstan-var array<string, mixed> $data */
		$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

		return $this->fromIterable($data);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize():array{
		return $this->toArray();
	}

	/**
	 * Returns a serialized string representation of the object in its current state (except static/readonly properties)
	 */
	public function serialize():string{
		return serialize($this);
	}

	/**
	 * Restores the data (except static/readonly properties) from the given serialized object to the current instance
	 *
	 * @throws \InvalidArgumentException
	 */
	public function unserialize(string $data):void{
		$obj = unserialize($data);

		if(!is_object($obj)){
			throw new InvalidArgumentException('The given serialized string is invalid');
		}

		$reflection = new ReflectionObject($obj);

		if(!$reflection->isInstance($this)){
			throw new InvalidArgumentException('The unserialized object does not match the class of this container');
		}

		$properties = $reflection->getProperties(~(ReflectionProperty::IS_STATIC | ReflectionProperty::IS_READONLY));
		$data       = [];

		foreach($properties as $reflectionProperty){
			$data[$reflectionProperty->name] = (PHP_VERSION_ID < 80400)
				? $reflectionProperty->getValue($obj)
				/** @phan-suppress-next-line PhanUndeclaredMethod */
				: $reflectionProperty->getRawValue($obj);
		}

		$this->__unserialize($data);
	}

	/**
	 * Returns a serialized array representation of the object in its current state (except static/readonly properties),
	 * bypassing custom getters and property hooks
	 *
	 * @return array<string, mixed>
	 */
	public function __serialize():array{

		$properties = (new ReflectionObject($this))
			->getProperties(~(ReflectionProperty::IS_STATIC | ReflectionProperty::IS_READONLY))
		;

		$data = [];

		foreach($properties as $reflectionProperty){
			// bypass existing property hooks for PHP >= 8.4
			$data[$reflectionProperty->name] = (PHP_VERSION_ID < 80400)
				? $reflectionProperty->getValue($this)
				/** @phan-suppress-next-line PhanUndeclaredMethod */
				: $reflectionProperty->getRawValue($this);
		}

		return $data;
	}

	/**
	 * Restores the data from the given array to the current instance,
	 * bypassing custom setters and property hooks
	 *
	 * @param array<string, mixed> $data
	 */
	public function __unserialize(array $data):void{
		$reflection = new ReflectionObject($this);

		foreach($data as $key => $value){
			try{
				$reflectionProperty = $reflection->getProperty($key);

				if($reflectionProperty->isStatic() || $reflectionProperty->isReadOnly()){
					continue; // @codeCoverageIgnore
				}

				(PHP_VERSION_ID < 80400)
					? $reflectionProperty->setValue($this, $value)
					/** @phan-suppress-next-line PhanUndeclaredMethod */
					: $reflectionProperty->setRawValue($this, $value);

			}
			// @codeCoverageIgnoreStart
			catch(ReflectionException){
				// attempt to assign a non-existent property, skip
				continue;
			}
			// @codeCoverageIgnoreEnd
		}

	}

}
