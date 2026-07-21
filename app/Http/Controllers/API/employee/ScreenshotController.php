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
use App\Traits\ResolvesClientTime;


class ScreenshotController extends Controller
{
    use ResolvesClientTime;

    public function store(Request $request)
    {
        $userId = Auth::id();
        /*
        if($userId == 159){
            \Log::info('window title '.$request->window_title);
        } */


        //\Log::info('screenshot time '.Carbon::now());

        // Attach the screenshot to the EXACT work session the desktop app is tracking.
        //
        // Previously this endpoint IGNORED the work_session_id the app sends: it picked the
        // user's "latest open" session and, if none was open, CLOSED all open sessions and
        // CREATED a brand-new one. That mis-attributed screenshots to the wrong session and
        // spawned duplicate/overlapping sessions. It now ONLY attaches; it never creates,
        // closes, or reopens sessions (start/stop are the single source of truth for that).
        $currentSession = null;

        if ($request->filled('work_session_id')) {
            $currentSession = WorkSession::where('user_id', $userId)
                ->where('id', $request->work_session_id)
                ->first();
        }

        // Backward-compatible fallback for older clients that don't send work_session_id:
        // use the latest still-open session. We never create/close sessions here.
        if (!$currentSession) {
            $currentSession = WorkSession::where('user_id', $userId)
                ->whereNull('end_time')
                ->latest('start_time')
                ->first();
        }

        if (!$currentSession) {
            return response()->json([
                'message' => 'No matching work session found for this screenshot.'
            ], 422);
        }

        // When was this screenshot actually captured? Prefer the client's UTC capture time
        // (correct across timezones AND when an offline screenshot is flushed later); fall
        // back to server now() for older clients.
        $hasCaptureUtc = $this->hasClientUtc($request->input('captured_utc'));
        $capturedAt = $this->resolveClientUtc($request->input('captured_utc'), Carbon::now());

        // If this screenshot belongs to a session that was already closed (e.g. the
        // CheckHeartBeat safety-net closed it while the machine was briefly offline) but the
        // capture happened AFTER that end, the user was clearly still working — extend the
        // session end forward to cover it. We NEVER re-open the session.
        //
        // IMPORTANT: only do this when we have the REAL capture instant (captured_utc). For an
        // older client we fall back to now() (= upload time); a late-flushed pre-stop screenshot
        // would then wrongly push a user-stopped session's end past the real stop. So skip the
        // extension entirely unless captured_utc was provided (safe: same behaviour as before).
        if ($hasCaptureUtc && !is_null($currentSession->end_time)) {
            try {
                $sessionEnd = Carbon::parse(($currentSession->end_date ?? $currentSession->start_date) . ' ' . $currentSession->end_time);
                if ($capturedAt->greaterThan($sessionEnd)) {
                    $currentSession->end_time = $capturedAt;
                    $currentSession->end_date = $capturedAt->toDateString();
                    $currentSession->save();
                }
            } catch (\Throwable $e) {
                // leave the session end untouched on any parse issue
            }
        }

        // NOTE: We intentionally NO LONGER close open idle periods here.
        //
        // Idle lifecycle is now managed authoritatively by update-idle-time with an explicit
        // start/end intent. Closing an idle from a screenshot used the screenshot's captured_utc,
        // which for an OFFLINE-flushed shot is its ORIGINAL (active-era) capture instant — often
        // EARLIER than the currently-open idle's start. That produced an inverted (end <= start)
        // adjustment that the worked-time query drops entirely, silently erasing a real idle
        // period and billing it as worked time. The client's own idle "end" toggle closes idles
        // at the correct instant, so this out-of-band close is both redundant and harmful.

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
            // Real capture time (UTC-derived) so offline-flushed screenshots keep their true
            // timestamp instead of clustering at the reconnect/upload moment.
            $screenshot->created_at = $capturedAt;
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
            // UTC event time(s) — preferred (timezone- & offline-correct)
            'event_utc' => 'sometimes|string',
            'start_utc' => 'sometimes|string',
            'end_utc' => 'sometimes|string',
            // Explicit toggle intent from newer clients: "start" (went idle) / "end" (became active)
            'idle_action' => 'sometimes|in:start,end',
        ]);

        $now = Carbon::now();
        // The instant this toggle actually happened (client UTC preferred; falls back to now()).
        $eventTime = $this->resolveClientUtc($request->input('event_utc'), $now);
        $sessionId = $request->session_id;

        // ──────────────────────────────────────────────
        // CASE A: No explicit period supplied → real-time
        //         toggle (open / close) behaviour
        // ──────────────────────────────────────────────
        if (!$request->has('start_date') && !$request->filled('start_utc')) {

            // Explicit toggle intent from newer clients: "start" (user went idle) or "end"
            // (user became active). Older clients send neither — treated as a legacy toggle.
            $idleAction = $request->input('idle_action');

            $openAdjustment = SessionTimeAdjustment::where('session_id', $sessionId)
                ->whereNull('end_time')
                ->first();

            if ($openAdjustment) {
                if ($idleAction === 'start') {
                    // A "start" while one is already open is an anomaly (a duplicate/retry, or a
                    // previous "end" that was permanently lost leaving a STALE open idle). Close
                    // the existing one at this instant and open a fresh one, so a lost "end" can
                    // never let a single idle run on and swallow later active time. A true
                    // duplicate is harmless — the two adjacent idles are merged by the
                    // worked-time query.
                    $openAdjustment->end_time = $eventTime;
                    $openAdjustment->save();

                    $newAdjustment = SessionTimeAdjustment::create([
                        'session_id' => $sessionId,
                        'start_time' => $eventTime,
                        'end_time' => null,
                    ]);

                    return response()->json([
                        'status' => 'success',
                        'message' => 'Idle period re-opened.',
                        'data' => $newAdjustment,
                    ]);
                }

                // "end" intent (or a legacy toggle) -> close it.
                $openAdjustment->end_time = $eventTime;
                $openAdjustment->save();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Idle period closed.',
                    'data' => $openAdjustment,
                ]);
            }

            // Nothing is open. An "end" intent (became active) with no open idle MUST be a
            // no-op — never fabricate an idle period from a "close". This is what keeps
            // client/server toggle parity from drifting (e.g. after a screenshot or a sleep
            // event closed the idle out of band). Without this guard, a legitimate stretch of
            // ACTIVE work would be recorded as idle and subtracted from worked time.
            if ($idleAction === 'end') {
                return response()->json([
                    'status' => 'skipped',
                    'message' => 'No open idle period to close.',
                ]);
            }

            // "start" intent (or a legacy toggle) with nothing open -> open a new idle.
            // The client only calls this on a GENUINE idle (6+ minutes of no input), so record
            // it accurately — no more of the old 20-min / 60-70-min "throttle" that billed real
            // breaks as worked time and suppressed the first idle after a sleep gap.
            $newAdjustment = SessionTimeAdjustment::create([
                'session_id' => $sessionId,
                'start_time' => $eventTime,
                'end_time' => null,
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Idle period started.',
                'data' => $newAdjustment,
            ]);
        }

        // ──────────────────────────────────────────────
        // CASE B: Explicit period supplied → offline / catch-up / SLEEP-GAP slot with
        //         smart splitting around existing records. Used e.g. when the machine slept
        //         and we record [suspend, resume] as one idle span. Prefer UTC bounds.
        // ──────────────────────────────────────────────
        if ($request->filled('start_utc') && $request->filled('end_utc')) {
            $incomingStart = $this->resolveClientUtc($request->input('start_utc'), $now);
            $incomingEnd = $this->resolveClientUtc($request->input('end_utc'), $now);
        } else {
            $incomingStart = Carbon::parse($request->start_date . ' ' . $request->start_time);
            $incomingEnd = Carbon::parse($request->end_date . ' ' . $request->end_time);
        }

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
