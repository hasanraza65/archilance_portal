<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single line on a payslip. `amount` is ALWAYS positive; the sign is carried
 * by `kind` (earning | deduction) — see the migration for why.
 */
class PayslipItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public const EARNING   = 'earning';
    public const DEDUCTION = 'deduction';

    /** Presets the UI offers; `category` is a free string, so this is not a limit. */
    public const EARNING_CATEGORIES   = ['Bonus', 'Incentive', 'Allowance', 'Overtime', 'Commission', 'Arrears', 'Other'];
    public const DEDUCTION_CATEGORIES = ['Tax', 'Loan', 'Advance', 'Absence', 'Late Deduction', 'Other'];

    public function payslip()
    {
        return $this->belongsTo(Payslip::class, 'payslip_id');
    }
}
