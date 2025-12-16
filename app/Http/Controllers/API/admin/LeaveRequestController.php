<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\LeaveRequest;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Carbon\CarbonInterface;


class LeaveRequestController extends Controller
{
    // List all leave requests (latest first)
    public function index()
    {
        // Fetch all leave requests with related user info
        $leaves = LeaveRequest::with('user')
            ->orderBy('created_at', 'desc')
            ->get();

        // Count totals by status
        $counts = [
            'total' => LeaveRequest::count(),
            'approved' => LeaveRequest::where('status', 'Approved')->count(),
            'rejected' => LeaveRequest::where('status', 'Rejected')->count(),
            'pending' => LeaveRequest::where('status', 'Pending')->count(),
        ];

        return response()->json([
            'data' => $leaves,
            'counts' => $counts
        ]);
    }

    // View a specific leave request
    public function show($id)
    {
        $leave = LeaveRequest::with('user')->findOrFail($id);
        $userId = $leave->user_id;

        // Determine current leave cycle (1 July → 30 June)
        $today = now();
        if ($today->month >= 7) {
            $cycleStart = $today->copy()->startOfYear()->addMonths(6)->startOfMonth(); // July 1
            $cycleEnd   = $today->copy()->addYear()->startOfYear()->addMonths(6)->subDay(); // June 30 next year
        } else {
            $cycleStart = $today->copy()->subYear()->startOfYear()->addMonths(6)->startOfMonth(); // July 1 last year
            $cycleEnd   = $today->copy()->startOfYear()->addMonths(6)->subDay(); // June 30 this year
        }

        // Get relevant leave requests for this user
        $leaveRequests = LeaveRequest::where('user_id', $userId)
            ->where('status', '!=', 'Rejected')
            ->whereBetween('start_date', [$cycleStart, $cycleEnd])
            ->get();

        // Prepare summary array
      $leaveSummary = [
    'sick'   => 0,
    'casual' => 0,
    'annual' => 0,
    'other'  => 0,
];

foreach ($leaveRequests as $req) {

    $start = Carbon::parse($req->start_date);
    $end   = Carbon::parse($req->end_date);

    $days = 0;

    while ($start->lte($end)) {
        if (!in_array($start->dayOfWeek, [
            CarbonInterface::SATURDAY,
            CarbonInterface::SUNDAY
        ])) {
            $days++;
        }
        $start->addDay();
    }

    // Normalize the type (case-insensitive)
    $type = strtolower(trim($req->leave_type));

    // Map types to your fixed labels
    switch ($type) {
        case 'sick':
        case 'medical leave':     // ← added
            $mapped = 'sick';
            break;

        case 'casual':
            $mapped = 'casual';
            break;

        case 'annual':
            $mapped = 'annual';
            break;

        default:
            $mapped = 'casual'; // Any other type becomes casual
            break;
    }

    // Add the days
    $leaveSummary[$mapped] += $days;
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
            'reviewed_at' => now(), // optional: track when reviewed
            'approved_by' => Auth::id(), // optional: track who reviewed
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
