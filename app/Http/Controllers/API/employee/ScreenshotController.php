<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Auth;
use App\Models\Screenshot;
use App\Models\WorkSession;
use App\Models\SessionTimeAdjustment;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ScreenshotController extends Controller
{

    public function store(Request $request)
    {
        $userId = Auth::id();
        
        //\Log::info('screenshot time '.Carbon::now());
    
        // 1. Check if a session exists for today with null end_time
        $currentSession = WorkSession::whereDate('start_time', Carbon::today())
            ->whereNull('end_time')
            ->where('user_id', $userId)
            ->first();
    
        // 2. If no session found, handle older open sessions + create new one
        if (!$currentSession) {
            // 2a. Close any previous open sessions
            $previousOpenSessions = WorkSession::whereNull('end_time')
                ->where('user_id', $userId)
                ->get();
    
            foreach ($previousOpenSessions as $session) {
                $session->end_time = Carbon::now();
                $session->save();
            }
    
            // 2b. Create a new session
            $currentSession = new WorkSession();
            $currentSession->user_id = $userId;
            $currentSession->task_id = $request->task_id ?? null;
            $currentSession->memo_content = $request->memo_content ?? null;
            $currentSession->start_time = Carbon::now();
            $currentSession->save();
        }
    
        // 🔹 3. If screenshot comes in, end any active idle time for this session
        $openIdle = SessionTimeAdjustment::where('session_id', $currentSession->id)
            ->whereNull('end_time')
            ->first();
    
        if ($openIdle) {
            $openIdle->end_time = Carbon::now();
            $openIdle->save();
        }
    
        // 4. Store Screenshot
        $screenshot = new Screenshot();
    
        if ($request->hasFile('screenshot_image')) {
            $path = $request->file('screenshot_image')->store('uploads/screenshots', 'public');
            $screenshot->screenshot_file = $path;
        }
    
        $screenshot->session_id = $currentSession->id;
        $screenshot->created_at = Carbon::now();
        $screenshot->user_id = $userId;
        $screenshot->save();
    
        return response()->json([
            'message' => 'Screenshot added successfully. Idle time (if active) was closed.',
            'screenshot' => $screenshot
        ]);
    }

    public function destroy($id)
    {
        $userId = Auth::id();

        // Begin DB transaction for safety
        DB::beginTransaction();

        try {
            $screenshot = Screenshot::where('id', $id)
                ->where('user_id', $userId)
                ->firstOrFail();

            $session = WorkSession::find($screenshot->session_id);

            if (!$session) {
                return response()->json(['error' => 'Associated session not found.'], 404);
            }

            $sessionScreenshots = Screenshot::where('session_id', $session->id)
                ->orderBy('created_at')
                ->get();

            $index = $sessionScreenshots->search(fn($ss) => $ss->id === $screenshot->id);

            // CASE 1: Only screenshot in session → delete session entirely
            if ($sessionScreenshots->count() === 1) {
                if ($screenshot->screenshot_file && \Storage::disk('public')->exists($screenshot->screenshot_file)) {
                    \Storage::disk('public')->delete($screenshot->screenshot_file);
                }

                $screenshot->delete();
                $session->delete();

                DB::commit();

                return response()->json(['message' => 'Screenshot and session deleted (only screenshot).']);
            }

            // Determine the adjustment range (from previous screenshot to current)
            $prevScreenshot = $index > 0 ? $sessionScreenshots[$index - 1] : null;
            $nextScreenshot = $sessionScreenshots[$index + 1] ?? null;

            $adjustStart = $prevScreenshot
                ? $prevScreenshot->created_at
                : $screenshot->created_at->copy()->subSeconds(1);

            $adjustEnd = $nextScreenshot
                ? $screenshot->created_at
                : $screenshot->created_at->copy(); // If last, adjust only that point

            // Log this time removal
            SessionTimeAdjustment::create([
                'session_id' => $session->id,
                'start_time' => $adjustStart,
                'end_time' => $adjustEnd,
            ]);

            // Delete screenshot file
            if ($screenshot->screenshot_file && \Storage::disk('public')->exists($screenshot->screenshot_file)) {
                \Storage::disk('public')->delete($screenshot->screenshot_file);
            }

            $screenshot->delete();

            DB::commit();

            return response()->json(['message' => 'Screenshot deleted and time adjustment logged.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to delete screenshot.'], 500);
        }

    }


   public function upsertIdleTime(Request $request)
{
    $request->validate([
        'session_id' => 'required|exists:work_sessions,id',
    ]);

    $now = Carbon::now();
    $sessionId = $request->session_id;

    // 1️⃣ Check if there's already an open idle record
    $openAdjustment = SessionTimeAdjustment::where('session_id', $sessionId)
        ->whereNull('end_time')
        ->first();

    if ($openAdjustment) {
        // Close the idle period
        $openAdjustment->end_time = $now;
        $openAdjustment->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Idle period closed.',
            'data' => $openAdjustment
        ]);
    }

    // 2️⃣ Fetch the most recent idle period (if any)
    $lastIdle = SessionTimeAdjustment::where('session_id', $sessionId)
        ->whereNotNull('end_time')
        ->latest('end_time')
        ->first();

    // 3️⃣ Define thresholds
    $minGapBetweenIdles = 20; // minutes (minimum time gap before logging another idle)
    $ignoreSmallBreaksIfWorkedLong = 10; // minutes (if idle < this, skip it)
    $longWorkThreshold = 60; // minutes (if user worked > this, ignore small idles)

    $session = WorkSession::find($sessionId);
    
    // Ensure session start time is Carbon instance
    $sessionStart = $session->start_time ? Carbon::parse($session->start_time) : null;

    $shouldCreateIdle = true;

    if ($lastIdle) {
        // Ensure end_time is Carbon instance
        $lastIdleEndTime = Carbon::parse($lastIdle->end_time);
        $minutesSinceLastIdle = $lastIdleEndTime->diffInMinutes($now);

        // 🕒 Skip if new idle happens too soon after previous one
        if ($minutesSinceLastIdle < $minGapBetweenIdles) {
            $shouldCreateIdle = false;
        }
    }

    // ⏱ Check how long the user was working continuously before this idle
    if ($sessionStart) {
        // Ensure lastActivity is a Carbon instance
        $lastActivity = $lastIdle ? Carbon::parse($lastIdle->end_time) : $sessionStart;
        $workedMinutes = $lastActivity->diffInMinutes($now);

        // If worked for a long time, ignore tiny idle gaps (e.g., 5–6 min)
        if ($workedMinutes >= $longWorkThreshold && $workedMinutes <= ($longWorkThreshold + $ignoreSmallBreaksIfWorkedLong)) {
            $shouldCreateIdle = false;
        }
    }

    if (! $shouldCreateIdle) {
        return response()->json([
            'status' => 'skipped',
            'message' => 'Idle period skipped due to recent activity or short gap.',
        ]);
    }

    // 4️⃣ Create a new idle period
    $newAdjustment = SessionTimeAdjustment::create([
        'session_id' => $sessionId,
        'start_time' => $now,
        'end_time' => null,
    ]);

    return response()->json([
        'status' => 'success',
        'message' => 'Idle period started.',
        'data' => $newAdjustment
    ]);
}


     public function deleteIdleTime(Request $request){

        $data = SessionTimeAdjustment::findOrFail($request->idle_time_id);

        $data->delete();

        return response()->json([
                'status' => 'success',
                'message' => 'Idle period deleted.'
            ]);

    }

}
