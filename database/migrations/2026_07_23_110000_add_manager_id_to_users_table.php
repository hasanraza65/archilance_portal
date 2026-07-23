<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * manager_id — the manager a (non-internee) employee reports to. Mirrors the
 * existing internee_manager_id column (nullable integer, no FK constraint, in
 * keeping with this app's convention of enforcing integrity in code).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'manager_id')) {
                $table->integer('manager_id')->nullable()->after('internee_manager_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'manager_id')) {
                $table->dropColumn('manager_id');
            }
        });
    }
};
