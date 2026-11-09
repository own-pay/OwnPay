<?php
declare(strict_types=1);

namespace OwnPay\Http\Dto;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionProperty;

/**
 * Class BaseDto
 *
 * Abstract Data Transfer Object (DTO) base class. Provides reflection-based
 * array hydration and automatic type-casting routines with runtime validation.
 *
 * @package OwnPay\Http\Dto
 */
abstract class BaseDto
{
    /**
     * Factory to instantiate DTO from array data.
     *
     * Maps keys matching property names and casts types based on reflection.
     *
     * @param array<string, mixed> $data Input array (e.g. from $_POST or JSON).
     * @return static Hydrated DTO instance.
     * @throws InvalidArgumentException on validation or parameter type casting failure.
     */
    /** @phpstan-ignore-next-line */
    public static function fromArray(array $data): static
    {
        /** @phpstan-ignore-next-line */
        $dto = new static();
        $reflection = new ReflectionClass(static::class);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property/** @phpstan-ignore-next-line */->getName();
            if (array_key_exists($name, $data)) {
                $value = $data[$name];
                
                // Enforce basic type casting routines.
                $type = $property->getType();
                if ($type && !$type->allowsNull() && $value === null) {
                    throw new InvalidArgumentException("Property '{$name}' cannot be null.");
                }

                if ($type && $value !== null) {
                    $typeName = ($type instanceof \ReflectionNamedType ? $type->getName() : 'mixed');
                    if ($typeName === 'int') {
                        // Strict int coercion (API-18): previously (int) $value silently
                        // turned "abc" into 0 and "1; DROP TABLE" into 1 - masking bad
                        // input as valid integers. Accept ints, integer-valued strings,
                        // and numeric floats; reject everything else.
                        if (is_int($value)) {
                            // already int
                        } elseif (is_string($value) && preg_match('/^[+-]?[0-9]+$/', $value)) {
                            $value = (int) $value;
                        } elseif (is_float($value) && floor($value) === $value) {
                            $value = (int) $value;
                        } else {
                            throw new InvalidArgumentException(
                                "Property '{$name}' expects int, got non-integer value."
                            );
                        }
                    } elseif ($typeName === 'float') {
                        // Strict float coercion: accept floats and numeric strings only.
                        if (is_float($value)) {
                            // already float
                        } elseif (is_int($value)) {
                            $value = (float) $value;
                        } elseif (is_string($value) && is_numeric($value)) {
                            $value = (float) $value;
                        } else {
                            throw new InvalidArgumentException(
                                "Property '{$name}' expects float, got non-numeric value."
                            );
                        }
                    } elseif ($typeName === 'bool') {
                        // Strict bool coercion (API-18): previously used filter_var with
                        // FILTER_VALIDATE_BOOLEAN without FILTER_NULL_ON_FAILURE, so any
                        // unrecognized value silently became false. Now we explicitly
                        // accept the truthy/falsy string set and bool/int; anything else
                        // throws.
                        if (is_bool($value)) {
                            // already bool
                        } else {
                            $boolVal = filter_var(
                                $value,
                                FILTER_VALIDATE_BOOLEAN,
                                FILTER_NULL_ON_FAILURE
                            );
                            if ($boolVal === null) {
                                throw new InvalidArgumentException(
                                    "Property '{$name}' expects bool, got unrecognized value."
                                );
                            }
                            $value = $boolVal;
                        }
                    } elseif ($typeName === 'string') {
                        // Strict string coercion (API-18): reject arrays and objects
                        // (previously (string) on an array yields "Array" with a notice).
                        if (is_string($value)) {
                            // already string
                        } elseif (is_scalar($value)) {
                            $value = (string) $value;
                        } else {
                            throw new InvalidArgumentException(
                                "Property '{$name}' expects string, got non-scalar value."
                            );
                        }
                    }
                }

                $property->setValue($dto, $value);
            } else {
                // Initialize nullable properties without defaults to null.
                $type = $property->getType();
                if (!$property->isInitialized($dto)) {
                    if ($type && $type->allowsNull()) {
                        // Hydrate uninitialized nullable fields missing from input to null.
                        $property->setValue($dto, null);
                    } else {
                        throw new InvalidArgumentException("Missing required property '{$name}'.");
                    }
                }
            }
        }

        if (method_exists($dto, 'validate')) {
            $dto->validate();
        }

        return $dto;
    }
}
