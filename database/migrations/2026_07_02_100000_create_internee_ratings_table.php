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
        Schema::create('internee_ratings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('manager_id');
            $table->unsignedBigInteger('internee_id');
            // Always the top-level (root) task id — subtask work rolls up to its parent.
            $table->unsignedBigInteger('task_id');
            // The day this report is about (usually "yesterday").
            $table->date('rating_date');
            // True when the intern simply didn't work on this task that day — no stars required.
            $table->boolean('did_not_work')->default(false);

            $table->unsignedTinyInteger('technical_accuracy')->nullable();
            $table->unsignedTinyInteger('learning_improvement')->nullable();
            $table->unsignedTinyInteger('ownership_initiative')->nullable();
            $table->unsignedTinyInteger('communication_professionalism')->nullable();
            $table->unsignedTinyInteger('overall_recommendation')->nullable();
            $table->text('comments')->nullable();

            $table->timestamps();

            $table->foreign('manager_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('internee_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('task_id')->references('id')->on('project_tasks')->onDelete('cascade');

            // One rating per manager+internee+task+day.
            $table->unique(
                ['manager_id', 'internee_id', 'task_id', 'rating_date'],
                'internee_ratings_unique_entry'
            );

            $table->index(['internee_id', 'rating_date']);
            $table->index(['manager_id', 'rating_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('internee_ratings');
    }
};
