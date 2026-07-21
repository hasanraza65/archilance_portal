<?php

namespace App\Traits;

use Carbon\Carbon;

/**
 * Resolves timestamps sent by the desktop/time-tracker client.
 *
 * The tracker sends an unambiguous UTC ISO-8601 timestamp (captured at the moment
 * the event actually happened) in a "*_utc" field. This is correct regardless of:
 *   - the user's timezone / country, and
 *   - any delay between the event and when it is synced to the server (offline queue).
 *
 * We convert that UTC instant into the application's canonical timezone
 * (config('app.timezone'), e.g. Asia/Karachi) so it is stored consistently with
 * server-generated timestamps (screenshots, heartbeats, etc.).
 *
 * If no UTC value is present (older client builds), callers fall back to their
 * existing legacy behaviour, so nothing breaks for un-upgraded apps.
 */
trait ResolvesClientTime
{
    /**
     * @param  string|null  $utc       The client's UTC ISO-8601 string (may be null/empty).
     * @param  Carbon       $fallback  What to use when $utc is missing or unparseable.
     * @return Carbon                  A Carbon in the app timezone.
     */
    protected function resolveClientUtc(?string $utc, Carbon $fallback): Carbon
    {
        if (!empty($utc)) {
            try {
                return Carbon::parse($utc)->setTimezone(config('app.timezone'));
            } catch (\Throwable $e) {
                // Unparseable → fall through to the safe fallback (never crash).
            }
        }

        return $fallback->copy();
    }

    /**
     * True when the client provided a usable UTC timestamp for this event.
     * Lets callers skip legacy per-user clock hacks when we already have an
     * absolute, timezone-correct instant.
     */
    protected function hasClientUtc(?string $utc): bool
    {
        if (empty($utc)) {
            return false;
        }
        try {
            Carbon::parse($utc);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
