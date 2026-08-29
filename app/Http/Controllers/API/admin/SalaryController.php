<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use App\Models\Payslip;
use App\Models\SalaryProfile;
use App\Models\SalaryRevision;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Salaries: who earns what, the history of how it changed, and each person's
 * payment record.
 *
 * ── SCOPE ───────────────────────────────────────────────────────────────────
 * The roster here is exactly the employee roster (users.user_role = 3, not
 * soft-deleted) — the same set /employee-user returns. Customers, customer team
 * members and admins can never appear, because they are never selected.
 *
 * ── EVERYTHING HERE IS NEW ──────────────────────────────────────────────────
 * No existing table, model, controller or route is touched by this module, so a
 * backend-only deploy cannot change the behaviour of any currently-live client.
 */
class SalaryController extends Controller
{
    /** Employee roster + salary + payment summary, in a fixed number of queries. */
    public function index(Request $request)
    {
        $monthStart = $this->monthStart($request->input('month'));

        $users = User::where('user_role', 3)
            ->when($request->filled('search'), function ($q) use ($request) {
                $like = '%' . trim((string) $request->input('search')) . '%';
                $q->where(function ($w) use ($like) {
                    $w->where('name', 'like', $like)->orWhere('email', 'like', $like);
                });
            })
            ->when($request->filled('employee_team'), fn ($q) => $q->where('employee_team', $request->input('employee_team')))
            ->when($request->filled('employee_type'), fn ($q) => $q->where('employee_type', $request->input('employee_type')))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'profile_pic', 'employee_type', 'employee_team', 'joining_date', 'contract_status']);

        $ids = $users->pluck('id')->all();

        $profiles = SalaryProfile::whereIn('user_id', $ids)->get()->keyBy('user_id');

        // Lifetime paid + last payment date, one grouped query rather than per-user.
        $paidTotals = Payslip::whereIn('user_id', $ids)
            ->where('status', 'Paid')
            ->selectRaw('user_id, SUM(net_amount) AS total_paid, MAX(paid_at) AS last_paid_at, COUNT(*) AS payments')
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        // This month's slip, if payroll has been generated for it.
        $thisMonth = Payslip::whereIn('user_id', $ids)
            ->whereDate('period_month', $monthStart)
            ->get(['id', 'user_id', 'status', 'net_amount', 'payroll_run_id', 'paid_at'])
            ->keyBy('user_id');

        $rows = $users->map(function ($user) use ($profiles, $paidTotals, $thisMonth) {
            $profile = $profiles->get($user->id);
            $paid    = $paidTotals->get($user->id);
            $slip    = $thisMonth->get($user->id);

            return [
                'user_id'          => $user->id,
                'name'             => $user->name,
                'email'            => $user->email,
                'profile_pic'      => $user->profile_pic,
                'employee_type'    => $user->employee_type,
                'employee_team'    => $user->employee_team,
                'joining_date'     => $user->joining_date,
                'contract_status'  => (int) ($user->contract_status ?? 1),

                'monthly_salary'   => $profile ? (float) $profile->monthly_salary : null,
                'currency'         => $profile->currency ?? 'PKR',
                'salary_status'    => $profile->status ?? null,
                'effective_from'   => optional($profile)->effective_from,
                'payment_method'   => $profile->payment_method ?? null,
                'has_salary'       => (bool) ($profile && (float) $profile->monthly_salary > 0),

                'total_paid'       => $paid ? (float) $paid->total_paid : 0.0,
                'payments_count'   => $paid ? (int) $paid->payments : 0,
                'last_paid_at'     => $paid->last_paid_at ?? null,

                // "Not Generated" is a real, meaningful state here — it is the
                // difference between "we haven't run payroll" and "we ran it and
                // haven't paid them", which the finance team needs to tell apart.
                'current_month_status' => $slip->status ?? 'Not Generated',
                'current_month_net'    => $slip ? (float) $slip->net_amount : null,
                'current_payslip_id'   => $slip->id ?? null,
            ];
        })->values();

        return response()->json([
            'data'    => $rows,
            'month'   => $monthStart->toDateString(),
            'summary' => [
                'employees'        => $rows->count(),
                'with_salary'      => $rows->where('has_salary', true)->count(),
                'missing_salary'   => $rows->where('has_salary', false)->count(),
                'monthly_total'    => round($rows->where('salary_status', 'Active')->sum('monthly_salary'), 2),
                'paid_this_month'  => $rows->where('current_month_status', 'Paid')->count(),
                'lifetime_paid'    => round($rows->sum('total_paid'), 2),
            ],
        ]);
    }

    /** One employee: current arrangement, full revision history, payment history. */
    public function show($userId)
    {
        $user = User::where('user_role', 3)->findOrFail($userId);

        $profile   = SalaryProfile::where('user_id', $user->id)->first();
        $revisions = SalaryRevision::with('creator:id,name')
            ->where('user_id', $user->id)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        $payslips = Payslip::with('items')
            ->where('user_id', $user->id)
            ->orderByDesc('period_month')
            ->get();

        return response()->json([
            'user' => [
                'id'            => $user->id,
                'name'          => $user->name,
                'email'         => $user->email,
                'profile_pic'   => $user->profile_pic,
                'employee_type' => $user->employee_type,
                'employee_team' => $user->employee_team,
                'joining_date'  => $user->joining_date,
            ],
            'profile'   => $profile,
            'revisions' => $revisions,
            'payslips'  => $payslips,
            'totals'    => [
                'lifetime_paid' => round((float) $payslips->where('status', 'Paid')->sum('net_amount'), 2),
                'payments'      => $payslips->where('status', 'Paid')->count(),
                'last_paid_at'  => $payslips->where('status', 'Paid')->max('paid_at'),
            ],
        ]);
    }

    /**
     * Set or adjust someone's salary.
     *
     * Profile update and history entry are written in ONE transaction: a salary
     * that changed without leaving a revision behind would silently break the
     * audit trail this module exists to provide.
     *
     * Already-issued payslips are deliberately NOT touched — they carry their own
     * copy of the figure. A raise applies to payroll generated from here on.
     */
    public function setSalary(Request $request, $userId)
    {
        $user = User::where('user_role', 3)->findOrFail($userId);

        $data = $request->validate([
            'monthly_salary'    => 'required|numeric|min:0|max:999999999',
            'effective_from'    => 'nullable|date',
            'reason'            => 'nullable|string|max:1000',
            'currency'          => 'nullable|string|max:8',
            'status'            => 'nullable|in:Active,On Hold,Inactive',
            'payment_method'    => 'nullable|string|max:120',
            'payment_reference' => 'nullable|string|max:190',
            'notes'             => 'nullable|string|max:2000',
        ]);

        $effectiveFrom = isset($data['effective_from'])
            ? Carbon::parse($data['effective_from'])->startOfDay()
            : Carbon::today();

        $newAmount = round((float) $data['monthly_salary'], 2);

        $profile = DB::transaction(function () use ($user, $data, $newAmount, $effectiveFrom) {
            $profile  = SalaryProfile::where('user_id', $user->id)->first();
            $previous = $profile ? (float) $profile->monthly_salary : null;

            if (!$profile) {
                $profile = new SalaryProfile([
                    'user_id'    => $user->id,
                    'created_by' => Auth::id(),
                ]);
            }

            $profile->monthly_salary = $newAmount;
            $profile->currency       = $data['currency'] ?? $profile->currency ?? 'PKR';
            $profile->status         = $data['status'] ?? $profile->status ?? 'Active';
            $profile->effective_from = $effectiveFrom;
            $profile->updated_by     = Auth::id();
            foreach (['payment_method', 'payment_reference', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $profile->{$field} = $data[$field];
                }
            }
            $profile->save();

            // Only record a revision when the figure actually moved — re-saving
            // payment details shouldn't litter the history with no-op entries.
            if ($previous === null || abs($previous - $newAmount) > 0.001) {
                SalaryRevision::create([
                    'user_id'         => $user->id,
                    'previous_amount' => $previous,
                    'new_amount'      => $newAmount,
                    'change_amount'   => $previous === null ? 0 : round($newAmount - $previous, 2),
                    'type'            => $this->revisionType($previous, $newAmount),
                    'effective_from'  => $effectiveFrom,
                    'reason'          => $data['reason'] ?? null,
                    'created_by'      => Auth::id(),
                ]);
            }

            return $profile;
        });

        return response()->json([
            'message' => 'Salary updated.',
            'data'    => $profile->fresh(),
        ]);
    }

    private function revisionType(?float $previous, float $next): string
    {
        if ($previous === null) return 'Initial';
        if ($next > $previous)  return 'Increment';
        if ($next < $previous)  return 'Decrement';
        return 'Correction';
    }

    /** Normalises any date (or nothing) to the first day of that month. */
    private function monthStart($value): Carbon
    {
        try {
            return $value ? Carbon::parse($value)->startOfMonth() : Carbon::today()->startOfMonth();
        } catch (\Throwable $e) {
            return Carbon::today()->startOfMonth();
        }
    }
}
