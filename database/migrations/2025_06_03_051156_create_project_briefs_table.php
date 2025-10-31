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
        Schema::create('project_briefs', function (Blueprint $table) {
            $table->id();
            $table->integer('project_id');
            $table->integer('created_by');
            $table->longText('brief_description');
            $table->date('brief_date');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_briefs');
    }
};
