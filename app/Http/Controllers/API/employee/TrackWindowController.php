<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Auth;
use App\Models\TrackWindow;
use App\Models\SessionTimeAdjustment;
use Carbon\Carbon;
use DB;

class TrackWindowController extends Controller
{
    public function store(Request $request)
    {
        $employeeId = Auth::id();
    
        // Normalize input (single OR bulk)
        $entries = isset($request->data) && is_array($request->data)
            ? $request->data
            : [$request->all()];
    
        // Sort entries by start_time (VERY IMPORTANT)
        usort($entries, function ($a, $b) {
            return strtotime($a['start_time']) <=> strtotime($b['start_time']);
        });
    
        $created = [];
    
        DB::beginTransaction();
    
        try {
    
            foreach ($entries as $entry) {
    
                $sessionId = $entry['session_id'];
                $newStart  = Carbon::parse($entry['start_time']);
    
                // ----------------------------------------------------
                // 1. CHECK TIME ADJUSTMENT
                // ----------------------------------------------------
                $adjustment = SessionTimeAdjustment::where('session_id', $sessionId)
                    ->where('start_time', '<=', $newStart)
                    ->where('end_time', '>=', $newStart)
                    ->first();
    
                if ($adjustment) {
                    continue; // skip this entry
                }
    
                // ----------------------------------------------------
                // 2. CLOSE PREVIOUS WINDOW
                // ----------------------------------------------------
                $previous = TrackWindow::where('employee_id', $employeeId)
                    ->where('session_id', $sessionId)
                    ->whereNull('end_time')
                    ->latest('start_time')
                    ->first();
    
                if ($previous) {
    
                    $calculatedEnd = $newStart->copy();
    
                    $adjForEnd = SessionTimeAdjustment::where('session_id', $sessionId)
                        ->where('start_time', '<=', $calculatedEnd)
                        ->where('end_time', '>=', $calculatedEnd)
                        ->first();
    
                    if ($adjForEnd) {
                        $calculatedEnd = Carbon::parse($adjForEnd->start_time);
                    }
    
                    $previous->end_time = $calculatedEnd;
    
                    $previous->duration_seconds = $previous->start_time
                        ? Carbon::parse($previous->start_time)->diffInSeconds($calculatedEnd)
                        : 0;
    
                    $previous->save();
                }
    
                // ----------------------------------------------------
                // 3. CREATE NEW WINDOW
                // ----------------------------------------------------
                $newWindow = TrackWindow::create([
                    'employee_id'  => $employeeId,
                    'session_id'   => $sessionId,
                    'app_name'     => $entry['app_name'] ?? null,
                    'process_name' => $entry['process_name'] ?? null,
                    'window_title' => $entry['window_title'] ?? null,
                    'start_time'   => $newStart,
                ]);
    
                $created[] = $newWindow;
            }
    
            DB::commit();
    
            return response()->json([
                'message' => 'Track windows processed successfully.',
                'count'   => count($created),
                'data'    => $created
            ]);
    
        } catch (\Exception $e) {
            DB::rollBack();
    
            return response()->json([
                'message' => 'Something went wrong',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
