<?php

namespace Cyberwizard\WatchtowerAgent\Support;

use Throwable;

/**
 * Recursion guard. While the agent builds or emits a record, or makes its own calls (for
 * example to Watch Tower's action API), every recorder ignores what happens so the agent never
 * observes itself.
 */
final class Guard
{
    private static int $depth = 0;

    public static function active(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Run agent code: the guard is raised and nothing it throws reaches the host app.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @param  TReturn  $default
     * @return TReturn
     */
    public static function safely(callable $callback, mixed $default = null): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } catch (Throwable) {
            return $default;
        } finally {
            self::$depth--;
        }
    }

    /**
     * Run code that must not be recorded but whose exceptions belong to the caller.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function quietly(callable $callback): mixed
    {
        self::$depth++;

        try {
            return $callback();
        } finally {
            self::$depth--;
        }
    }
}
