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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('project_name')->nullable();
            $table->longText('project_description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('delivered_date')->nullable();
            $table->enum('status', ['In Progress', 'Pending', 'Completed', 'Cancelled'])->default('In Progress');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->foreign('customer_id')->references('id')->on('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes(); // This handles soft deletes
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
