<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable contract templates. `body` is rich HTML that may contain
 * {{ variable }} placeholders (see App\Support\ContractVariables). Soft-deleted
 * so a template can be retired without breaking contracts already generated
 * from it (contracts keep their own snapshot of the body).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('contract_templates')) {
            return;
        }

        Schema::create('contract_templates', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->longText('body')->nullable();          // rich HTML w/ {{placeholders}}
            $table->unsignedBigInteger('created_by')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_by');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_templates');
    }
};
