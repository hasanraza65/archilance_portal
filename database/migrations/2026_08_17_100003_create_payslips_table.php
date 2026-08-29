<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One employee's pay for one month. This is the record a payslip PDF is rendered
 * from, and the row that "last paid on" / "total paid to date" are read from.
 *
 * ── WHY THE SNAPSHOT COLUMNS ────────────────────────────────────────────────
 * employee_name / designation / department / joining_date are COPIED here at
 * generation time rather than joined from `users` at render time. A payslip is a
 * financial document: reprinting March's payslip in December must show what was
 * true in March, even if the person has since changed title, team, or name — or
 * left and had their account soft-deleted.
 *
 * Likewise `basic_salary` is copied from salary_profiles at generation, so a
 * later raise never silently rewrites an already-issued payslip.
 *
 * Money model: gross = basic_salary + total_earnings; net = gross - total_deductions.
 * The earning/deduction detail lives in `payslip_items`; these three columns are
 * kept in step by the controller so lists and reports never have to aggregate.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('payslips')) {
            return;
        }

        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payroll_run_id');
            $table->unsignedBigInteger('user_id');
            $table->date('period_month');                          // denormalised from the run, for direct queries

            $table->string('payslip_no', 40)->nullable()->unique(); // e.g. ALLC-2026-08-0042

            $table->decimal('basic_salary', 12, 2)->default(0);
            $table->decimal('total_earnings', 12, 2)->default(0);   // sum of 'earning' items
            $table->decimal('total_deductions', 12, 2)->default(0); // sum of 'deduction' items
            $table->decimal('gross_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->string('currency', 8)->default('PKR');

            // Pending | Approved | Paid | On Hold | Skipped
            $table->string('status')->default('Pending');

            $table->timestamp('paid_at')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();        // transfer id / Payoneer ref later

            // Point-in-time snapshot — see the note above.
            $table->string('employee_name')->nullable();
            $table->string('employee_email')->nullable();
            $table->string('designation')->nullable();
            $table->string('department')->nullable();
            $table->date('joining_date')->nullable();

            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('paid_by')->nullable();

            $table->timestamps();

            $table->index('payroll_run_id');
            $table->index('user_id');
            $table->index('period_month');
            $table->index('status');
            // "this person's pay for this month" — the lookup the employee view uses.
            $table->index(['user_id', 'period_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }
};
