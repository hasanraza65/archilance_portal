<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // project_tasks: filter by project, parent, status, kanban order
        Schema::table('project_tasks', function (Blueprint $table) {
            if (!$this->indexExists('project_tasks', 'project_tasks_project_id_index')) {
                $table->index('project_id');
            }
            if (!$this->indexExists('project_tasks', 'project_tasks_parent_task_id_index')) {
                $table->index('parent_task_id');
            }
            if (!$this->indexExists('project_tasks', 'project_tasks_task_status_index')) {
                $table->index('task_status');
            }
            if (!$this->indexExists('project_tasks', 'project_tasks_board_order_index')) {
                $table->index('board_order');
            }
        });

        // task_assignees: lookup assignees by task and employee
        Schema::table('task_assignees', function (Blueprint $table) {
            if (!$this->indexExists('task_assignees', 'task_assignees_task_id_index')) {
                $table->index('task_id');
            }
            if (!$this->indexExists('task_assignees', 'task_assignees_employee_id_index')) {
                $table->index('employee_id');
            }
        });

        // task_comments: filter by task, reply threading, visibility flag
        Schema::table('task_comments', function (Blueprint $table) {
            if (!$this->indexExists('task_comments', 'task_comments_task_id_index')) {
                $table->index('task_id');
            }
            if (!$this->indexExists('task_comments', 'task_comments_reply_to_index')) {
                $table->index('reply_to');
            }
            if (!$this->indexExists('task_comments', 'task_comments_allowed_customer_index')) {
                $table->index('allowed_customer');
            }
            if (!$this->indexExists('task_comments', 'task_comments_sender_id_index')) {
                $table->index('sender_id');
            }
        });

        // task_comment_read_statuses: lookup per user per comment
        Schema::table('task_comment_read_statuses', function (Blueprint $table) {
            if (!$this->indexExists('task_comment_read_statuses', 'tcrs_comment_receiver_index')) {
                $table->index(['comment_id', 'receiver_id'], 'tcrs_comment_receiver_index');
            }
            if (!$this->indexExists('task_comment_read_statuses', 'task_comment_read_statuses_receiver_id_index')) {
                $table->index('receiver_id');
            }
        });

        // work_sessions: the hottest table — filter by task_id and user_id
        Schema::table('work_sessions', function (Blueprint $table) {
            if (!$this->indexExists('work_sessions', 'work_sessions_task_id_index')) {
                $table->index('task_id');
            }
            if (!$this->indexExists('work_sessions', 'work_sessions_user_id_index')) {
                $table->index('user_id');
            }
            if (!$this->indexExists('work_sessions', 'work_sessions_task_user_index')) {
                $table->index(['task_id', 'user_id'], 'work_sessions_task_user_index');
            }
            if (!$this->indexExists('work_sessions', 'work_sessions_start_date_index')) {
                $table->index('start_date');
            }
        });

        // session_time_adjustments: always queried by session_id
        Schema::table('session_time_adjustments', function (Blueprint $table) {
            if (!$this->indexExists('session_time_adjustments', 'session_time_adjustments_session_id_index')) {
                $table->index('session_id');
            }
        });

        // project_assignees: already has some indexes but ensure task_id lookups are fast
        Schema::table('project_assignees', function (Blueprint $table) {
            if (!$this->indexExists('project_assignees', 'project_assignees_employee_id_index')) {
                $table->index('employee_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropIndexIfExists('project_tasks_project_id_index');
            $table->dropIndexIfExists('project_tasks_parent_task_id_index');
            $table->dropIndexIfExists('project_tasks_task_status_index');
            $table->dropIndexIfExists('project_tasks_board_order_index');
        });

        Schema::table('task_assignees', function (Blueprint $table) {
            $table->dropIndexIfExists('task_assignees_task_id_index');
            $table->dropIndexIfExists('task_assignees_employee_id_index');
        });

        Schema::table('task_comments', function (Blueprint $table) {
            $table->dropIndexIfExists('task_comments_task_id_index');
            $table->dropIndexIfExists('task_comments_reply_to_index');
            $table->dropIndexIfExists('task_comments_allowed_customer_index');
            $table->dropIndexIfExists('task_comments_sender_id_index');
        });

        Schema::table('task_comment_read_statuses', function (Blueprint $table) {
            $table->dropIndexIfExists('tcrs_comment_receiver_index');
            $table->dropIndexIfExists('task_comment_read_statuses_receiver_id_index');
        });

        Schema::table('work_sessions', function (Blueprint $table) {
            $table->dropIndexIfExists('work_sessions_task_id_index');
            $table->dropIndexIfExists('work_sessions_user_id_index');
            $table->dropIndexIfExists('work_sessions_task_user_index');
            $table->dropIndexIfExists('work_sessions_start_date_index');
        });

        Schema::table('session_time_adjustments', function (Blueprint $table) {
            $table->dropIndexIfExists('session_time_adjustments_session_id_index');
        });

        Schema::table('project_assignees', function (Blueprint $table) {
            $table->dropIndexIfExists('project_assignees_employee_id_index');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return collect(\DB::select("SHOW INDEX FROM `{$table}`"))
            ->pluck('Key_name')
            ->contains($indexName);
    }
};