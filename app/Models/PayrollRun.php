<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One month's payroll batch — the unit that gets approved.
 *
 * Status gates the whole module: payslips are editable only while the run is
 * Draft or Pending Approval, and payable only once it is Approved. Those two
 * predicates live here so every controller action asks the same question.
 */
class PayrollRun extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'period_month'     => 'date:Y-m-d',
        'approved_at'      => 'datetime',
        'total_gross'      => 'decimal:2',
        'total_deductions' => 'decimal:2',
        'total_net'        => 'decimal:2',
    ];

    public const DRAFT     = 'Draft';
    public const PENDING   = 'Pending Approval';
    public const APPROVED  = 'Approved';
    public const PAID      = 'Paid';
    public const CANCELLED = 'Cancelled';

    public function payslips()
    {
        return $this->hasMany(Payslip::class, 'payroll_run_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Payslips may be added, edited or removed. */
    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::PENDING], true);
    }

    /** Money may actually be marked as sent. */
    public function isPayable(): bool
    {
        return in_array($this->status, [self::APPROVED, self::PAID], true);
    }

    /**
     * Recomputes the denormalised totals from the payslips, and promotes the run
     * to Paid once every payable slip is settled. Slips that were deliberately
     * skipped or put on hold don't block that — otherwise one excluded person
     * would leave the run stuck in Approved forever.
     */
    public function recalculate(bool $save = true): self
    {
        $slips = $this->payslips()->get(['status', 'gross_amount', 'total_deductions', 'net_amount']);
        $counted = $slips->whereNotIn('status', ['Skipped']);

        $this->employee_count    = $counted->count();
        $this->total_gross       = (float) $counted->sum('gross_amount');
        $this->total_deductions  = (float) $counted->sum('total_deductions');
        $this->total_net         = (float) $counted->sum('net_amount');
        $this->paid_count        = $counted->where('status', 'Paid')->count();

        $awaiting = $counted->whereNotIn('status', ['Paid', 'On Hold'])->count();
        if ($this->status === self::APPROVED && $counted->count() > 0 && $awaiting === 0) {
            $this->status = self::PAID;
        }

        if ($save) {
            $this->save();
        }

        return $this;
    }
}
