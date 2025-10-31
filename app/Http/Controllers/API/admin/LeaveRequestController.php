<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\LeaveRequest;
use Illuminate\Support\Facades\Auth;

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
