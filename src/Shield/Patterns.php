<?php

namespace Cyberwizard\WatchtowerAgent\Shield;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * IP ranges, user agent expressions and path globs as Shield matches them. Nothing here throws
 * or emits warnings: an invalid CIDR or regular expression simply never matches.
 */
final class Patterns
{
    /**
     * User agents longer than this are cut before matching, which bounds regex work.
     */
    public const MAX_SUBJECT_LENGTH = 1024;

    private const DELIMITERS = ['~', '#', '%', '!', '@', '`', ';', '|'];

    /**
     * IPv4 or IPv6 address inside a CIDR (or equal to a bare address).
     */
    public static function ipInRange(string $ip, string $cidr): bool
    {
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        [$address, $bits] = array_pad(explode('/', $cidr, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $max = str_contains($address, ':') ? 128 : 32;

        if ($bits !== null && (! ctype_digit($bits) || (int) $bits > $max)) {
            return false;
        }

        return IpUtils::checkIp($ip, $cidr);
    }

    /**
     * A PCRE written without delimiters ("(?i)sqlmap|nikto"). Invalid expressions and
     * expressions that hit the backtracking limit do not match.
     */
    public static function regexMatches(string $pattern, string $subject): bool
    {
        $delimiter = null;

        foreach (self::DELIMITERS as $candidate) {
            if (! str_contains($pattern, $candidate)) {
                $delimiter = $candidate;

                break;
            }
        }

        if ($delimiter === null) {
            return false;
        }

        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '100000');

        try {
            $result = @preg_match($delimiter.$pattern.$delimiter, substr($subject, 0, self::MAX_SUBJECT_LENGTH));
        } finally {
            if ($previous !== false) {
                ini_set('pcre.backtrack_limit', $previous);
            }
        }

        return $result === 1;
    }

    /**
     * Path glob: "*" matches anything (slashes included), "?" one character. Case-insensitive,
     * so "/WP-LOGIN.PHP" can't slip past "/wp-login.php".
     */
    public static function globMatches(string $glob, string $path): bool
    {
        $regex = '';
        $length = strlen($glob);

        for ($i = 0; $i < $length; $i++) {
            $regex .= match ($glob[$i]) {
                '*' => '.*',
                '?' => '.',
                default => preg_quote($glob[$i], '~'),
            };
        }

        return preg_match('~^'.$regex.'$~is', $path) === 1;
    }

    /**
     * The request path as globs see it: percent-decoded, duplicate slashes collapsed.
     */
    public static function normalizePath(string $pathInfo): string
    {
        $path = '/'.ltrim(rawurldecode($pathInfo), '/');

        return (string) preg_replace('~/{2,}~', '/', $path);
    }
}
