<?php

namespace Cyberwizard\WatchtowerAgent\Host;

use Cyberwizard\WatchtowerAgent\Support\Redactor;

class CommandLine
{
    public const MAX_LENGTH = 300;

    private const SENSITIVE = '[\w.-]*(?:key(?![a-z])|secret|token|pass(?:w(?:or)?d|phrase)?(?![a-z])|pwd|credentials?)[\w.-]*';

    private const VALUE = '("[^"]*"|\'[^\']*\'|\S+)';

    public static function redact(string $command): string
    {
        $mask = Redactor::MASK;
        $command = self::clean($command);

        $command = (string) (preg_replace(
            '~\b([a-z][a-z0-9+.-]*://)([^\s:@/]*):([^\s@/]+)@~i',
            '$1$2:'.$mask.'@',
            $command,
        ) ?? $command);

        $command = (string) (preg_replace(
            '/(^|[\s"\'])(-{0,2}'.self::SENSITIVE.'=)'.self::VALUE.'/i',
            '$1$2'.$mask,
            $command,
        ) ?? $command);

        $command = (string) (preg_replace(
            '/(^|\s)(--'.self::SENSITIVE.'\s+)(?!-)'.self::VALUE.'/i',
            '$1$2'.$mask,
            $command,
        ) ?? $command);

        if (preg_match('~(^|[\s/])(mysql[\w-]*|mariadb[\w-]*)(\s|$)~i', $command) === 1) {
            $command = (string) (preg_replace('/(^|\s)-p(?!\s|$)\S+/', '$1-p'.$mask, $command) ?? $command);
        }

        return mb_strlen($command) > self::MAX_LENGTH ? mb_substr($command, 0, self::MAX_LENGTH) : $command;
    }

    public static function clean(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');

        return trim((string) (preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text) ?? $text));
    }
}
