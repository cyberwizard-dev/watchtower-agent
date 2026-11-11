<?php

namespace Cyberwizard\WatchtowerAgent\Browser;

use Cyberwizard\WatchtowerAgent\WatchtowerAgent;

/**
 * The script tag behind @watchtowerBrowser. With the proxy on (the default) the
 * script and its beacons stay on the app's own domain, so content blockers do
 * not drop them; without it the script loads from Watch Tower directly.
 * Renders nothing unless browser monitoring is configured.
 */
class BrowserTag
{
    public static function render(): string
    {
        if (! WatchtowerAgent::feature('browser')) {
            return '';
        }

        $attributes = ['data-key' => (string) config('watchtower-agent.browser.key')];

        if (self::proxied()) {
            $src = url(self::path().'/s.js');
            $attributes['data-endpoint'] = self::path().'/e';
        } else {
            $src = rtrim((string) config('watchtower-agent.browser.base_url'), '/').'/watchtower-browser.js';
        }

        foreach (['release' => 'data-release', 'sample' => 'data-sample'] as $option => $attribute) {
            $value = config("watchtower-agent.browser.{$option}");

            if ($value !== null && $value !== '') {
                $attributes[$attribute] = (string) $value;
            }
        }

        $html = '<script src="'.e($src).'"';

        foreach ($attributes as $name => $value) {
            $html .= ' '.$name.'="'.e($value).'"';
        }

        return $html.' defer></script>';
    }

    public static function proxied(): bool
    {
        return (bool) config('watchtower-agent.browser.proxy', true) && filled(config('watchtower-agent.browser.token'));
    }

    /** The proxy's path prefix on the app's domain, e.g. "/_t". */
    public static function path(): string
    {
        return '/'.trim((string) config('watchtower-agent.browser.path', '/_t'), '/');
    }
}
