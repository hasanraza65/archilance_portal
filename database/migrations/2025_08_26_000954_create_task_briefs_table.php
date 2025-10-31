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
        Schema::create('task_briefs', function (Blueprint $table) {
            $table->id();
            $table->integer('task_id')->nullable();
            $table->integer('created_by')->nullable();
            $table->longText('brief_description')->nullable();
            $table->date('brief_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_briefs');
    }
};
