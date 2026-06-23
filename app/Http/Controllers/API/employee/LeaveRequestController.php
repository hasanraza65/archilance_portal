<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use \Carbon\Carbon;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;

class LeaveRequestController extends Controller
{
    private const ADDITIONAL_LEAVE_LIMIT = 8;
    private const ADDITIONAL_LEAVE_USER_IDS = [177, 109, 171, 22, 173, 50, 172, 147, 118, 35, 180, 114, 69, 182, 23, 26, 21, 128, 175, 139, 28, 58];

    // List all leave requests of the logged-in employee
    public function index()
    {
        $user = Auth::user();
        $userId = $user->id;

        $today = now();

        // Determine leave cycle
        if ($user->joining_date) {
            $join = Carbon::parse($user->joining_date);
            $yearDiff = $today->year - $join->year;
            $cycleStart = $join->copy()->addYears($yearDiff);
            if ($cycleStart->gt($today)) {
                $cycleStart->subYear();
            }
            $cycleEnd = $cycleStart->copy()->addYear()->subDay();
        } else {
            if ($today->month >= 7) {
                $cycleStart = $today->copy()->startOfYear()->addMonths(6)->startOfMonth();
                $cycleEnd = $today->copy()->addYear()->startOfYear()->addMonths(6)->subDay();
            } else {
                $cycleStart = $today->copy()->subYear()->startOfYear()->addMonths(6)->startOfMonth();
                $cycleEnd = $today->copy()->startOfYear()->addMonths(6)->subDay();
            }
        }

        // Fetch all leave requests within this cycle
        $leaveRequests = LeaveRequest::where('user_id', $userId)
            ->whereBetween('start_date', [$cycleStart, $cycleEnd])
            ->get();

        // Type summary (only count approved/pending)
        $typeCounts = [
            'sick' => 0,
            'casual' => 0,
            'annual' => 0,
        ];

        foreach ($leaveRequests as $req) {
            if ($req->status === 'Rejected') continue;

            $start = Carbon::parse($req->start_date);
            $end = Carbon::parse($req->end_date);
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
                case 'annual':
                    $mapped = 'annual';
                    break;
                case 'casual':
                default:
                    $mapped = 'casual';
                    break;
            }

            $typeCounts[$mapped] += $days;
        }

        // Add additional leave count for eligible users (all-time, no cycle)
        if (in_array($userId, self::ADDITIONAL_LEAVE_USER_IDS)) {
            $additionalUsed = LeaveRequest::where('user_id', $userId)
                ->where('leave_type', 'additional')
                ->where('status', '!=', 'Rejected')
                ->get()
                ->sum(function ($leave) {
                    $start = Carbon::parse($leave->start_date);
                    $end = Carbon::parse($leave->end_date);
                    return collect(CarbonPeriod::create($start, $end))
                        ->filter(fn($date) => !$date->isWeekend())
                        ->count();
                });
            $typeCounts['additional'] = $additionalUsed;
        }

        // Status counts
        $counts = [
            'total' => LeaveRequest::where('user_id', $userId)->count(),
            'approved' => LeaveRequest::where('user_id', $userId)->where('status', 'Approved')->count(),
            'rejected' => LeaveRequest::where('user_id', $userId)->where('status', 'Rejected')->count(),
            'pending' => LeaveRequest::where('user_id', $userId)->where('status', 'Pending')->count(),
        ];

        return response()->json([
            'types' => $typeCounts,
            'counts' => $counts,
            'data' => $leaveRequests,
            'cycle' => [
                'start' => $cycleStart->toDateString(),
                'end' => $cycleEnd->toDateString(),
            ]
        ]);
    }

    // Submit a new leave request
    public function store(Request $request)
    {
        $user = Auth::user();
        $userId = $user->id;
        $isEligibleForAdditional = in_array($userId, self::ADDITIONAL_LEAVE_USER_IDS);

        $allowedTypes = $isEligibleForAdditional
            ? 'sick,casual,annual,additional'
            : 'sick,casual,annual';

        $request->validate([
            'leave_type' => "required|in:{$allowedTypes}",
            'reason' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $leaveType = $request->leave_type;
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        // Calculate number of weekdays (Mon-Fri) applied for
        $daysRequested = collect(CarbonPeriod::create($startDate, $endDate))
            ->filter(fn($date) => !$date->isWeekend())
            ->count();

        // Handle additional leave separately (all-time limit, no cycle)
        if ($leaveType === 'additional') {
            $usedAdditional = LeaveRequest::where('user_id', $userId)
                ->where('leave_type', 'additional')
                ->where('status', '!=', 'Rejected')
                ->get()
                ->sum(function ($leave) {
                    $start = Carbon::parse($leave->start_date);
                    $end = Carbon::parse($leave->end_date);
                    return collect(CarbonPeriod::create($start, $end))
                        ->filter(fn($date) => !$date->isWeekend())
                        ->count();
                });

            if (($usedAdditional + $daysRequested) > self::ADDITIONAL_LEAVE_LIMIT) {
                return response()->json([
                    'message' => "You have exceeded your additional leave limit. Used: {$usedAdditional}, Remaining: " . max(0, self::ADDITIONAL_LEAVE_LIMIT - $usedAdditional) . "."
                ], 422);
            }

            $leave = LeaveRequest::create([
                'user_id' => $userId,
                'leave_type' => $leaveType,
                'reason' => $request->reason,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => 'Pending',
            ]);

            $this->sendLeaveEmail($leaveType, $startDate, $endDate);

            return response()->json([
                'message' => 'Leave request submitted successfully.',
                'data' => $leave
            ]);
        }

        // Leave limits
        $limits = [
            'casual' => 10,
            'annual' => 10,
            'sick' => 8,
        ];

        // Check consecutive rule for casual leave (weekdays only)
        if ($leaveType === 'casual' && $daysRequested > 2) {
            return response()->json([
                'message' => 'Casual leaves cannot be taken for more than 2 consecutive weekdays.'
            ], 422);
        }

        // Determine leave cycle
        if ($user->joining_date) {
            $join = Carbon::parse($user->joining_date);
            $yearDiff = $startDate->year - $join->year;
            $yearStart = $join->copy()->addYears($yearDiff)->startOfDay();
            if ($yearStart->gt($startDate)) {
                $yearStart->subYear();
            }
            $yearEnd = $yearStart->copy()->addYear()->subDay()->endOfDay();
        } else {
            $year = $startDate->month >= 7 ? $startDate->year : $startDate->year - 1;
            $yearStart = Carbon::create($year, 7, 1)->startOfDay();
            $yearEnd = Carbon::create($year + 1, 6, 30)->endOfDay();
        }

        // Count total leave days (weekdays only) of this type within this cycle
        $usedLeaves = LeaveRequest::where('user_id', $userId)
            ->where('leave_type', $leaveType)
            ->where('status', '!=', 'Rejected')
            ->whereBetween('start_date', [$yearStart, $yearEnd])
            ->get()
            ->sum(function ($leave) {
                $start = Carbon::parse($leave->start_date);
                $end = Carbon::parse($leave->end_date);
                return collect(CarbonPeriod::create($start, $end))
                    ->filter(fn($date) => !$date->isWeekend())
                    ->count();
            });

        // Check if limit exceeded
        if (($usedLeaves + $daysRequested) > $limits[$leaveType]) {
            return response()->json([
                'message' => "You have exceeded your {$leaveType} leave limit for this leave year. Used: {$usedLeaves}, Remaining: " . max(0, $limits[$leaveType] - $usedLeaves) . "."
            ], 422);
        }

        // Create the leave
        $leave = LeaveRequest::create([
            'user_id' => $userId,
            'leave_type' => $leaveType,
            'reason' => $request->reason,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => 'Pending',
        ]);

        $this->sendLeaveEmail($leaveType, $startDate, $endDate);

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

        // Determine current leave cycle (1 July -> 30 June)
        $today = now();
        if ($today->month >= 7) {
            $cycleStart = $today->copy()->startOfYear()->addMonths(6)->startOfMonth();
            $cycleEnd = $today->copy()->addYear()->startOfYear()->addMonths(6)->subDay();
        } else {
            $cycleStart = $today->copy()->subYear()->startOfYear()->addMonths(6)->startOfMonth();
            $cycleEnd = $today->copy()->startOfYear()->addMonths(6)->subDay();
        }

        // Get relevant leave requests for this user
        $leaveRequests = LeaveRequest::where('user_id', $userId)
            ->whereBetween('start_date', [$cycleStart, $cycleEnd])
            ->get();

        // Prepare summary array
        $leaveSummary = [
            'sick' => 0,
            'casual' => 0,
            'annual' => 0,
            'other' => 0,
        ];

        foreach ($leaveRequests as $req) {
            $start = Carbon::parse($req->start_date);
            $end = Carbon::parse($req->end_date);
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
            'data' => $leave,
            'leave_summary' => $leaveSummary,
            'cycle' => [
                'start' => $cycleStart->toDateString(),
                'end' => $cycleEnd->toDateString(),
            ]
        ]);
    }

    // Update a leave request (only if pending)
    public function update(Request $request, $id)
    {
        $leave = LeaveRequest::where('user_id', Auth::id())->findOrFail($id);

        $user = Auth::user();
        $userId = $user->id;
        $isEligibleForAdditional = in_array($userId, self::ADDITIONAL_LEAVE_USER_IDS);

        $allowedTypes = $isEligibleForAdditional
            ? 'sick,casual,annual,additional'
            : 'sick,casual,annual';

        $request->validate([
            'leave_type' => "required|in:{$allowedTypes}",
            'reason' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $leaveType = $request->leave_type;
        $startDate = Carbon::parse($request->start_date);
        $endDate = Carbon::parse($request->end_date);

        // Calculate requested weekdays
        $daysRequested = collect(CarbonPeriod::create($startDate, $endDate))
            ->filter(fn($date) => !$date->isWeekend())
            ->count();

        // Handle additional leave separately (all-time limit, no cycle)
        if ($leaveType === 'additional') {
            $usedAdditional = LeaveRequest::where('user_id', $userId)
                ->where('leave_type', 'additional')
                ->where('status', '!=', 'Rejected')
                ->where('id', '!=', $leave->id)
                ->get()
                ->sum(function ($l) {
                    $start = Carbon::parse($l->start_date);
                    $end = Carbon::parse($l->end_date);
                    return collect(CarbonPeriod::create($start, $end))
                        ->filter(fn($date) => !$date->isWeekend())
                        ->count();
                });

            if (($usedAdditional + $daysRequested) > self::ADDITIONAL_LEAVE_LIMIT) {
                return response()->json([
                    'message' => "You have exceeded your additional leave limit. Used: {$usedAdditional}, Remaining: " . max(0, self::ADDITIONAL_LEAVE_LIMIT - $usedAdditional) . "."
                ], 422);
            }

            $leave->update([
                'leave_type' => $leaveType,
                'reason' => $request->reason,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => 'Pending',
            ]);

            $this->sendLeaveEmail($leaveType, $startDate, $endDate, true);

            return response()->json([
                'message' => 'Leave request updated and resubmitted successfully.',
                'data' => $leave
            ]);
        }

        $limits = [
            'casual' => 10,
            'annual' => 10,
            'sick' => 8,
        ];

        // Casual consecutive rule
        if ($leaveType === 'casual' && $daysRequested > 2) {
            return response()->json([
                'message' => 'Casual leaves cannot be taken for more than 2 consecutive weekdays.'
            ], 422);
        }

        // Determine leave cycle
        if ($user->joining_date) {
            $join = Carbon::parse($user->joining_date);
            $yearDiff = $startDate->year - $join->year;
            $yearStart = $join->copy()->addYears($yearDiff)->startOfDay();
            if ($yearStart->gt($startDate)) {
                $yearStart->subYear();
            }
            $yearEnd = $yearStart->copy()->addYear()->subDay()->endOfDay();
        } else {
            $year = $startDate->month >= 7 ? $startDate->year : $startDate->year - 1;
            $yearStart = Carbon::create($year, 7, 1)->startOfDay();
            $yearEnd = Carbon::create($year + 1, 6, 30)->endOfDay();
        }

        // Recalculate used leaves EXCLUDING this leave
        $usedLeaves = LeaveRequest::where('user_id', $userId)
            ->where('leave_type', $leaveType)
            ->where('status', '!=', 'Rejected')
            ->where('id', '!=', $leave->id)
            ->whereBetween('start_date', [$yearStart, $yearEnd])
            ->get()
            ->sum(function ($l) {
                $start = Carbon::parse($l->start_date);
                $end = Carbon::parse($l->end_date);
                return collect(CarbonPeriod::create($start, $end))
                    ->filter(fn($date) => !$date->isWeekend())
                    ->count();
            });

        if (($usedLeaves + $daysRequested) > $limits[$leaveType]) {
            return response()->json([
                'message' => "You have exceeded your {$leaveType} leave limit for this leave year. Used: {$usedLeaves}, Remaining: " . max(0, $limits[$leaveType] - $usedLeaves) . "."
            ], 422);
        }

        // Update and force back to Pending
        $leave->update([
            'leave_type' => $leaveType,
            'reason' => $request->reason,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'status' => 'Pending',
        ]);

        $this->sendLeaveEmail($leaveType, $startDate, $endDate, true);

        return response()->json([
            'message' => 'Leave request updated and resubmitted successfully.',
            'data' => $leave
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

        $all_managers = User::where('employee_type', 'Manager')
            ->orWhere('employee_type', 'Executive')
            ->pluck('email')
            ->toArray();

        $allEmails = array_unique(array_merge($fixedEmails, $all_managers));

        $subject = $isUpdate
            ? $sender_name . ' updated leave request - Archilance LLC'
            : $sender_name . ' request for leaves - Archilance LLC';

        \Mail::send('mails.new-leave-request', compact('sender_name', 'leaveType', 'endDate', 'startDate'), function ($message) use ($sender_name, $allEmails, $subject) {
            $message->from("info@archilance.net", $sender_name)
                ->subject($subject);
        });
    }
}