<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use App\Models\Payslip;
use Illuminate\Support\Facades\Auth;

/**
 * An employee's own payslips — read-only, self only.
 *
 * Scoped to Auth::id() in the query itself rather than by checking an incoming
 * id, so there is no parameter that could be tampered with to read somebody
 * else's pay.
 *
 * Only APPROVED and PAID slips are exposed: a draft payroll is still being
 * edited and its figures are not yet anything the company stands behind.
 */
class MyPayslipController extends Controller
{
    public function index()
    {
        $payslips = Payslip::with('items')
            ->where('user_id', Auth::id())
            ->whereIn('status', ['Approved', 'Paid'])
            ->orderByDesc('period_month')
            ->get();

        return response()->json([
            'data'   => $payslips,
            'totals' => [
                'lifetime_paid' => round((float) $payslips->where('status', 'Paid')->sum('net_amount'), 2),
                'payments'      => $payslips->where('status', 'Paid')->count(),
                'last_paid_at'  => $payslips->where('status', 'Paid')->max('paid_at'),
            ],
        ]);
    }

    public function show($id)
    {
        $payslip = Payslip::with('items')
            ->where('user_id', Auth::id())
            ->whereIn('status', ['Approved', 'Paid'])
            ->findOrFail($id);

        return response()->json(['data' => $payslip]);
    }
}
