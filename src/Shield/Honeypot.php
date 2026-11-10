<?php

namespace Cyberwizard\WatchtowerAgent\Shield;

use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;

/**
 * The hidden honeypot input (@watchtowerHoneypot). Humans never see or fill it: it's moved off
 * screen, hidden from assistive technology, skipped by Tab and excluded from autofill.
 */
final class Honeypot
{
    public const DEFAULT_FIELD = 'wt_hp_website';

    /**
     * The field name: from the cached rules when Watch Tower sent one, else the configured name.
     */
    public static function fieldName(): string
    {
        $fromRules = Guard::safely(fn (): ?string => app(RulesRepository::class)->current()?->honeypot['field'] ?? null);

        if (is_string($fromRules) && $fromRules !== '') {
            return $fromRules;
        }

        $configured = config('watchtower-agent.shield.honeypot_field', self::DEFAULT_FIELD);

        return is_string($configured) && $configured !== '' ? $configured : self::DEFAULT_FIELD;
    }

    /**
     * The markup, or an empty string when Shield is off.
     */
    public static function render(): string
    {
        if (! WatchtowerAgent::feature('shield')) {
            return '';
        }

        $name = htmlspecialchars(self::fieldName(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div aria-hidden="true" style="position:absolute!important;left:-10000px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;">'
            .'<label for="'.$name.'">Leave this field empty</label>'
            .'<input type="text" id="'.$name.'" name="'.$name.'" value="" tabindex="-1" autocomplete="off">'
            .'</div>';
    }
}
