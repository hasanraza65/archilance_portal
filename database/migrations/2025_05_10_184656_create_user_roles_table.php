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
         Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g. Admin, Customer
            $table->string('slug')->unique(); // e.g. admin, customer
            $table->boolean('is_system')->default(false); // prevent deletion
            $table->unsignedBigInteger('company_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_roles');
    }
};
