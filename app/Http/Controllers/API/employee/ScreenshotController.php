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

use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

use Illuminate\Support\Str;


class ScreenshotController extends Controller
{

    public function store(Request $request)
    {
        $userId = Auth::id();
        /*
        if($userId == 159){
            \Log::info('window title '.$request->window_title);
        } */


        //\Log::info('screenshot time '.Carbon::now());

        // 1. Check if a session exists for today with null end_time
        $currentSession = WorkSession::where('user_id', $userId)
        $currentSession = WorkSession::where('user_id', $userId)
            ->whereNull('end_time')
            ->latest('start_time')
            ->latest('start_time')
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
        if ($request->hasFile('screenshot_image')) {

            $file = $request->file('screenshot_image');

            // store original image
            $path = $file->store('uploads/screenshots', 'public');

            $screenshot = new Screenshot();

            $screenshot->screenshot_file = $path;

            $title = strtolower(trim($request->window_title ?? ''));

            if (!empty($title) && Str::contains($title, 'whatsapp')) {
                /*
                if($userId == 159){
                \Log::info('BLUR TRIGGERED: '.$title); // debug
                } */

                $manager = new ImageManager(new Driver());

                // read from stored file (IMPORTANT FIX)
                $image = $manager->read(Storage::disk('public')->path($path));

                $image->blur(85);

                $blurPath = 'uploads/screenshots/blur_' . time() . '_' . $file->getClientOriginalName();

                Storage::disk('public')->put($blurPath, (string) $image->encode());

                $screenshot->emp_screenshot_file = $blurPath;

            } else {
                /*
                if($userId == 159){
                \Log::info('NO BLUR: '.$title); // debug
                } */

                $screenshot->emp_screenshot_file = $path;
            }

            $screenshot->session_id = $currentSession->id;
            $screenshot->created_at = Carbon::now();
            $screenshot->user_id = $userId;
            $screenshot->save();
        }

        //ending storing images

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
                    // \Storage::disk('public')->delete($screenshot->screenshot_file);
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
                // \Storage::disk('public')->delete($screenshot->screenshot_file);
            }

            $screenshot->delete();

            DB::commit();

            return response()->json(['message' => 'Screenshot deleted and time adjustment logged.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to delete screenshot.'], 500);
        }

    }

    public function deletedScreenshots($session_id)
    {
        $screenshots = Screenshot::onlyTrashed()
            ->where('session_id', $session_id)
            ->get();

        return response()->json($screenshots);
    }

    public function upsertIdleTime(Request $request)
    {
        $request->validate([
            'session_id' => 'required|exists:work_sessions,id',
            'start_date' => 'sometimes|date_format:Y-m-d',
            'start_time' => 'sometimes|date_format:H:i:s',
            'end_date' => 'sometimes|date_format:Y-m-d',
            'end_time' => 'sometimes|date_format:H:i:s',
        ]);

        $now = Carbon::now();
        $sessionId = $request->session_id;

        // ──────────────────────────────────────────────
        // CASE A: No explicit times supplied → real-time
        //         toggle (open / close) behaviour
        // ──────────────────────────────────────────────
        if (!$request->has('start_date')) {

            $openAdjustment = SessionTimeAdjustment::where('session_id', $sessionId)
                ->whereNull('end_time')
                ->first();

            if ($openAdjustment) {
                $openAdjustment->end_time = $now;
                $openAdjustment->save();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Idle period closed.',
                    'data' => $openAdjustment,
                ]);
            }

            // ── Throttle / skip logic ──
            $lastIdle = SessionTimeAdjustment::where('session_id', $sessionId)
                ->whereNotNull('end_time')
                ->latest('end_time')
                ->first();

            $minGapBetweenIdles = 20;
            $ignoreSmallBreaksIfWorkedLong = 10;
            $longWorkThreshold = 60;

            $session = WorkSession::find($sessionId);
            $sessionStart = $session->start_time ? Carbon::parse($session->start_time) : null;
            $shouldCreate = true;

            if ($lastIdle) {
                $lastIdleEndTime = Carbon::parse($lastIdle->end_time);
                $minutesSinceLastIdle = $lastIdleEndTime->diffInMinutes($now);

                if ($minutesSinceLastIdle < $minGapBetweenIdles) {
                    $shouldCreate = false;
                }
            }

            if ($sessionStart && $shouldCreate) {
                $lastActivity = $lastIdle ? Carbon::parse($lastIdle->end_time) : $sessionStart;
                $workedMinutes = $lastActivity->diffInMinutes($now);

                if (
                    $workedMinutes >= $longWorkThreshold &&
                    $workedMinutes <= ($longWorkThreshold + $ignoreSmallBreaksIfWorkedLong)
                ) {
                    $shouldCreate = false;
                }
            }

            $openExists = SessionTimeAdjustment::where('session_id', $sessionId)
                ->whereNull('end_time')
                ->exists();

            if (!$shouldCreate || $openExists) {
                return response()->json([
                    'status' => 'skipped',
                    'message' => 'Idle period skipped due to recent activity or short gap.',
                ]);
            }

            $newAdjustment = SessionTimeAdjustment::create([
                'session_id' => $sessionId,
                'start_time' => $now,
                'end_time' => null,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Idle period started.',
                'data' => $newAdjustment,
            ]);
        }

        // ──────────────────────────────────────────────
        // CASE B: Explicit dates + times supplied
        //         → offline / catch-up slot with smart
        //           splitting around existing records
        // ──────────────────────────────────────────────
        $incomingStart = Carbon::parse($request->start_date . ' ' . $request->start_time);
        $incomingEnd = Carbon::parse($request->end_date . ' ' . $request->end_time);

        if ($incomingStart->gte($incomingEnd)) {
            return response()->json([
                'status' => 'error',
                'message' => 'start_time must be before end_time.',
            ], 422);
        }

        // Fetch every existing closed slot that overlaps [incomingStart, incomingEnd]
        $existingSlots = SessionTimeAdjustment::where('session_id', $sessionId)
            ->whereNotNull('end_time')
            ->where('start_time', '<', $incomingEnd)
            ->where('end_time', '>', $incomingStart)
            ->orderBy('start_time')
            ->get();

        // Build free intervals by punching holes for each existing slot
        $freeIntervals = $this->subtractSlots($incomingStart, $incomingEnd, $existingSlots);

        if (empty($freeIntervals)) {
            return response()->json([
                'status' => 'skipped',
                'message' => 'Entire slot is already covered by existing idle records.',
            ]);
        }

        $created = [];
        foreach ($freeIntervals as [$freeStart, $freeEnd]) {
            // Skip slivers under 1 minute
            if ($freeStart->diffInSeconds($freeEnd) < 60) {
                continue;
            }

            $created[] = SessionTimeAdjustment::create([
                'session_id' => $sessionId,
                'start_time' => $freeStart,
                'end_time' => $freeEnd,
            ]);
        }

        if (empty($created)) {
            return response()->json([
                'status' => 'skipped',
                'message' => 'No meaningful free intervals remain after removing overlaps.',
            ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => count($created) . ' idle slot(s) created after splitting around existing records.',
            'data' => $created,
        ]);
    }

    /**
     * Given a range [rangeStart, rangeEnd] and a sorted collection of existing
     * slots, return the free sub-intervals with existing slots punched out.
     *
     * Example:
     *   range    : 6:05 → 8:30
     *   existing : [6:35 → 6:48]
     *   result   : [[6:05 → 6:35], [6:48 → 8:30]]
     */
    private function subtractSlots(
        Carbon $rangeStart,
        Carbon $rangeEnd,
        \Illuminate\Support\Collection $existingSlots
    ): array {
        $free = [];
        $cursor = $rangeStart->copy();

        foreach ($existingSlots as $slot) {
            $slotStart = Carbon::parse($slot->start_time)->max($rangeStart);
            $slotEnd = Carbon::parse($slot->end_time)->min($rangeEnd);

            // Free gap before this existing slot
            if ($cursor->lt($slotStart)) {
                $free[] = [$cursor->copy(), $slotStart->copy()];
            }

            // Advance cursor past this existing slot
            if ($slotEnd->gt($cursor)) {
                $cursor = $slotEnd->copy();
            }
        }

        // Remaining tail after the last existing slot
        if ($cursor->lt($rangeEnd)) {
            $free[] = [$cursor->copy(), $rangeEnd->copy()];
        }

        return $free;
    }

    // ──────────────────────────────────────────────────────────────
// Helper: given a range [rangeStart, rangeEnd] and a sorted list
// of existing slots, return the free sub-intervals.
//
// Example:
//   range        : 6:05 → 8:30
//   existing     : [6:35 → 6:48]
//   result       : [[6:05 → 6:35], [6:48 → 8:30]]
// ──────────────────────────────────────────────────────────────



    public function deleteIdleTime(Request $request)
    {

        $data = SessionTimeAdjustment::findOrFail($request->idle_time_id);

        $data->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Idle period deleted.'
        ]);

    }

}
