<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\LeaveRequest;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;


class LeaveRequestController extends Controller
{
    private const ADDITIONAL_LEAVE_USER_IDS = [177, 109, 171, 22, 173, 50, 172, 147, 118, 35, 180, 114, 69, 182, 23, 26, 21, 128, 175, 139, 28, 58];

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

        // Add additional leave count for eligible users (all-time, no cycle)
        if (in_array($userId, self::ADDITIONAL_LEAVE_USER_IDS)) {
            $additionalUsed = LeaveRequest::where('user_id', $userId)
                ->where('leave_type', 'additional')
                ->where('status', '!=', 'Rejected')
                ->get()
                ->sum(function ($req) {
                    $start = Carbon::parse($req->start_date);
                    $end = Carbon::parse($req->end_date);
                    return collect(CarbonPeriod::create($start, $end))
                        ->filter(fn($date) => !$date->isWeekend())
                        ->count();
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