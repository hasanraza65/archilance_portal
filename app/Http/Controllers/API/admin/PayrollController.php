<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipItem;
use App\Models\SalaryProfile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Monthly payroll: generate a run, adjust it, get it approved, then mark it paid.
 *
 * ── THE APPROVAL GATE ───────────────────────────────────────────────────────
 * The client's requirement is that the FINAL approval is his. That is enforced
 * by run status rather than by UI convention:
 *
 *   Draft / Pending Approval  → payslips editable, nothing can be marked paid
 *   Approved                  → payslips frozen, payments may be recorded
 *   Paid                      → set automatically once every payable slip settles
 *   Cancelled                 → terminal; generate a fresh run instead
 *
 * Every mutating action re-reads the run and asks isEditable()/isPayable(), so
 * the rule cannot be bypassed by calling the API directly.
 *
 * Payoneer is deliberately out of scope: `markPaid` records a reference the
 * finance team pastes in. When Payoneer arrives it fills the same two columns.
 */
class PayrollController extends Controller
{
    /* ------------------------------- runs ------------------------------- */

    public function index(Request $request)
    {
        $runs = PayrollRun::with(['creator:id,name', 'approver:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->orderByDesc('period_month')
            ->orderByDesc('id')
            ->limit(60)
            ->get();

        return response()->json(['data' => $runs]);
    }

    public function show($id)
    {
        $run = PayrollRun::with(['creator:id,name', 'approver:id,name'])->findOrFail($id);

        $payslips = Payslip::with('items')
            ->where('payroll_run_id', $run->id)
            ->join('users', 'users.id', '=', 'payslips.user_id')
            ->orderBy('users.name')
            ->select('payslips.*')
            ->get();

        return response()->json([
            'data'     => $run,
            'payslips' => $payslips,
            'editable' => $run->isEditable(),
            'payable'  => $run->isPayable(),
        ]);
    }

    /**
     * Builds a run for a month from every Active salary profile.
     *
     * Re-running for a month that already has a live run is refused rather than
     * silently duplicating — the caller must cancel the old one first. Only
     * Cancelled runs are ignored, which is why period_month isn't a unique index.
     */
    public function generate(Request $request)
    {
        $data = $request->validate([
            'month' => 'required|date',
            'notes' => 'nullable|string|max:2000',
        ]);

        $month = Carbon::parse($data['month'])->startOfMonth();

        $existing = PayrollRun::whereDate('period_month', $month)
            ->where('status', '!=', PayrollRun::CANCELLED)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Payroll for ' . $month->format('F Y') . ' already exists.',
                'data'    => $existing,
            ], 409);
        }

        $profiles = SalaryProfile::payable()->get();

        if ($profiles->isEmpty()) {
            return response()->json([
                'message' => 'No employees have an active salary set, so there is nothing to generate.',
            ], 422);
        }

        // Only real, current employees — a soft-deleted or non-employee account
        // with a stale salary profile must never be swept into payroll.
        $users = User::whereIn('id', $profiles->pluck('user_id'))
            ->where('user_role', 3)
            ->get()
            ->keyBy('id');

        $run = DB::transaction(function () use ($month, $data, $profiles, $users) {
            $run = PayrollRun::create([
                'period_month' => $month,
                'title'        => $month->format('F Y'),
                'status'       => PayrollRun::DRAFT,
                'notes'        => $data['notes'] ?? null,
                'created_by'   => Auth::id(),
            ]);

            $seq = 0;
            foreach ($profiles as $profile) {
                $user = $users->get($profile->user_id);
                if (!$user) {
                    continue;
                }

                $seq++;
                $basic = (float) $profile->monthly_salary;

                Payslip::create([
                    'payroll_run_id' => $run->id,
                    'user_id'        => $user->id,
                    'period_month'   => $month,
                    'payslip_no'     => sprintf('ALLC-%s-%04d', $month->format('Y-m'), $seq),

                    'basic_salary'   => $basic,
                    'gross_amount'   => $basic,
                    'net_amount'     => $basic,
                    'currency'       => $profile->currency ?: 'PKR',
                    'status'         => 'Pending',

                    'payment_method'    => $profile->payment_method,
                    'payment_reference' => null,

                    // Snapshot — see the payslips migration for why.
                    'employee_name'  => $user->name,
                    'employee_email' => $user->email,
                    'designation'    => $user->employee_type,
                    'department'     => $user->employee_team,
                    'joining_date'   => $user->joining_date,

                    'created_by'     => Auth::id(),
                ]);
            }

            return $run->recalculate();
        });

        return response()->json([
            'message' => 'Payroll generated for ' . $month->format('F Y') . '.',
            'data'    => $run->fresh(),
        ]);
    }

    /** Draft → Pending Approval. */
    public function submit($id)
    {
        $run = PayrollRun::findOrFail($id);

        if ($run->status !== PayrollRun::DRAFT) {
            return response()->json(['message' => 'Only a draft payroll can be sent for approval.'], 422);
        }

        $run->update(['status' => PayrollRun::PENDING]);

        return response()->json(['message' => 'Sent for approval.', 'data' => $run->fresh()]);
    }

    /** Pending Approval → Approved. After this, payslips are frozen. */
    public function approve($id)
    {
        $run = PayrollRun::findOrFail($id);

        if (!in_array($run->status, [PayrollRun::DRAFT, PayrollRun::PENDING], true)) {
            return response()->json(['message' => 'This payroll has already been approved or closed.'], 422);
        }

        $run->recalculate(false);
        $run->status      = PayrollRun::APPROVED;
        $run->approved_by = Auth::id();
        $run->approved_at = now();
        $run->save();

        return response()->json(['message' => 'Payroll approved.', 'data' => $run->fresh()]);
    }

    /** Sends an approved run back for edits. */
    public function reopen($id)
    {
        $run = PayrollRun::findOrFail($id);

        if ($run->payslips()->where('status', 'Paid')->exists()) {
            return response()->json([
                'message' => 'Some payslips in this payroll are already paid, so it can no longer be reopened.',
            ], 422);
        }

        $run->update([
            'status'      => PayrollRun::DRAFT,
            'approved_by' => null,
            'approved_at' => null,
        ]);

        return response()->json(['message' => 'Payroll reopened for editing.', 'data' => $run->fresh()]);
    }

    public function cancel($id)
    {
        $run = PayrollRun::findOrFail($id);

        if ($run->payslips()->where('status', 'Paid')->exists()) {
            return response()->json([
                'message' => 'Some payslips in this payroll are already paid, so it cannot be cancelled.',
            ], 422);
        }

        $run->update(['status' => PayrollRun::CANCELLED]);

        return response()->json(['message' => 'Payroll cancelled.', 'data' => $run->fresh()]);
    }

    /* ----------------------------- payslips ----------------------------- */

    public function showPayslip($payslipId)
    {
        $payslip = Payslip::with(['items', 'run', 'user:id,name,email,profile_pic'])->findOrFail($payslipId);

        return response()->json(['data' => $payslip]);
    }

    /** Notes / payment details / status while the run is still open. */
    public function updatePayslip(Request $request, $payslipId)
    {
        $payslip = Payslip::with('run')->findOrFail($payslipId);

        $data = $request->validate([
            'status'            => 'nullable|in:Pending,Approved,On Hold,Skipped',
            'notes'             => 'nullable|string|max:2000',
            'payment_method'    => 'nullable|string|max:120',
            'payment_reference' => 'nullable|string|max:190',
            'basic_salary'      => 'nullable|numeric|min:0|max:999999999',
        ]);

        if (!$payslip->run || !$payslip->run->isEditable()) {
            return response()->json(['message' => 'This payroll is approved or closed and can no longer be edited.'], 422);
        }

        if ($payslip->status === 'Paid') {
            return response()->json(['message' => 'A paid payslip cannot be edited.'], 422);
        }

        $payslip->fill(array_filter($data, fn ($v) => $v !== null))->save();
        $payslip->recalculate();
        $payslip->run->recalculate();

        return response()->json(['message' => 'Payslip updated.', 'data' => $payslip->fresh('items')]);
    }

    /** Adds a bonus / incentive / allowance / deduction line. */
    public function addItem(Request $request, $payslipId)
    {
        $payslip = Payslip::with('run')->findOrFail($payslipId);

        $data = $request->validate([
            'kind'     => 'required|in:earning,deduction',
            'category' => 'required|string|max:80',
            'label'    => 'nullable|string|max:190',
            'amount'   => 'required|numeric|min:0.01|max:999999999',
            'notes'    => 'nullable|string|max:1000',
        ]);

        if (!$payslip->run || !$payslip->run->isEditable() || $payslip->status === 'Paid') {
            return response()->json(['message' => 'This payslip can no longer be edited.'], 422);
        }

        $item = $payslip->items()->create($data + ['created_by' => Auth::id()]);

        $payslip->recalculate();
        $payslip->run->recalculate();

        return response()->json([
            'message' => 'Added.',
            'data'    => $item,
            'payslip' => $payslip->fresh('items'),
        ]);
    }

    public function deleteItem($payslipId, $itemId)
    {
        $payslip = Payslip::with('run')->findOrFail($payslipId);

        if (!$payslip->run || !$payslip->run->isEditable() || $payslip->status === 'Paid') {
            return response()->json(['message' => 'This payslip can no longer be edited.'], 422);
        }

        PayslipItem::where('payslip_id', $payslip->id)->where('id', $itemId)->delete();

        $payslip->recalculate();
        $payslip->run->recalculate();

        return response()->json(['message' => 'Removed.', 'payslip' => $payslip->fresh('items')]);
    }

    /**
     * Records that one person has actually been paid.
     *
     * Manual for now — the finance team makes the transfer and pastes the
     * reference. Guarded by the run being Approved, so nothing can be marked
     * paid before the approval step has happened.
     */
    public function markPaid(Request $request, $payslipId)
    {
        $payslip = Payslip::with('run')->findOrFail($payslipId);

        $data = $request->validate([
            'payment_reference' => 'nullable|string|max:190',
            'payment_method'    => 'nullable|string|max:120',
            'paid_at'           => 'nullable|date',
        ]);

        if (!$payslip->run || !$payslip->run->isPayable()) {
            return response()->json(['message' => 'This payroll must be approved before payments can be recorded.'], 422);
        }

        if ($payslip->status === 'Paid') {
            return response()->json(['message' => 'This payslip is already marked paid.'], 422);
        }

        $payslip->fill([
            'status'            => 'Paid',
            'paid_at'           => isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : now(),
            'payment_reference' => $data['payment_reference'] ?? $payslip->payment_reference,
            'payment_method'    => $data['payment_method'] ?? $payslip->payment_method,
            'paid_by'           => Auth::id(),
        ])->save();

        $payslip->run->recalculate();

        return response()->json([
            'message' => 'Marked as paid.',
            'data'    => $payslip->fresh(),
            'run'     => $payslip->run->fresh(),
        ]);
    }

    /** Undo a payment recorded by mistake. */
    public function unmarkPaid($payslipId)
    {
        $payslip = Payslip::with('run')->findOrFail($payslipId);

        if ($payslip->status !== 'Paid') {
            return response()->json(['message' => 'This payslip is not marked paid.'], 422);
        }

        $payslip->fill(['status' => 'Approved', 'paid_at' => null, 'paid_by' => null])->save();

        // A run auto-promoted to Paid must drop back, or it would claim to be
        // settled while one person is outstanding again.
        if ($payslip->run->status === PayrollRun::PAID) {
            $payslip->run->status = PayrollRun::APPROVED;
            $payslip->run->save();
        }
        $payslip->run->recalculate();

        return response()->json(['message' => 'Payment reversed.', 'data' => $payslip->fresh()]);
    }

    /** Marks every outstanding payslip in an approved run as paid, in one go. */
    public function markRunPaid(Request $request, $id)
    {
        $run = PayrollRun::findOrFail($id);

        if (!$run->isPayable()) {
            return response()->json(['message' => 'This payroll must be approved first.'], 422);
        }

        $reference = $request->input('payment_reference');
        $paidAt    = $request->filled('paid_at') ? Carbon::parse($request->input('paid_at')) : now();

        $count = 0;
        DB::transaction(function () use ($run, $reference, $paidAt, &$count) {
            $slips = $run->payslips()->whereNotIn('status', ['Paid', 'Skipped', 'On Hold'])->get();
            foreach ($slips as $slip) {
                $slip->fill([
                    'status'            => 'Paid',
                    'paid_at'           => $paidAt,
                    'payment_reference' => $reference ?: $slip->payment_reference,
                    'paid_by'           => Auth::id(),
                ])->save();
                $count++;
            }
            $run->recalculate();
        });

        return response()->json([
            'message' => $count . ' payslip' . ($count === 1 ? '' : 's') . ' marked as paid.',
            'data'    => $run->fresh(),
        ]);
    }

    /* ------------------------------ reports ----------------------------- */

    /**
     * Payment rows for a date range, flat enough to drive both the on-screen
     * report and the client-side CSV/PDF export without further shaping.
     */
    public function report(Request $request)
    {
        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))->startOfMonth()
            : Carbon::today()->startOfYear();
        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->endOfMonth()
            : Carbon::today()->endOfMonth();

        $rows = Payslip::query()
            ->whereBetween('period_month', [$from->toDateString(), $to->toDateString()])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->input('user_id')))
            ->orderByDesc('period_month')
            ->orderBy('employee_name')
            ->get();

        $byMonth = $rows->groupBy(fn ($r) => Carbon::parse($r->period_month)->format('Y-m'))
            ->map(fn ($group, $month) => [
                'month'      => $month,
                'employees'  => $group->count(),
                'gross'      => round((float) $group->sum('gross_amount'), 2),
                'deductions' => round((float) $group->sum('total_deductions'), 2),
                'net'        => round((float) $group->sum('net_amount'), 2),
                'paid'       => round((float) $group->where('status', 'Paid')->sum('net_amount'), 2),
                'paid_count' => $group->where('status', 'Paid')->count(),
            ])->values();

        return response()->json([
            'data'   => $rows,
            'months' => $byMonth,
            'range'  => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => [
                'employees'  => $rows->count(),
                'gross'      => round((float) $rows->sum('gross_amount'), 2),
                'deductions' => round((float) $rows->sum('total_deductions'), 2),
                'net'        => round((float) $rows->sum('net_amount'), 2),
                'paid'       => round((float) $rows->where('status', 'Paid')->sum('net_amount'), 2),
            ],
        ]);
    }
}
