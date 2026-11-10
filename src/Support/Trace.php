<?php

namespace Cyberwizard\WatchtowerAgent\Support;

use Illuminate\Support\Facades\Context;
use Throwable;

/**
 * The Nightwatch trace id of the current execution. Nightwatch keeps it in hidden context under
 * "nightwatch_trace_id" (Compatibility::addTraceIdToContext), so it is read from there instead
 * of from Core.
 */
class Trace
{
    public static function current(): ?string
    {
        try {
            $trace = Context::getHidden('nightwatch_trace_id');
        } catch (Throwable) {
            return null;
        }

        return is_string($trace) && $trace !== '' ? $trace : null;
    }
}
