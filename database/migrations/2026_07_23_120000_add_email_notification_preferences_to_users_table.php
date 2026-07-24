<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user email notification preferences, stored as a small JSON map of
 * { category_key: bool }. NULL means "nothing set" → all categories default to
 * enabled (opt-out), so behaviour is unchanged until a user edits their settings.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'email_notification_preferences')) {
                $table->json('email_notification_preferences')->nullable()->after('probation_period_end_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'email_notification_preferences')) {
                $table->dropColumn('email_notification_preferences');
            }
        });
    }
};
