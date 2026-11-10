<?php

namespace Cyberwizard\WatchtowerAgent\Support;

use Closure;
use DateTimeZone;
use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Laravel\Nightwatch\Sensors\ScheduledTaskSensor;
use ReflectionClass;
use ReflectionFunction;

/**
 * Task names and group hashes computed exactly as Nightwatch's ScheduledTaskSensor and
 * CommandSensor compute them, so Watch Tower links agent records to Nightwatch's own.
 *
 * @see ScheduledTaskSensor::__invoke()
 * @see ScheduledTaskSensor::normalizeTaskName()
 */
class TaskGroup
{
    public static function for(Event $event): string
    {
        $name = self::name($event);
        $timezone = self::timezone($event);
        $repeatSeconds = self::repeatSeconds($event);

        return $repeatSeconds > 0
            ? hash('xxh128', "{$name},{$event->expression},{$timezone},{$repeatSeconds}")
            : hash('xxh128', "{$name},{$event->expression},{$timezone}");
    }

    public static function command(string $name): string
    {
        return hash('xxh128', $name);
    }

    public static function timezone(Event $event): string
    {
        return $event->timezone instanceof DateTimeZone
            ? $event->timezone->getName()
            : (string) $event->timezone;
    }

    public static function repeatSeconds(Event $event): int
    {
        return (int) ($event->repeatSeconds ?? 0);
    }

    public static function name(Event $event): string
    {
        $name = $event->command ?? '';

        $name = str_replace([
            Application::phpBinary(),
            Application::artisanBinary(),
        ], [
            'php',
            preg_replace("#['\"]#", '', Application::artisanBinary()),
        ], $name);

        if ($event instanceof CallbackEvent) {
            $name = $event->getSummaryForDisplay();

            if (in_array($name, ['Closure', 'Callback'], true)) {
                $name = self::closureLocation($event);
            }
        }

        return $name;
    }

    private static function closureLocation(CallbackEvent $event): string
    {
        $callback = (new ReflectionClass($event))->getProperty('callback')->getValue($event);

        if ($callback instanceof Closure) {
            $function = new ReflectionFunction($callback);

            return sprintf(
                'Closure at: %s:%s',
                str_replace(base_path().DIRECTORY_SEPARATOR, '', $function->getFileName() ?: ''),
                $function->getStartLine()
            );
        }

        if (is_string($callback)) {
            return $callback;
        }

        if (is_array($callback)) {
            return is_string($callback[0]) ? $callback[0] : $callback[0]::class;
        }

        return $callback::class;
    }
}
