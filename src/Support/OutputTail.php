<?php

namespace Cyberwizard\WatchtowerAgent\Support;

use Illuminate\Console\Scheduling\Event;
use ReflectionProperty;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Reads the last N bytes of captured output and makes it safe to JSON-encode.
 */
class OutputTail
{
    /**
     * @param  string  $output  the captured text
     * @param  bool  $truncated  whether older output was dropped
     */
    public function __construct(
        public readonly string $output,
        public readonly bool $truncated,
    ) {}

    public static function fromString(string $output, int $maxBytes): self
    {
        $maxBytes = max(1, $maxBytes);
        $truncated = strlen($output) > $maxBytes;

        if ($truncated) {
            $output = substr($output, -$maxBytes);
        }

        return new self(self::sanitise($output, $truncated), $truncated);
    }

    /**
     * The output file of a scheduled event, or null when it is not captured (no path, the null
     * device, or an unreadable file).
     */
    public static function fromEvent(Event $event, int $maxBytes): ?self
    {
        $path = $event->output ?? null;

        if (! self::isCapturedPath($path, $event)) {
            return null;
        }

        return self::fromFile($path, $maxBytes);
    }

    public static function fromFile(string $path, int $maxBytes): ?self
    {
        try {
            if (! is_file($path) || ! is_readable($path)) {
                return null;
            }

            clearstatcache(true, $path);
            $size = (int) filesize($path);
            $maxBytes = max(1, $maxBytes);
            $truncated = $size > $maxBytes;

            $handle = fopen($path, 'rb');

            if ($handle === false) {
                return null;
            }

            try {
                if ($truncated) {
                    fseek($handle, -$maxBytes, SEEK_END);
                }

                $contents = stream_get_contents($handle, $maxBytes);
            } finally {
                fclose($handle);
            }

            return new self(self::sanitise($contents === false ? '' : $contents, $truncated), $truncated);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Reads a BufferedOutput without consuming it, so Artisan::output() still works for the
     * caller.
     */
    public static function fromBufferedOutput(BufferedOutput $output, int $maxBytes): ?self
    {
        try {
            $buffer = (new ReflectionProperty(BufferedOutput::class, 'buffer'))->getValue($output);
        } catch (Throwable) {
            return null;
        }

        return is_string($buffer) ? self::fromString($buffer, $maxBytes) : null;
    }

    public static function isCapturedPath(?string $path, ?Event $event = null): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        if (in_array($path, ['/dev/null', 'NUL'], true)) {
            return false;
        }

        return $event === null || $path !== $event->getDefaultOutput();
    }

    /**
     * Strips ANSI escapes and invalid UTF-8 (including a character cut in half by the tail).
     */
    private static function sanitise(string $output, bool $truncated): string
    {
        $output = preg_replace('/\e\[[0-9;?]*[ -\/]*[@-~]/', '', $output) ?? $output;

        if ($truncated) {
            // Drop continuation bytes left at the start by cutting a multibyte character.
            $output = preg_replace('/^[\x80-\xBF]{1,3}/', '', $output) ?? $output;
        }

        return mb_scrub($output, 'UTF-8');
    }
}
