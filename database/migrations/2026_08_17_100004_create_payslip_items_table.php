<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The line items that make up a payslip beyond the basic salary — bonuses,
 * incentives, allowances, overtime on the earning side; tax, loans, advances and
 * absence on the deduction side.
 *
 * `kind` is deliberately only 'earning' | 'deduction' and `amount` is always
 * stored POSITIVE. Letting deductions be negative earnings would mean every
 * report has to remember the sign convention; splitting them makes
 * "total earnings" and "total deductions" two plain SUMs that cannot be wrong.
 *
 * `category` is a free string rather than an enum so finance can add a new
 * category without a migration; the UI offers the common ones as presets.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('payslip_items')) {
            return;
        }

        Schema::create('payslip_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('payslip_id');

            $table->string('kind', 16)->default('earning');        // earning | deduction
            $table->string('category')->default('Other');          // Bonus, Incentive, Allowance, Overtime, Tax, Loan, ...
            $table->string('label')->nullable();                   // free text shown on the payslip line
            $table->decimal('amount', 12, 2)->default(0);          // ALWAYS positive
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('payslip_id');
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_items');
    }
};
