<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('employee_id');

            $table->dateTime('start_time');
            $table->dateTime('end_time');

            $table->integer('keyboard_clicks')->default(0);
            $table->integer('mouse_clicks')->default(0);

            $table->boolean('is_idle')->default(false);

            $table->string('active_window_title')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
