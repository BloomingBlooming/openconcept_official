<?php

declare(strict_types=1);

final class Hooks
{
    /** @var array<string, array<int, callable>> */
    private static array $actions = [];
    /** @var array<string, array<int, callable>> */
    private static array $filters = [];

    public static function addAction(string $name, callable $callback): void
    {
        self::$actions[$name][] = $callback;
    }

    public static function action(string $name, mixed ...$arguments): void
    {
        foreach (self::$actions[$name] ?? [] as $callback) {
            $callback(...$arguments);
        }
    }

    public static function addFilter(string $name, callable $callback): void
    {
        self::$filters[$name][] = $callback;
    }

    public static function filter(string $name, mixed $value, mixed ...$arguments): mixed
    {
        foreach (self::$filters[$name] ?? [] as $callback) {
            $value = $callback($value, ...$arguments);
        }
        return $value;
    }
}
