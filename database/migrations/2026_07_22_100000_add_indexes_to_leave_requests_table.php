<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The original leave_requests table only had the user_id foreign-key index.
 * Every status filter/count was therefore a full table scan, and the employee
 * cycle lookup (user_id + start_date range) could only use user_id.
 *
 * Indexes are additive: they change nothing about the data or the API, only how
 * MySQL plans the queries.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            // Admin status filters + the grouped status counts.
            $table->index('status', 'leave_requests_status_index');

            // Employee cycle lookup: where user_id = ? and start_date between ? and ?
            $table->index(['user_id', 'start_date'], 'leave_requests_user_start_index');

            // Admin list default ordering (created_at desc) + pagination.
            $table->index('created_at', 'leave_requests_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropIndex('leave_requests_status_index');
            $table->dropIndex('leave_requests_user_start_index');
            $table->dropIndex('leave_requests_created_at_index');
        });
    }
};
