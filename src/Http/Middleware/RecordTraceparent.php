<?php

namespace Cyberwizard\WatchtowerAgent\Http\Middleware;

use Closure;
use Cyberwizard\WatchtowerAgent\Http\TraceContext;
use Cyberwizard\WatchtowerAgent\Records\Recorder;
use Cyberwizard\WatchtowerAgent\Support\Guard;
use Cyberwizard\WatchtowerAgent\WatchtowerAgent;
use Illuminate\Http\Request;

/**
 * Global middleware: an incoming W3C traceparent becomes wt-trace-link, linking this request's
 * Nightwatch trace to the caller's.
 */
class RecordTraceparent
{
    public function __construct(private Recorder $recorder) {}

    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->headers->has('traceparent')) {
            Guard::safely(function () use ($request): void {
                if (! WatchtowerAgent::feature('trace_context') || ! config('watchtower-agent.trace_context.incoming', true)) {
                    return;
                }

                $link = TraceContext::parse($request->headers->get('traceparent'));

                if ($link !== null) {
                    $this->recorder->inExecution('wt-trace-link', $link, 1);
                }
            });
        }

        return $next($request);
    }
}
