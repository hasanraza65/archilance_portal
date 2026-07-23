<?php

namespace App\Traits;

use Carbon\Carbon;

/**
 * Weekday (Mon–Fri) counting for leave ranges.
 *
 * The leave controllers previously counted working days by walking the range one
 * day at a time — either a `while ($start->lte($end))` loop or
 * `collect(CarbonPeriod::create(...))->filter(fn($d) => !$d->isWeekend())->count()`.
 * Both allocate a Carbon object per day and ran once PER leave request, per page
 * load (14+ call sites).
 *
 * This does the same thing arithmetically: whole weeks contribute exactly 5
 * weekdays each, so only the ≤6 leftover days need checking. Results are
 * identical to the old loops (both are INCLUSIVE of start and end, and treat
 * Saturday + Sunday as non-working).
 */
trait CountsWeekdays
{
    /**
     * Number of Mon–Fri days between two dates, inclusive of both ends.
     */
    protected function weekdaysBetween($start, $end): int
    {
        if (empty($start) || empty($end)) {
            return 0;
        }

        try {
            $from = $start instanceof Carbon ? $start->copy() : Carbon::parse($start);
            $to   = $end instanceof Carbon ? $end->copy() : Carbon::parse($end);
        } catch (\Throwable $e) {
            return 0;
        }

        $from = $from->startOfDay();
        $to   = $to->startOfDay();

        if ($to->lt($from)) {
            return 0;
        }

        // Inclusive span in days.
        $totalDays = (int) abs($from->diffInDays($to)) + 1;

        $fullWeeks = intdiv($totalDays, 7);
        $weekdays  = $fullWeeks * 5;

        // Walk only the ≤6 remaining days.
        $remainder = $totalDays % 7;
        if ($remainder > 0) {
            $cursor = $from->copy()->addDays($fullWeeks * 7);
            for ($i = 0; $i < $remainder; $i++) {
                if (!$cursor->isWeekend()) {
                    $weekdays++;
                }
                $cursor->addDay();
            }
        }

        return $weekdays;
    }
}
