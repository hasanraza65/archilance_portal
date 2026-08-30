<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `gender` to users, needed by the Maternity / Paternity leave categories
 * introduced with the Aug 2026 policy update (LeavePolicy::MATERNITY / PATERNITY).
 *
 * Deliberately NULLABLE with no default. Every existing employee therefore has
 * gender = NULL, which LeavePolicy reads as "not recorded yet" and treats
 * permissively: both categories stay visible and requestable until somebody
 * actually sets the field. Defaulting to a value instead would silently hide
 * Maternity from every woman already in the system.
 *
 * Stored as a plain VARCHAR rather than an ENUM so a future third option (or a
 * "prefer not to say") needs no migration — the accepted values are enforced in
 * the controllers' validation instead.
 *
 * SAFETY: additive only. No existing column, index or row is touched, and
 * nothing reads this column unless it is present, so a backend-only deploy
 * cannot affect any currently-live client.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('users', 'gender')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('gender', 20)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'gender')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('gender');
        });
    }
};
