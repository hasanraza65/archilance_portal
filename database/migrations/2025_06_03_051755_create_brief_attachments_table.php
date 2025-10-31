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
        Schema::create('brief_attachments', function (Blueprint $table) {
            $table->id();
            $table->integer('brief_id');
            $table->integer('created_by');
            $table->string('file_path')->nullable();
            $table->string('file_type')->nullable();
            $table->string('file_size')->nullable();
            $table->string('file_name')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brief_attachments');
    }
};
