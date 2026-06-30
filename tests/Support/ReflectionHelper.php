<?php

declare(strict_types=1);

namespace Swoole\Zookeeper\Tests\Support;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class ReflectionHelper
{
    public static function getProperty(object $object, string $property): mixed
    {
        $ref = new ReflectionProperty($object, $property);
        $ref->setAccessible(true);
        return $ref->getValue($object);
    }

    public static function setProperty(object $object, string $property, mixed $value): void
    {
        $ref = new ReflectionProperty($object, $property);
        $ref->setAccessible(true);
        $ref->setValue($object, $value);
    }

    public static function invoke(object $object, string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod($object, $method);
        $ref->setAccessible(true);
        return $ref->invoke($object, ...$args);
    }

    public static function getClassConstant(string $class, string $constant): mixed
    {
        $ref = new ReflectionClass($class);
        return $ref->getConstant($constant);
    }
}
