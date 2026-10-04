<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Lazy, shared service instances for one request: service('name') returns the
 * same object every time. Tests replace services with injectMock().
 * The database connection is kept across reset() (like a connection pool of one).
 */
abstract class BaseServices
{
    /** @var array<string, object> */
    protected static array $instances = [];

    /** @var array<string, object> */
    protected static array $mocks = [];

    protected static ?Database $connection = null;

    protected static function getSharedInstance(string $key, mixed ...$params): object
    {
        if (isset(static::$mocks[$key])) {
            return static::$mocks[$key];
        }

        return static::$instances[$key] ??= static::$key(false, ...$params);
    }

    public static function injectMock(string $name, object $mock): void
    {
        static::$instances[$name] = $mock;
        static::$mocks[$name]     = $mock;
    }

    /** Forgets every shared instance (a new request in the test-suite). */
    public static function reset(bool $keepConnection = true): void
    {
        static::$instances = [];
        static::$mocks     = [];
        if (! $keepConnection) {
            static::$connection = null;
        }
    }

    public static function resetSingle(string $name): void
    {
        unset(static::$instances[$name], static::$mocks[$name]);
    }

    public static function setConnection(?Database $db): void
    {
        static::$connection = $db;
    }
}
