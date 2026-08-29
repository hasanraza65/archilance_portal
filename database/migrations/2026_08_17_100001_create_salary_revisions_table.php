<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only history of every salary change — the audit trail behind
 * `salary_profiles.monthly_salary`.
 *
 * Rows are never updated or deleted: an incorrect entry is corrected by adding
 * a further revision of type "Correction". That is what makes this usable as
 * evidence of what someone was paid and when it changed.
 *
 * `change_amount` is stored rather than derived so increment reports don't have
 * to self-join, and so the figure stays truthful even for the first row (where
 * there is no previous amount to subtract from).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('salary_revisions')) {
            return;
        }

        Schema::create('salary_revisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');

            $table->decimal('previous_amount', 12, 2)->nullable();  // null on the first ever entry
            $table->decimal('new_amount', 12, 2);
            $table->decimal('change_amount', 12, 2)->default(0);    // new - previous (may be negative)

            // Initial | Increment | Decrement | Correction
            $table->string('type')->default('Increment');

            $table->date('effective_from');
            $table->text('reason')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('effective_from');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_revisions');
    }
};
