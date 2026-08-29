<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The CURRENT salary arrangement for one employee — exactly one row per user.
 *
 * This table only ever holds "what we pay them today". Every change to
 * `monthly_salary` writes a row to `salary_revisions` as well, so the history
 * lives there and this stays a simple, fast lookup for the payroll list.
 *
 * Payroll reads this at GENERATION time and copies the figure onto the payslip,
 * so a later raise never rewrites a payslip that has already been issued.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('salary_profiles')) {
            return;
        }

        Schema::create('salary_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();       // one arrangement per employee

            $table->decimal('monthly_salary', 12, 2)->default(0);
            $table->string('currency', 8)->default('PKR');

            // Free text for now. Payoneer/Nsave integration will read these later;
            // until then they are just what the finance team needs to make the transfer.
            $table->string('payment_method')->nullable();
            $table->string('payment_reference')->nullable();

            // Active | On Hold | Inactive — On Hold keeps the person visible in the
            // list but excludes them from generated payroll.
            $table->string('status')->default('Active');

            $table->date('effective_from')->nullable();            // when the current figure started
            $table->text('notes')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_profiles');
    }
};
