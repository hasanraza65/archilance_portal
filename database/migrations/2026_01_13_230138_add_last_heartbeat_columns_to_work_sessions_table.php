<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('work_sessions', function (Blueprint $table) {
            $table->dateTime('last_heartbeat')
                  ->default(DB::raw('CURRENT_TIMESTAMP'))
                  ->after('type');

            $table->string('last_heartbeat_type')
                  ->nullable()
                  ->after('last_heartbeat');
        });
    }

    public function down(): void
    {
        Schema::table('work_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'last_heartbeat',
                'last_heartbeat_type',
            ]);
        });
    }
};
