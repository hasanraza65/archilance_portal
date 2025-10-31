<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\LeaveRequest;
use Illuminate\Support\Facades\Auth;
use \Carbon\Carbon;

class LeaveRequestController extends Controller
{
    // List all leave requests of the logged-in employee
    public function index()
    {
        $userId = Auth::id();

        // Fetch leave requests for the logged-in employee
        $leaves = LeaveRequest::where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get();

        // General status counts
        $counts = [
            'total' => LeaveRequest::where('user_id', $userId)->count(),
            'approved' => LeaveRequest::where('user_id', $userId)->where('status', 'Approved')->count(),
            'rejected' => LeaveRequest::where('user_id', $userId)->where('status', 'Rejected')->count(),
            'pending' => LeaveRequest::where('user_id', $userId)->where('status', 'Pending')->count(),
        ];

        // ✅ Count leaves by type (for the current leave year, optional)
        $types = [
            'casual' => LeaveRequest::where('user_id', $userId)->where('leave_type', 'casual')->count(),
            'annual' => LeaveRequest::where('user_id', $userId)->where('leave_type', 'annual')->count(),
            'sick' => LeaveRequest::where('user_id', $userId)->where('leave_type', 'sick')->count(),
        ];

        return response()->json([
            'data' => $leaves,
            'counts' => $counts,
            'types' => $types,
        ]);
    }



    // Submit a new leave request
    public function store(Request $request)
    {
        $request->validate([
            'leave_type' => 'required|in:sick,casual,annual',
            'reason' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $userId = Auth::id();
        $leaveType = $request->leave_type;
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        // Calculate number of days applied for
        $daysRequested = $startDate->diffInDays($endDate) + 1;

        // Leave limits
        $limits = [
            'casual' => 10,
            'annual' => 10,
            'sick' => 8,
        ];

        // ✅ Check consecutive rule for casual leave
        if ($leaveType === 'casual' && $daysRequested > 2) {
            return response()->json([
                'message' => 'Casual leaves cannot be taken for more than 2 consecutive days.'
            ], 422);
        }

        // ✅ Determine the "leave year" cycle (1 July → 30 June)
        $year = $startDate->month >= 7 ? $startDate->year : $startDate->year - 1;
        $yearStart = Carbon::create($year, 7, 1)->startOfDay();
        $yearEnd = Carbon::create($year + 1, 6, 30)->endOfDay();

        // ✅ Count total leaves of this type within this leave year
        $usedLeaves = LeaveRequest::where('user_id', $userId)
            ->where('leave_type', $leaveType)
            ->where('status', '!=', 'Rejected')
            ->where(function ($q) use ($yearStart, $yearEnd) {
                $q->whereBetween('start_date', [$yearStart, $yearEnd])
                    ->orWhereBetween('end_date', [$yearStart, $yearEnd]);
            })
            ->get()
            ->sum(function ($leave) {
                return Carbon::parse($leave->start_date)
                    ->diffInDays(Carbon::parse($leave->end_date)) + 1;
            });

        // ✅ Check if limit exceeded
        if (($usedLeaves + $daysRequested) > $limits[$leaveType]) {
            return response()->json([
                'message' => "You have exceeded your {$leaveType} leave limit for this leave year (1 July to 30 June). 
            Used: {$usedLeaves}, Remaining: " . max(0, $limits[$leaveType] - $usedLeaves) . "."
            ], 422);
        }

        // ✅ Create the leave
        $leave = LeaveRequest::create([
            'user_id' => $userId,
            'leave_type' => $leaveType,
            'reason' => $request->reason,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => 'Pending',
        ]);

        return response()->json([
            'message' => 'Leave request submitted successfully.',
            'data' => $leave
        ]);
    }

    // Show a specific leave request
    public function show($id)
{
    $leave = LeaveRequest::with('user')->findOrFail($id);

    $userId = $leave->user_id;

    // Determine current leave cycle (1 July → 30 June)
    $today = now();
    if ($today->month >= 7) {
        // Current cycle: July 1 of this year → June 30 of next year
        $cycleStart = $today->copy()->startOfYear()->addMonths(6)->startOfMonth(); // 1 July
        $cycleEnd   = $today->copy()->addYear()->startOfYear()->addMonths(6)->subDay(); // 30 June
    } else {
        // Current cycle: July 1 of last year → June 30 of this year
        $cycleStart = $today->copy()->subYear()->startOfYear()->addMonths(6)->startOfMonth(); // 1 July last year
        $cycleEnd   = $today->copy()->startOfYear()->addMonths(6)->subDay(); // 30 June this year
    }

    // Count leaves per type for this user (excluding rejected, within cycle)
    $leaveCounts = LeaveRequest::where('user_id', $userId)
        ->where('status', '!=', 'Rejected')
        ->whereBetween('start_date', [$cycleStart, $cycleEnd]) // assuming you have start_date column
        ->selectRaw("leave_type, COUNT(*) as total")
        ->groupBy('leave_type')
        ->pluck('total', 'leave_type');

    $leaveSummary = [
        'casual' => $leaveCounts['casual'] ?? 0,
        'annual' => $leaveCounts['annual'] ?? 0,
        'sick'   => $leaveCounts['sick'] ?? 0,
    ];

    return response()->json([
        'data' => $leave,
        'leave_summary' => $leaveSummary,
        'cycle' => [
            'start' => $cycleStart->toDateString(),
            'end'   => $cycleEnd->toDateString(),
        ]
    ]);
}


    // Update a leave request (only if pending)
    public function update(Request $request, $id)
    {
        $leave = LeaveRequest::where('user_id', Auth::id())->findOrFail($id);

        if ($leave->status !== 'Pending') {
            return response()->json(['error' => 'Cannot update approved or rejected requests.'], 403);
        }

        $request->validate([
            'leave_type' => 'nullable',
            'reason' => 'nullable|string',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        $leave->update($request->only('leave_type', 'reason', 'start_date', 'end_date'));

        return response()->json(['message' => 'Leave request updated.', 'data' => $leave]);
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
}