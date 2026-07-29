<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A generated contract sent to one recipient.
 *
 * `body` is the FINAL, already-substituted HTML for THIS recipient (the sender
 * may have edited it before sending — that edit affects only this row, never the
 * template). `token` powers the public, no-login view/accept link. `variables`
 * keeps the values that were merged in, for the record.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('contracts')) {
            return;
        }

        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('template_id')->nullable(); // source template (nullable: template may be deleted)
            $table->unsignedBigInteger('recipient_id');            // users.id of the person receiving it
            $table->unsignedBigInteger('created_by')->nullable();  // admin/executive who generated it

            $table->string('title');
            $table->longText('body');                              // final substituted (+ possibly edited) HTML
            $table->json('variables')->nullable();                 // merged variable values, for the record

            // Snapshots so the list stays correct even if the user record changes.
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();

            $table->string('token', 64)->unique();                 // public link token
            $table->string('status')->default('Sent');             // Sent | Accepted | Declined

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('accepted_ip')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('recipient_id');
            $table->index('template_id');
            $table->index('status');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
