<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use App\Services\LeavePolicy;
use App\Traits\CountsWeekdays;
use Illuminate\Http\Request;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use \Carbon\Carbon;

/**
 * An employee's own leave: balances, applying, editing, cancelling.
 *
 * Every entitlement and rule lives in App\Services\LeavePolicy — this class only
 * handles HTTP. See that class for the policy itself.
 *
 * ── BACKWARD COMPATIBILITY ───────────────────────────────────────────────────
 * The `types`, `counts`, `data` and `cycle` keys returned by index() keep their
 * exact pre-policy meaning, including the separate `additional` tally, so an
 * older web/desktop build talking to this controller behaves as it always has.
 * Everything new is namespaced under the additive `policy` key. `additional` is
 * its own leave type under the current policy too (BIM Team only, its own
 * 8-day balance) — see LeavePolicy::ADDITIONAL.
 */
class LeaveRequestController extends Controller
{
    use CountsWeekdays;

    /**
     * Pre-policy allow-list, kept ONLY so the legacy `types.additional` tally in
     * index() renders for the same people it always has, unaffected by whatever
     * LeavePolicy currently resolves users.employee_team to.
     */
    private const LEGACY_ADDITIONAL_USER_IDS = [109, 171, 22, 173, 50, 172, 147, 118, 35, 180, 114, 69, 182, 23, 26, 21, 128, 175, 139, 28, 58, 162, 166];

    /** Types accepted on the wire. */
    private const ACCEPTED_TYPES = 'sick,casual,annual,marriage,unpaid,additional';

    private function policy(): LeavePolicy
    {
        return LeavePolicy::for(Auth::user());
    }

    // List the logged-in employee's leave for their current cycle
    public function index()
    {
        $user   = Auth::user();
        $userId = $user->id;
        $policy = $this->policy();

        [$cycleStart, $cycleEnd] = $policy->cycleFor(Carbon::today());

        $leaveRequests = LeaveRequest::where('user_id', $userId)
            ->whereBetween('start_date', [$cycleStart, $cycleEnd])
            ->get();

        // Admin-added 'additional' rows (reason "added by/from admin") stay in
        // the database and still count in every tally below — the days genuinely
        // happened. The ONLY thing hidden is the row itself: `data` (the list
        // the employee browses) uses this filtered view, while every balance
        // number below is computed from the FULL $leaveRequests.
        $visibleLeaveRequests = $leaveRequests
            ->reject(fn ($l) => LeavePolicy::isAdminAddedAdditional($l->leave_type, $l->reason))
            ->values();

        // ── Legacy tally — unchanged semantics on purpose ────────────────────
        // Weekday-counted, `additional` kept separate from casual. Newer clients
        // should read `policy.entitlements` instead — same shape (additional is
        // still its own bucket there too), but calendar-day-counted Annual and
        // the current BIM-Team-only Additional eligibility are correct there.
        $typeCounts = ['sick' => 0, 'casual' => 0, 'annual' => 0];

        foreach ($leaveRequests as $req) {
            if ($req->status === 'Rejected') {
                continue;
            }

            $days = $this->weekdaysBetween($req->start_date, $req->end_date);
            $type = strtolower(trim($req->leave_type));

            switch ($type) {
                case 'sick':
                case 'medical leave':
                    $typeCounts['sick'] += $days;
                    break;
                case 'annual':
                    $typeCounts['annual'] += $days;
                    break;
                case 'additional':
                    break; // tallied separately below
                case 'marriage':
                case 'unpaid':
                    break; // new types are not part of the legacy block
                default:
                    $typeCounts['casual'] += $days;
                    break;
            }
        }

        if (in_array($userId, self::LEGACY_ADDITIONAL_USER_IDS)) {
            $typeCounts['additional'] = $leaveRequests
                ->filter(fn ($l) => $l->status !== 'Rejected'
                    && strtolower(trim($l->leave_type)) === 'additional')
                ->sum(fn ($l) => $this->weekdaysBetween($l->start_date, $l->end_date));
        }

        // All-time (not cycle-scoped, unlike everything above) — but still
        // excludes admin-added rows, same as the list and every balance number.
        // Computed in PHP against the exact same detector rather than a raw SQL
        // aggregate, so this can never drift from what's actually hidden.
        $allTimeVisible = LeaveRequest::where('user_id', $userId)
            ->get(['status', 'leave_type', 'reason'])
            ->reject(fn ($l) => LeavePolicy::isAdminAddedAdditional($l->leave_type, $l->reason));

        $counts = [
            'total'    => $allTimeVisible->count(),
            'approved' => $allTimeVisible->where('status', 'Approved')->count(),
            'rejected' => $allTimeVisible->where('status', 'Rejected')->count(),
            'pending'  => $allTimeVisible->where('status', 'Pending')->count(),
        ];

        return response()->json([
            'types'  => $typeCounts,
            'counts' => $counts,
            'data'   => $visibleLeaveRequests,
            'cycle'  => [
                'start' => $cycleStart->toDateString(),
                'end'   => $cycleEnd->toDateString(),
            ],
            // Additive: current policy, balances and rules. Older clients ignore it.
            'policy' => $policy->summary(),
        ]);
    }

    // Submit a new leave request
    public function store(Request $request)
    {
        $request->validate([
            'leave_type' => 'required|in:' . self::ACCEPTED_TYPES,
            'reason'     => 'nullable|string',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $policy    = $this->policy();
        $submitted = strtolower(trim($request->leave_type));
        $type      = LeavePolicy::normalizeType($submitted);
        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate   = Carbon::parse($request->end_date)->startOfDay();

        if ($error = $policy->validate($type, $startDate, $endDate)) {
            return response()->json(['message' => $error], 422);
        }

        $leave = LeaveRequest::create([
            'user_id' => Auth::id(),
            // Store what was submitted so older clients keep seeing their own
            // labels; all counting goes through LeavePolicy::normalizeType().
            'leave_type' => $submitted,
            'reason'     => $request->reason,
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'status'     => 'Pending',
        ]);

        $this->sendLeaveEmail($submitted, $startDate, $endDate);

        return response()->json([
            'message' => 'Leave request submitted successfully.',
            'data'    => $leave,
        ]);
    }

    // Show a specific leave request
    public function show($id)
    {
        $leave  = LeaveRequest::with('user')->findOrFail($id);
        $policy = LeavePolicy::for($leave->user);

        [$cycleStart, $cycleEnd] = $policy->cycleFor(Carbon::today());

        // No list is exposed here (only the aggregate below plus the single
        // requested $leave), so admin-added rows are counted, not filtered —
        // same rule as everywhere else: hidden from lists, never from balances.
        $leaveRequests = LeaveRequest::where('user_id', $leave->user_id)
            ->whereBetween('start_date', [$cycleStart, $cycleEnd])
            ->get();

        // Legacy shape: weekday-counted, unknown types folded into `other`.
        $leaveSummary = ['sick' => 0, 'casual' => 0, 'annual' => 0, 'other' => 0];

        foreach ($leaveRequests as $req) {
            $days = $this->weekdaysBetween($req->start_date, $req->end_date);

            switch (strtolower(trim($req->leave_type))) {
                case 'sick':
                case 'medical leave':
                    $leaveSummary['sick'] += $days;
                    break;
                case 'casual':
                    $leaveSummary['casual'] += $days;
                    break;
                case 'annual':
                    $leaveSummary['annual'] += $days;
                    break;
                default:
                    $leaveSummary['other'] += $days;
                    break;
            }
        }

        return response()->json([
            'data'          => $leave,
            'leave_summary' => $leaveSummary,
            'cycle'         => [
                'start' => $cycleStart->toDateString(),
                'end'   => $cycleEnd->toDateString(),
            ],
            'policy'        => $policy->summary(),
        ]);
    }

    // Update a leave request (resets it to Pending)
    public function update(Request $request, $id)
    {
        $leave = LeaveRequest::where('user_id', Auth::id())->findOrFail($id);

        $request->validate([
            'leave_type' => 'required|in:' . self::ACCEPTED_TYPES,
            'reason'     => 'nullable|string',
            'start_date' => 'required|date',
            'end_date'   => 'required|date|after_or_equal:start_date',
        ]);

        $policy    = $this->policy();
        $submitted = strtolower(trim($request->leave_type));
        $type      = LeavePolicy::normalizeType($submitted);
        $startDate = Carbon::parse($request->start_date)->startOfDay();
        $endDate   = Carbon::parse($request->end_date)->startOfDay();

        // Exclude the row being edited so it never counts against itself.
        if ($error = $policy->validate($type, $startDate, $endDate, $leave->id)) {
            return response()->json(['message' => $error], 422);
        }

        $leave->update([
            'leave_type' => $submitted,
            'reason'     => $request->reason,
            'start_date' => $startDate,
            'end_date'   => $endDate,
            'status'     => 'Pending',
        ]);

        $this->sendLeaveEmail($submitted, $startDate, $endDate, true);

        return response()->json([
            'message' => 'Leave request updated and resubmitted successfully.',
            'data'    => $leave,
        ]);
    }

    // Cancel a leave request (only if pending)
    public function destroy($id)
    {
        $leave = LeaveRequest::where('user_id', Auth::id())->findOrFail($id);

        if ($leave->status !== 'Pending') {
            return response()->json(['error' => 'Cannot cancel approved or rejected requests.'], 403);
        }

        $leave->delete();

        return response()->json(['message' => 'Leave request canceled.']);
    }

    private function sendLeaveEmail(string $leaveType, Carbon $startDate, Carbon $endDate, bool $isUpdate = false): void
    {
        $sender_name = Auth::user()->name;

        $fixedEmails = [
            'asad@archilance.net',
            'Faran@archilance.net',
            'info@archilance.net',
            'HR@archilance.net'
        ];

        $sender = Auth::user();
        $senderIsExecutive = (int) $sender->user_role === 7
            || strcasecmp((string) $sender->employee_type, 'Executive') === 0;

        // Admins always hear about every request. Executives hear about
        // everyone's request EXCEPT a fellow executive's own — when an
        // executive applies, it goes UP to admins only, not sideways to peers.
        $audience = User::where('user_role', 2)->pluck('email')->all();
        if (!$senderIsExecutive) {
            $execEmails = User::where('user_role', 7)
                ->orWhere('employee_type', 'Executive')
                ->pluck('email')
                ->all();
            $audience = array_merge($audience, $execEmails);
        }

        // ...plus ONLY the requester's direct manager (users.manager_id — the
        // reporting line, never internee_manager_id). Managers no longer
        // receive every employee's requests, just their own team's. For an
        // executive with no manager set, this leaves admins alone — as intended.
        if ($sender->manager_id) {
            $managerEmail = User::where('id', $sender->manager_id)->value('email');
            if ($managerEmail) {
                $audience[] = $managerEmail;
            }
        }

        // Case-insensitive dedupe: a manager-level requester's manager is often
        // an executive who is ALREADY in the audience — one email per person,
        // never two. Employees with no manager fall back to admins/execs alone.
        $allEmails = collect($fixedEmails)
            ->merge($audience)
            ->filter()
            ->unique(fn ($e) => strtolower(trim($e)))
            ->values()
            ->all();

        $subject = $isUpdate
            ? $sender_name . ' updated leave request - Archilance LLC'
            : $sender_name . ' request for leaves - Archilance LLC';

       \Mail::send(
        'mails.new-leave-request',
        compact('sender_name', 'leaveType', 'endDate', 'startDate'),
        function ($message) use ($sender_name, $allEmails, $subject) {
            $message->from("info@archilance.net", $sender_name)
                    ->to($allEmails)
                    ->subject($subject);
        });
    }
}
