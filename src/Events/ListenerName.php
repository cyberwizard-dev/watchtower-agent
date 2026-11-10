<?php

namespace Cyberwizard\WatchtowerAgent\Events;

use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use ReflectionFunction;
use Throwable;

/**
 * Display names for raw listeners, in the "Class@method" form Laravel and Telescope use.
 */
final class ListenerName
{
    /** @var array<string, bool> */
    private static array $queued = [];

    public static function for(mixed $listener): string
    {
        try {
            if (is_string($listener)) {
                return str_contains($listener, '@') ? $listener : self::classMethod($listener, null);
            }

            if (is_array($listener) && isset($listener[0], $listener[1])) {
                $class = is_object($listener[0]) ? $listener[0]::class : (string) $listener[0];

                return $class.'@'.$listener[1];
            }

            if ($listener instanceof Closure) {
                $reflection = new ReflectionFunction($listener);
                $file = (string) $reflection->getFileName();

                if (str_contains($reflection->getName(), '{closure')) {
                    return 'Closure at '.self::relative($file).':'.$reflection->getStartLine();
                }

                $scope = $reflection->getClosureScopeClass()?->getName();

                return ($scope !== null ? $scope.'@' : '').$reflection->getName();
            }

            if (is_object($listener)) {
                return $listener::class.'@__invoke';
            }
        } catch (Throwable) {
            //
        }

        return 'unknown';
    }

    /**
     * Whether the raw listener is a class implementing ShouldQueue (Laravel queues it).
     */
    public static function queued(mixed $listener): bool
    {
        $class = match (true) {
            is_string($listener) => explode('@', $listener, 2)[0],
            is_array($listener) && isset($listener[0]) && is_string($listener[0]) => $listener[0],
            default => null,
        };

        if ($class === null || $class === '') {
            return false;
        }

        return self::$queued[$class] ??= class_exists($class) && is_subclass_of($class, ShouldQueue::class);
    }

    private static function classMethod(string $class, ?string $method): string
    {
        if ($method === null) {
            $method = ! method_exists($class, 'handle') && method_exists($class, '__invoke') ? '__invoke' : 'handle';
        }

        return $class.'@'.$method;
    }

    private static function relative(string $file): string
    {
        $base = function_exists('base_path') ? rtrim(base_path(), '/').'/' : '';

        return $base !== '' && str_starts_with($file, $base) ? substr($file, strlen($base)) : $file;
    }
}
