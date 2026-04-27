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
        // Default static 1 July → 30 June
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

        $days = 0;
        while ($start->lte($end)) {
            if (!in_array($start->dayOfWeek, [CarbonInterface::SATURDAY, CarbonInterface::SUNDAY])) {
                $days++;
            }
            $start->addDay();
        }

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

    return response()->json([
        'data' => $leave,           // keep the same leave object with user
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
