<?php

namespace App\Traits;

use Carbon\Carbon;

/**
 * Shared maths for idle time ("session_time_adjustments").
 *
 * Two independent defects were making worked / idle totals wrong:
 *
 *  1. OVERLAPPING ROWS. Duplicate offline-queue retries, two tracker instances,
 *     and sleep-gap records written over an already-open idle all produced idle
 *     rows that overlap each other. Most readers summed them naively, so the
 *     SAME minutes were subtracted more than once — inflating idle and deflating
 *     worked time (and making "idle > worked" possible).
 *
 *  2. UNBOUNDED ROWS. Nothing guaranteed an idle row sat inside its own session
 *     window, so a single stale row could span past the session end and exceed
 *     the whole session duration on its own.
 *
 * Merging the intervals and clamping them to the session window fixes BOTH for
 * every row already in the database, without mutating any stored data.
 *
 * Carbon note: in Carbon 3 diffInSeconds() is SIGNED (later->diff(earlier) is
 * negative), hence the explicit abs() + int cast everywhere below.
 */
trait CalculatesIdleTime
{
    /**
     * Normalise idle rows into a sorted, NON-OVERLAPPING list of [start, end].
     *
     * Rows that are still open (no end_time), unparseable, or zero/negative
     * length are dropped — an open idle contributes nothing until it is closed.
     *
     * @param  iterable|null  $adjustments  rows exposing start_time / end_time
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    protected function mergeIdleIntervals($adjustments): array
    {
        $intervals = [];

        foreach ($adjustments ?? [] as $adj) {
            $rawStart = is_array($adj) ? ($adj['start_time'] ?? null) : ($adj->start_time ?? null);
            $rawEnd   = is_array($adj) ? ($adj['end_time'] ?? null)   : ($adj->end_time ?? null);

            if (empty($rawStart) || empty($rawEnd)) {
                continue;
            }

            try {
                $start = Carbon::parse($rawStart);
                $end   = Carbon::parse($rawEnd);
            } catch (\Throwable $e) {
                continue;
            }

            if ($end->lte($start)) {
                continue;
            }

            $intervals[] = [$start, $end];
        }

        if (empty($intervals)) {
            return [];
        }

        usort($intervals, fn($a, $b) => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());

        $merged = [];
        foreach ($intervals as $iv) {
            $n = count($merged);

            if ($n === 0 || $iv[0]->gt($merged[$n - 1][1])) {
                // Disjoint — starts after the previous one ended.
                $merged[] = [$iv[0]->copy(), $iv[1]->copy()];
            } elseif ($iv[1]->gt($merged[$n - 1][1])) {
                // Overlaps (or touches) — extend the previous interval.
                $merged[$n - 1][1] = $iv[1]->copy();
            }
            // else: fully swallowed by the previous interval — contributes nothing.
        }

        return $merged;
    }

    /**
     * Total seconds of already-merged intervals that fall inside [$from, $to].
     *
     * @param  array<int, array{0: Carbon, 1: Carbon}>  $mergedIntervals
     */
    protected function idleSecondsBetween(array $mergedIntervals, Carbon $from, Carbon $to): int
    {
        if ($to->lte($from)) {
            return 0;
        }

        $seconds = 0;

        foreach ($mergedIntervals as $interval) {
            [$start, $end] = $interval;

            $clampedStart = $start->greaterThan($from) ? $start : $from;
            $clampedEnd   = $end->lessThan($to) ? $end : $to;

            if ($clampedStart->lt($clampedEnd)) {
                $seconds += (int) abs($clampedEnd->diffInSeconds($clampedStart));
            }
        }

        return $seconds;
    }

    /**
     * Idle seconds for ONE session — overlaps merged AND clamped to the
     * session's own window.
     *
     * Because the result is clamped to [sessionStart, sessionEnd], it can never
     * exceed the session duration, so net worked time can never go negative.
     *
     * @param  iterable|null  $adjustments
     */
    protected function sessionIdleSeconds($adjustments, Carbon $sessionStart, Carbon $sessionEnd): int
    {
        return $this->idleSecondsBetween(
            $this->mergeIdleIntervals($adjustments),
            $sessionStart,
            $sessionEnd
        );
    }

    /**
     * Convenience: net worked seconds for one session, never negative.
     *
     * @param  iterable|null  $adjustments
     */
    protected function sessionWorkedSeconds($adjustments, Carbon $sessionStart, Carbon $sessionEnd): int
    {
        if ($sessionEnd->lte($sessionStart)) {
            return 0;
        }

        $duration = (int) abs($sessionEnd->diffInSeconds($sessionStart));
        $idle     = $this->sessionIdleSeconds($adjustments, $sessionStart, $sessionEnd);

        return (int) max(0, $duration - $idle);
    }
}
