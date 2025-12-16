<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Auth;
use App\Models\TrackWindow;
use App\Models\SessionTimeAdjustment;
use Carbon\Carbon;

class TrackWindowController extends Controller
{
    public function store(Request $request)
    {
        $employeeId = Auth::id();
        $sessionId  = $request->session_id;
        $newStart   = Carbon::parse($request->start_time);

        // ----------------------------------------------------
        // 1. CHECK FOR TIME ADJUSTMENT (Skip window)
        // ----------------------------------------------------
        $adjustment = SessionTimeAdjustment::where('session_id', $sessionId)
            ->where('start_time', '<=', $newStart)
            ->where('end_time', '>=', $newStart)
            ->first();

        if ($adjustment) {
            return response()->json([
                'message' => 'Track window skipped due to time adjustment range.'
            ], 200);
        }

        // ----------------------------------------------------
        // 2. CLOSE PREVIOUS TRACK WINDOW IF EXISTS
        // ----------------------------------------------------
        $previous = TrackWindow::where('employee_id', $employeeId)
            ->where('session_id', $sessionId)
            ->whereNull('end_time')
            ->first();

        if ($previous) {

            $calculatedEnd = $newStart->copy();

            // Check if this END TIME falls in any time-adjustment range
            $adjForEnd = SessionTimeAdjustment::where('session_id', $sessionId)
                ->where('start_time', '<=', $calculatedEnd)
                ->where('end_time', '>=', $calculatedEnd)
                ->first();

            // If end falls inside adjustment → shift to adjustment start_time
            if ($adjForEnd) {
                $calculatedEnd = Carbon::parse($adjForEnd->start_time);
            }

            // Set end_time
            $previous->end_time = $calculatedEnd;

            // Duration in seconds
            $previous->duration_seconds = $previous->start_time
                ? Carbon::parse($previous->start_time)->diffInSeconds($calculatedEnd)
                : 0;

            $previous->save();
        }

        // ----------------------------------------------------
        // 3. CREATE NEW TRACK WINDOW
        // ----------------------------------------------------
        $newWindow = new TrackWindow();
        $newWindow->employee_id = $employeeId;
        $newWindow->session_id = $sessionId;
        $newWindow->app_name = $request->app_name;
        $newWindow->process_name = $request->process_name;
        $newWindow->window_title = $request->window_title;
        $newWindow->start_time = $newStart;
        $newWindow->save();

        return response()->json([
            'message' => 'Track window created successfully.',
            'data'    => $newWindow
        ]);
    }
}
