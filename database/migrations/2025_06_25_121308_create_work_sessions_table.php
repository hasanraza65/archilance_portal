<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('work_sessions', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->integer('task_id');
            // Separate fields
            $table->date('start_date')->default(DB::raw('CURRENT_DATE'));
            $table->time('start_time')->default(DB::raw('CURRENT_TIME'));
            $table->date('end_date')->nullable();
            $table->time('end_time')->nullable();
            $table->longText('memo_content')->nullable();
            $table->string('type')->default('Auto');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_sessions');
    }
};
