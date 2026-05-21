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
                        $value = (int) $value;
                    } elseif ($typeName === 'float') {
                        $value = (float) $value;
                    } elseif ($typeName === 'bool') {
                        $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    } elseif ($typeName === 'string') {
                        $value = (string) $value;
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
