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
    // List all leave requests of the logged-in employee
  public function index()
{
    $userId = Auth::id();

    // Determine current leave cycle (1 July → 30 June)
    $today = now();
    if ($today->month >= 7) {
        $cycleStart = $today->copy()->startOfYear()->addMonths(6)->startOfMonth();
        $cycleEnd   = $today->copy()->addYear()->startOfYear()->addMonths(6)->subDay();
    } else {
        $cycleStart = $today->copy()->subYear()->startOfYear()->addMonths(6)->startOfMonth();
        $cycleEnd   = $today->copy()->startOfYear()->addMonths(6)->subDay();
    }

    // All valid leave requests
    $leaveRequests = LeaveRequest::where('user_id', $userId)
        //->where('status', '!=', 'Rejected')
        ->whereBetween('start_date', [$cycleStart, $cycleEnd])
        ->get();

    // Type summary (count by days just like admin)
    $typeCounts = [
        'sick'   => 0,
        'casual' => 0,
        'annual' => 0,
    ];

    foreach ($leaveRequests as $req) {

        $start = Carbon::parse($req->start_date);
        $end   = Carbon::parse($req->end_date);

        $days = 0;

        // Count only working days (Mon–Fri)
        while ($start->lte($end)) {
            if (!in_array($start->dayOfWeek, [CarbonInterface::SATURDAY, CarbonInterface::SUNDAY])) {
                $days++;
            }
            $start->addDay();
        }

        // Normalize leave type
        $type = strtolower(trim($req->leave_type));

        // Mapping same as admin
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

    // Status counts (still fine)
    $counts = [
        'total'    => LeaveRequest::where('user_id', $userId)->count(),
        'approved' => LeaveRequest::where('user_id', $userId)->where('status', 'Approved')->count(),
        'rejected' => LeaveRequest::where('user_id', $userId)->where('status', 'Rejected')->count(),
        'pending'  => LeaveRequest::where('user_id', $userId)->where('status', 'Pending')->count(),
    ];

    return response()->json([
        'types'  => $typeCounts,   // working-day totals
        'counts' => $counts,       // request-level stats
        'data'   => $leaveRequests,
        'cycle'  => [
            'start' => $cycleStart->toDateString(),
            'end'   => $cycleEnd->toDateString(),
        ]
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

    // Calculate number of weekdays (Mon-Fri) applied for
    $daysRequested = collect(CarbonPeriod::create($startDate, $endDate))
        ->filter(fn($date) => !$date->isWeekend())
        ->count();

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

    // Determine the "leave year" cycle (1 July → 30 June)
    $year = $startDate->month >= 7 ? $startDate->year : $startDate->year - 1;
    $yearStart = Carbon::create($year, 7, 1)->startOfDay();
    $yearEnd = Carbon::create($year + 1, 6, 30)->endOfDay();

    // Count total leave days (weekdays only) of this type within this leave year
    $usedLeaves = LeaveRequest::where('user_id', $userId)
        ->where('leave_type', $leaveType)
        ->where('status', '!=', 'Rejected')
        ->where(function ($q) use ($yearStart, $yearEnd) {
            $q->whereBetween('start_date', [$yearStart, $yearEnd])
                ->orWhereBetween('end_date', [$yearStart, $yearEnd]);
        })
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
            'message' => "You have exceeded your {$leaveType} leave limit for this leave year (1 July to 30 June). 
            Used: {$usedLeaves}, Remaining: " . max(0, $limits[$leaveType] - $usedLeaves) . "."
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

    $sender_name = Auth::user()->name;

    // Fixed emails
    $fixedEmails = [
        'Faran@archilance.net',
        'info@archilance.net',
        'HR@archilance.net'
    ];

    // Get all managers and executives
    $all_managers = User::where('employee_type', 'Manager')
        ->orWhere('employee_type', 'Executive')
        ->pluck('email')
        ->toArray();

    // Merge fixed emails with managers
    $allEmails = array_unique(array_merge($fixedEmails, $all_managers));

    // Send email
    \Mail::send('mails.new-leave-request', compact('sender_name','leaveType','endDate','startDate'), function ($message) use ($sender_name, $allEmails) {
        $message->from("info@archilance.net", $sender_name)
                ->to($allEmails)
                ->subject($sender_name.' request for leaves - Archilance LLC');
    });

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
            $cycleStart = $today->copy()->startOfYear()->addMonths(6)->startOfMonth(); // July 1
            $cycleEnd = $today->copy()->addYear()->startOfYear()->addMonths(6)->subDay(); // June 30 next year
        } else {
            $cycleStart = $today->copy()->subYear()->startOfYear()->addMonths(6)->startOfMonth(); // July 1 last year
            $cycleEnd = $today->copy()->startOfYear()->addMonths(6)->subDay(); // June 30 this year
        }

        // Get relevant leave requests for this user
        $leaveRequests = LeaveRequest::where('user_id', $userId)
            //->where('status', '!=', 'Rejected')
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
                if (
                    !in_array($start->dayOfWeek, [
                        CarbonInterface::SATURDAY,
                        CarbonInterface::SUNDAY
                    ])
                ) {
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
                'end' => $cycleEnd->toDateString(),
            ]
        ]);
    }



    // Update a leave request (only if pending)
   public function update(Request $request, $id)
{
    $leave = LeaveRequest::where('user_id', Auth::id())->findOrFail($id);

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

    // Calculate requested weekdays
    $daysRequested = collect(CarbonPeriod::create($startDate, $endDate))
        ->filter(fn($date) => !$date->isWeekend())
        ->count();

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

    // Leave year cycle (1 July → 30 June)
    $year = $startDate->month >= 7 ? $startDate->year : $startDate->year - 1;
    $yearStart = Carbon::create($year, 7, 1)->startOfDay();
    $yearEnd = Carbon::create($year + 1, 6, 30)->endOfDay();

    // Recalculate used leaves EXCLUDING this leave
    $usedLeaves = LeaveRequest::where('user_id', $userId)
        ->where('leave_type', $leaveType)
        ->where('status', '!=', 'Rejected')
        ->where('id', '!=', $leave->id)
        ->where(function ($q) use ($yearStart, $yearEnd) {
            $q->whereBetween('start_date', [$yearStart, $yearEnd])
              ->orWhereBetween('end_date', [$yearStart, $yearEnd]);
        })
        ->get()
        ->sum(function ($leave) {
            $start = Carbon::parse($leave->start_date);
            $end = Carbon::parse($leave->end_date);

            return collect(CarbonPeriod::create($start, $end))
                ->filter(fn($date) => !$date->isWeekend())
                ->count();
        });

    if (($usedLeaves + $daysRequested) > $limits[$leaveType]) {
        return response()->json([
            'message' => "You have exceeded your {$leaveType} leave limit for this leave year (1 July to 30 June). 
            Used: {$usedLeaves}, Remaining: " . max(0, $limits[$leaveType] - $usedLeaves) . "."
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

    $sender_name = Auth::user()->name;

    $fixedEmails = [
        'Faran@archilance.net',
        'info@archilance.net',
        'HR@archilance.net'
    ];

    $all_managers = User::where('employee_type', 'Manager')
        ->orWhere('employee_type', 'Executive')
        ->pluck('email')
        ->toArray();

    $allEmails = array_unique(array_merge($fixedEmails, $all_managers));

    \Mail::send('mails.new-leave-request',
        compact('sender_name','leaveType','endDate','startDate'),
        function ($message) use ($sender_name, $allEmails) {
            $message->from("info@archilance.net", $sender_name)
                    ->to($allEmails)
                    ->subject($sender_name.' updated leave request - Archilance LLC');
        }
    );

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
}