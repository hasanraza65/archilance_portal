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
        Schema::create('chat_read_statuses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('receiver_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable(); 
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->foreign('receiver_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('message_id')->references('id')->on('chats')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_read_statuses');
    }
};
