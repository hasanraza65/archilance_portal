<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\WorkSession;
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
    protected $description = 'Check user session heartbeat, if not coming for 10 mins, end the session';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tenMinutesAgo = now()->subMinutes(20);
    
        WorkSession::whereNull('end_date')
            ->where(function ($query) use ($tenMinutesAgo) {
    
                // Case 1: last_heartbeat exists
                $query->whereNotNull('last_heartbeat')
                      ->where('last_heartbeat', '<', $tenMinutesAgo);
    
                // Case 2: last_heartbeat is NULL → created_at + 5 hours
                $query->orWhere(function ($q) use ($tenMinutesAgo) {
                    $q->whereNull('last_heartbeat')
                      ->whereRaw(
                          'DATE_ADD(created_at, INTERVAL 5 HOUR) < ?',
                          [$tenMinutesAgo]
                      );
                });
    
            })
            ->update([
                // Date from last activity
                'end_date' => DB::raw("
                    DATE(
                        IFNULL(
                            last_heartbeat,
                            DATE_ADD(created_at, INTERVAL 5 HOUR)
                        )
                    )
                "),
    
                // Time from last activity
                'end_time' => DB::raw("
                    TIME(
                        IFNULL(
                            last_heartbeat,
                            DATE_ADD(created_at, INTERVAL 5 HOUR)
                        )
                    )
                "),
            ]);
    }

}
