<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use App\Traits\CountsWeekdays;
use Illuminate\Http\Request;
use App\Models\LeaveRequest;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;


class LeaveRequestController extends Controller
{
    use CountsWeekdays;

    private const ADDITIONAL_LEAVE_USER_IDS = [177, 109, 171, 22, 173, 50, 172, 147, 118, 35, 180, 114, 69, 182, 23, 26, 21, 128, 175, 139, 28, 58, 162];

    // List all leave requests (latest first)
    public function index(Request $request)
    {
        // Only the columns the list actually renders — the full user row was being
        // attached to every leave request.
        $query = LeaveRequest::with('user:id,name,email,profile_pic,employee_type')
            ->orderBy('created_at', 'desc');

        // ── Optional filters (all opt-in; absent = no filtering, so existing
        //    clients keep getting exactly what they get today) ──────────────
        if ($request->filled('status')) {
            $statuses = collect(explode(',', (string) $request->input('status')))
                ->map(fn($s) => trim($s))
                ->filter()
                ->values()
                ->all();
            if (!empty($statuses)) {
                $query->whereIn('status', $statuses);
            }
        }

        if ($request->filled('user_id')) {
            $userIds = collect(explode(',', (string) $request->input('user_id')))
                ->map(fn($id) => (int) trim($id))
                ->filter()
                ->values()
                ->all();
            if (!empty($userIds)) {
                $query->whereIn('user_id', $userIds);
            }
        }

        if ($request->filled('leave_type')) {
            $query->where('leave_type', $request->input('leave_type'));
        }

        // Overlap-style date filtering: any leave touching the window.
        if ($request->filled('from')) {
            $query->whereDate('end_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('start_date', '<=', $request->input('to'));
        }

        // ── Status counts in ONE grouped query instead of four COUNT(*) scans ──
        // Counts reflect the SAME filters as the list (minus pagination), so the
        // summary always matches what is being listed.
        $grouped = (clone $query)
            ->getQuery()
            ->select('status', \DB::raw('COUNT(*) as aggregate'))
            ->reorder()
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [
            'total'    => (int) $grouped->sum(),
            'approved' => (int) $grouped->get('Approved', 0),
            'rejected' => (int) $grouped->get('Rejected', 0),
            'pending'  => (int) $grouped->get('Pending', 0),
        ];

        // ── Opt-in pagination: only when the client asks (page / per_page). ──
        // Without those params the response shape is unchanged, so a backend-only
        // deploy cannot affect the current frontend.
        if ($request->filled('page') || $request->filled('per_page')) {
            $perPage = (int) $request->input('per_page', 25);
            $perPage = max(1, min($perPage, 200));

            $paginator = $query->paginate($perPage);

            return response()->json([
                'data'         => $paginator->items(),
                'counts'       => $counts,
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
                'has_more'     => $paginator->hasMorePages(),
            ]);
        }

        return response()->json([
            'data' => $query->get(),
            'counts' => $counts
        ]);
    }

    // View a specific leave request
    public function show($id)
    {
        $leave = LeaveRequest::with('user')->findOrFail($id);
        $user = $leave->user;
        $userId = $user->id;

        $today = now();

        // Determine leave cycle
        if ($user->joining_date) {
            $join = Carbon::parse($user->joining_date);

            // Calculate current cycle based on joining date anniversary
            $yearDiff = $today->year - $join->year;
            $cycleStart = $join->copy()->addYears($yearDiff);

            // If cycle start is in the future, subtract one year
            if ($cycleStart->gt($today)) {
                $cycleStart->subYear();
            }

            $cycleEnd = $cycleStart->copy()->addYear()->subDay();
        } else {
            // Default static 1 July -> 30 June
            if ($today->month >= 7) {
                $cycleStart = $today->copy()->startOfYear()->addMonths(6)->startOfMonth();
                $cycleEnd   = $today->copy()->addYear()->startOfYear()->addMonths(6)->subDay();
            } else {
                $cycleStart = $today->copy()->subYear()->startOfYear()->addMonths(6)->startOfMonth();
                $cycleEnd   = $today->copy()->startOfYear()->addMonths(6)->subDay();
            }
        }

        // Get relevant leave requests for this user (excluding rejected)
        $leaveRequests = LeaveRequest::with('user')
            ->where('user_id', $userId)
            ->where('status', '!=', 'Rejected')
            ->whereBetween('start_date', [$cycleStart, $cycleEnd])
            ->get();

        // Prepare summary
        $leaveSummary = [
            'sick'   => 0,
            'casual' => 0,
            'annual' => 0,
            'other'  => 0,
        ];

        foreach ($leaveRequests as $req) {
            $start = Carbon::parse($req->start_date);
            $end   = Carbon::parse($req->end_date);

            $days = $this->weekdaysBetween($start, $end);

            $type = strtolower(trim($req->leave_type));
            switch ($type) {
                case 'sick':
                case 'medical leave':
                    $mapped = 'sick';
                    break;
                case 'casual':
                    $mapped = 'casual';
                    break;
                case 'annual':
                    $mapped = 'annual';
                    break;
                default:
                    $mapped = 'other';
                    break;
            }

            $leaveSummary[$mapped] += $days;
        }

        // Add additional leave count for eligible users (all-time, no cycle)
        if (in_array($userId, self::ADDITIONAL_LEAVE_USER_IDS)) {
            $additionalUsed = LeaveRequest::where('user_id', $userId)
                ->where('leave_type', 'additional')
                ->where('status', '!=', 'Rejected')
                ->get()
                ->sum(function ($req) {
                    $start = Carbon::parse($req->start_date);
                    $end = Carbon::parse($req->end_date);
                    return $this->weekdaysBetween($start, $end);
                });
            $leaveSummary['additional'] = $additionalUsed;
        }

        return response()->json([
            'data' => $leave,
            'leave_summary' => $leaveSummary,
            'cycle' => [
                'start' => $cycleStart->toDateString(),
                'end'   => $cycleEnd->toDateString(),
            ]
        ]);
    }




    // Approve or reject a leave request
    public function update(Request $request, $id)
    {
        $leave = LeaveRequest::findOrFail($id);

        $request->validate([
            'status' => 'required|in:Approved,Rejected',
        ]);

        $leave->update([
            'status' => $request->status,
            'reviewed_at' => now(),
            'approved_by' => Auth::id(),
        ]);

        return response()->json(['message' => 'Leave request ' . strtolower($request->status) . '.', 'data' => $leave]);
    }

    // Optional: Delete leave request entirely
    public function destroy($id)
    {
        $leave = LeaveRequest::findOrFail($id);
        $leave->delete();

        return response()->json(['message' => 'Leave request deleted.']);
    }
}