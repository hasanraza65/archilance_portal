<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WorkSession;
use App\Models\Screenshot;
use Carbon\Carbon;
use DB;

class CheckHeartBeat extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:check-heart-beat';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check user session heartbeat, if not coming for 20 mins, end the session at its last known activity';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $cutoff = now()->subMinutes(20);

        // Find still-open sessions that have gone silent for too long.
        $staleSessions = WorkSession::whereNull('end_date')
            ->where(function ($query) use ($cutoff) {
                // Case 1: we have a heartbeat, and it is older than the cutoff
                $query->where(function ($q) use ($cutoff) {
                    $q->whereNotNull('last_heartbeat')
                      ->where('last_heartbeat', '<', $cutoff);
                });

                // Case 2: no heartbeat ever, and created_at + 5h is older than the cutoff
                $query->orWhere(function ($q) use ($cutoff) {
                    $q->whereNull('last_heartbeat')
                      ->whereRaw('DATE_ADD(created_at, INTERVAL 5 HOUR) < ?', [$cutoff]);
                });
            })
            ->get();

        foreach ($staleSessions as $session) {
            // Close the session at its TRUE last activity, not the (possibly very early)
            // last heartbeat. Otherwise a session that was active — screenshots kept coming —
            // but whose heartbeats stalled would be closed at ~its start time, producing the
            // bogus "0h 0m" sessions. End = the latest of: last heartbeat, last screenshot.
            $end = null;

            if (!is_null($session->last_heartbeat)) {
                try {
                    $end = Carbon::parse($session->last_heartbeat);
                } catch (\Throwable $e) {
                    $end = null;
                }
            }

            $lastShot = Screenshot::where('session_id', $session->id)->max('created_at');
            if (!is_null($lastShot)) {
                try {
                    $shotTime = Carbon::parse($lastShot);
                    if (is_null($end) || $shotTime->greaterThan($end)) {
                        $end = $shotTime;
                    }
                } catch (\Throwable $e) {
                    // ignore unparsable screenshot time
                }
            }

            // Fallbacks when we have no activity signal at all
            if (is_null($end)) {
                try {
                    $end = Carbon::parse($session->created_at)->addHours(5);
                } catch (\Throwable $e) {
                    $end = now();
                }
            }

            // Never end a session before it started.
            try {
                $start = Carbon::parse($session->start_date . ' ' . $session->start_time);
                if ($end->lessThan($start)) {
                    $end = $start;
                }
            } catch (\Throwable $e) {
                // if start is unparsable, keep computed end
            }

            $session->end_date = $end->toDateString();
            $session->end_time = $end->toTimeString();
            $session->save();
        }
    }
}
