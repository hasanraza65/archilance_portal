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
        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('parent_task_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('project_id')->references('id')->on('projects')->onDelete('cascade');
            $table->foreign('parent_task_id')->references('id')->on('project_tasks')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('set null');
            $table->string('task_title')->nullable();
            $table->longText('task_description')->nullable();
            $table->string('task_status')->default('Todo');
            $table->date('due_date')->nullable();
            $table->date('completed_date')->nullable();
            $table->string('priority')->default('Normal');
            $table->integer('board_order')->nullable();
            $table->timestamps();
            $table->softDeletes(); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_tasks');
    }
};
