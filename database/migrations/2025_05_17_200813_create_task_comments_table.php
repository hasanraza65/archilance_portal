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
        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_id');
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->unsignedBigInteger('reply_to')->nullable();
            $table->foreign('task_id')->references('id')->on('project_tasks')->onDelete('cascade');
            $table->longText('comment_message')->nullable();
            $table->string('attachment_file')->nullable();
            $table->foreign('sender_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('reply_to')->references('id')->on('task_comments')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_comments');
    }
};
