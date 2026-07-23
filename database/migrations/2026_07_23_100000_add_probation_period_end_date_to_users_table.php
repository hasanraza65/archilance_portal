<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'probation_period_end_date')) {
                $table->date('probation_period_end_date')->nullable()->after('joining_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'probation_period_end_date')) {
                $table->dropColumn('probation_period_end_date');
            }
        });
    }
};
