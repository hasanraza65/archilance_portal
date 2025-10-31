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
        Schema::create('project_chat_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('project_chats')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('file_path')->nullable();
            $table->string('file_type')->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->string('file_name')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_chat_attachments');
    }
};
