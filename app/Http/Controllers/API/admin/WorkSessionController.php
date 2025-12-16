<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Screenshot;
use App\Models\User;
use App\Models\WorkSession;
use App\Models\TrackWindow;
use Auth;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\DB;

class WorkSessionController extends Controller
{

   public function index(Request $request)
    {
        try {
            
        $check_employee_user = User::find($request->employee_id);
        
        if(Auth::check() && Auth::user()->employee_type == "Manager"){
            
            if($check_employee_user->employee_type == "Manager"){
                return response()->json('Unauthorized',401);
            }
            
        }
            
        $query = WorkSession::with('screenshots', 'taskDetail', 'userDetail', 'idleTimes')
        ->where('user_id', $request->employee_id);
    
        // Use current date if no dates provided
        $filterStartDate = $request->start_date ?? now()->toDateString();
        $filterEndDate = $request->end_date ?? $filterStartDate;
        
        // Date filters
        $query->where(function($q) use ($filterStartDate, $filterEndDate) {
            $q->whereBetween('start_date', [$filterStartDate, $filterEndDate])
              ->orWhereBetween('end_date', [$filterStartDate, $filterEndDate])
              ->orWhere(function($q2) use ($filterStartDate, $filterEndDate) {
                  $q2->where('start_date', '<', $filterStartDate)
                     ->where('end_date', '>', $filterEndDate);
              });
        });
        
        // ✅ Extra filters for task_id / project_id
        if ($request->filled('task_id')) {
            $query->where('task_id', $request->task_id);
        }
        
        if ($request->filled('project_id')) {
            $query->whereHas('taskDetail', function($q) use ($request) {
                $q->where('project_id', $request->project_id);
            });
        }



        $sessions = $query->orderBy('created_at', 'desc')->paginate(100);

        $overallTotalSeconds = 0;
        $time_strings_hr = [];

        foreach ($sessions as $session) {
            $sessionStart = Carbon::parse($session->start_date . ' ' . $session->start_time);
            
            if (is_null($session->end_time)) {
                $sessionEnd = now();
                $session->total_time = 'Running';
            } else {
                $endDate = $session->end_date ?? $session->start_date;
                $sessionEnd = Carbon::parse($endDate . ' ' . $session->end_time);
            }

            // Initialize duration for the filtered date range
            $sessionDuration = 0;
            
            // Get all dates in the filter range
            $filterDates = [];
            $currentDate = Carbon::parse($filterStartDate);
            $endDateObj = Carbon::parse($filterEndDate);
            
            while ($currentDate->lte($endDateObj)) {
                $filterDates[] = $currentDate->toDateString();
                $currentDate->addDay();
            }

            // Calculate time for each day in filter range
            foreach ($filterDates as $date) {
                $dayStart = Carbon::parse($date)->startOfDay();
                $dayEnd = Carbon::parse($date)->endOfDay();
                
                // Get overlapping period between session and this specific day
                $workStart = max($sessionStart, $dayStart);
                $workEnd = min($sessionEnd, $dayEnd);
                
                if ($workStart->lt($workEnd)) {
                    $sessionDuration += $workEnd->diffInSeconds($workStart);
                }
            }

            // Calculate adjustments for the filtered period
            $adjustmentSeconds = 0;
            $adjustments = DB::table('session_time_adjustments')
                ->where('session_id', $session->id)
                ->get();

            foreach ($adjustments as $adj) {
                if (empty($adj->start_time) || empty($adj->end_time)) {
                    continue;
                }
                
                $adjStart = Carbon::parse($adj->start_time);
                $adjEnd = Carbon::parse($adj->end_time);
                
                // Calculate adjustments day by day to respect midnight boundaries
                foreach ($filterDates as $date) {
                    $dayStart = Carbon::parse($date)->startOfDay();
                    $dayEnd = Carbon::parse($date)->endOfDay();
                    
                    $adjStartFiltered = max($adjStart, $dayStart);
                    $adjEndFiltered = min($adjEnd, $dayEnd);
                    
                    if ($adjStartFiltered->lt($adjEndFiltered)) {
                        $adjustmentSeconds += $adjEndFiltered->diffInSeconds($adjStartFiltered);
                    }
                }
            }

            $netSeconds = $sessionDuration - $adjustmentSeconds;
            
            if ($session->total_time !== 'Running') {
                $hours = floor(abs($netSeconds) / 3600);
                $minutes = floor((abs($netSeconds) % 3600) / 60);
                $session->total_time = sprintf('%dh %dm', $hours, $minutes);
                $time_strings_hr[] = $session->total_time;
            }

            $session->raw_calculation = [
                'filter_range' => [$filterStartDate, $filterEndDate],
                'session_period' => [
                    'start' => $sessionStart->format('Y-m-d H:i:s'),
                    'end' => $sessionEnd->format('Y-m-d H:i:s')
                ],
                'session_duration' => $sessionDuration,
                'adjustments' => $adjustmentSeconds,
                'net_seconds' => $netSeconds
            ];

            if ($netSeconds > 0) {
                $overallTotalSeconds += $netSeconds;
            }
        }

        // Calculate total time string
        $totalMinutes = 0;
        foreach ($time_strings_hr as $time) {
            preg_match('/(\d+)h (\d+)m/', $time, $matches);
            if (count($matches) === 3) {
                $hours = (int)$matches[1];
                $minutes = (int)$matches[2];
                $totalMinutes += ($hours * 60) + $minutes;
            }
        }
        
        $totalHours = floor($totalMinutes / 60);
        $remainingMinutes = $totalMinutes % 60;
        $totalTimeString = "{$totalHours}h {$remainingMinutes}m";
        
         $ids = $sessions->pluck('id')->toArray();
        $windows_activity = TrackWindow::whereIn('session_id', $ids)->get();
        
        return response()->json(
            array_merge(
                $sessions->toArray(),
                [
                    'overall_total_time' => $totalTimeString,
                    'windows_activity' => $windows_activity
                ]
            )
        );
        
        
    } catch (\Exception $e) {
       // \Log::error('WorkSession Error: '.$e->getMessage());
        return response()->json([
            'error' => 'Server error',
            'message' => $e->getMessage()
        ], 500);
    }
}

    public function show($id){

        $data = WorkSession::with('screenshots','taskDetail','userDetail')
            ->findOrFail($id);

        return response()->json($data);
    }

    public function store(Request $request)
    {
        $userId = Auth::id();

        // 1. Check if any open session exists for this user
        $openSession = WorkSession::where('user_id', $userId)
            ->whereNull('end_time')
            ->latest('start_time')
            ->first();

        // 2. Close the previous open session (if exists)
        if ($openSession) {
            $openSession->end_time = Carbon::now();
            $openSession->save();
        }

         $now = Carbon::now();

        // 3. Start a new session
        $newSession = new WorkSession();
        $newSession->user_id = $userId;
        $newSession->task_id = $request->task_id;
        $newSession->memo_content = $request->memo_content;
        $newSession->start_time = $now; // explicitly setting start time
        $newSession->start_date = $now->toDateString(); // explicitly setting start time
        $newSession->save();

        return response()->json([
            'message' => 'Work session started successfully.',
            'work_session' => $newSession
        ]);
    }

    public function stop(Request $request)
    {
        $userId = Auth::id();

        // Find the latest open session
        $openSession = WorkSession::where('user_id', $userId)
            ->whereNull('end_time')
            ->latest('start_time')
            ->first();

        if (!$openSession) {
            return response()->json([
                'message' => 'No active work session found to stop.'
            ], 404);
        }

        // Close the session by setting end_time and end_date
        $now = Carbon::now();
        $openSession->end_time = $now;
        $openSession->end_date = $now->toDateString(); // Sets just the date portion (YYYY-MM-DD)
        $openSession->save();

        return response()->json([
            'message' => 'Work session stopped successfully.',
            'work_session' => $openSession
        ]);
    }

    public function destroy($id)
    {
        $task = WorkSession::findOrFail($id);
        $task->delete();

        return response()->json(['message' => 'Work session deleted successfully.']);
    }

    public function manualSession(Request $request)
    {

        $data = new WorkSession();
        $data->user_id = $request->user_id;
        $data->start_date = $request->start_date;
        $data->start_time = $request->start_time;
        $data->end_date = $request->end_date;
        $data->end_time = $request->end_time;
        $data->memo_content = $request->memo_content;
        $data->task_id = $request->task_id;
        $data->save();

        return response()->json([
            'message' => 'Manual time added successfully.',
            'work_session' => $data
        ]);

    }


}
