<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Inertia\Ssr\SsrErrorType;
use Inertia\Ssr\SsrRenderFailed;

/**
 * The Laravel-side trace of an SSR failure.
 *
 * `Inertia\Ssr\HttpGateway` already reacts to a failed render the right way —
 * it drops the SSR result and lets the page render in the browser — and it
 * fires `SsrRenderFailed` so that the application can find out. Nothing here
 * listened. A component that crashed in Node, or a Node process that was not
 * even running, left no line in laravel.log at all; the only record was the
 * Node console, which has no request context and (until 0.153.0) not even a
 * timestamp. This is the listener that was missing: one `laravel.log` line
 * per failure, with the timestamp, environment and request context the
 * ordinary log channel already gives every entry.
 *
 * WHAT IS LOGGED IS WHERE IT FAILED, NEVER WHAT WAS BEING RENDERED. The event
 * carries the full page — every prop, which for a report means student names
 * and pedagogical observations. Only the component name, the request PATH
 * (a query string is dropped: Inertia never sets one, so anything there was
 * put there by a caller), the error message, its classification and the
 * source location are written. The stack trace is left out on purpose: it
 * is long, it repeats the source location, and it is the one field whose
 * contents this code does not control.
 *
 * A connection failure is a warning, not an error: the SSR service being
 * down is an operational state the deployment docs describe how to enter
 * deliberately (`INERTIA_SSR_ENABLED=false`, `systemctl stop lapis-ssr`), and
 * the page still opens. A render failure is an error: a component threw
 * under conditions the SSR server was asked to handle, and someone should
 * look at it.
 */
class LogSsrRenderFailure
{
    public function handle(SsrRenderFailed $event): void
    {
        $context = array_filter([
            'component' => $event->component(),
            'route' => explode('?', $event->url(), 2)[0],
            'error' => $event->error,
            'type' => $event->type->value,
            'hint' => $event->hint,
            'source_location' => $event->sourceLocation,
            'release' => (string) config('app.version'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        if ($event->type === SsrErrorType::Connection) {
            Log::warning('inertia.ssr.unavailable', $context);

            return;
        }

        Log::error('inertia.ssr.render_failed', $context);
    }
}
