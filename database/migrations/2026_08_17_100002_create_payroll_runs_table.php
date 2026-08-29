<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One month's payroll batch. This is the object the approval process acts on.
 *
 * Lifecycle:
 *   Draft ──▶ Pending Approval ──▶ Approved ──▶ Paid
 *     │                │                          ▲
 *     └── Cancelled ◀──┘        (partially paid stays "Approved" until all done)
 *
 * Payslips can only be edited while the run is Draft or Pending Approval, and
 * can only be marked paid once the run is Approved. That single rule is what
 * makes "the final approval comes from me" enforceable rather than a convention.
 *
 * `period_month` is always the FIRST day of the month, so a month is one value
 * to compare rather than a range. It is indexed, not unique: a cancelled run
 * must be able to coexist with its replacement.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('payroll_runs')) {
            return;
        }

        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();

            $table->date('period_month');                          // always YYYY-MM-01
            $table->string('title')->nullable();                   // e.g. "August 2026"

            // Draft | Pending Approval | Approved | Paid | Cancelled
            $table->string('status')->default('Draft');
            $table->string('currency', 8)->default('PKR');

            // Denormalised totals so the list page never has to sum payslips.
            // Recalculated by the controller whenever a payslip in the run changes.
            $table->decimal('total_gross', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);
            $table->decimal('total_net', 14, 2)->default(0);
            $table->unsignedInteger('employee_count')->default(0);
            $table->unsignedInteger('paid_count')->default(0);

            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('period_month');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
