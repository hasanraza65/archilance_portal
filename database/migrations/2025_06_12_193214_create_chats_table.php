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
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->unsignedBigInteger('receiver_id')->nullable();
            $table->longText('message')->nullable();
            $table->unsignedBigInteger('reply_to')->nullable();
            $table->timestamps();

            $table->foreign('sender_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('receiver_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chats');
    }
};
