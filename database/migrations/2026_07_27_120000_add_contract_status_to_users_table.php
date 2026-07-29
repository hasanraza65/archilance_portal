<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Employment-contract acceptance flag on users.
 *
 *   0 = contract not yet accepted (default for NEW users)
 *   1 = contract accepted (login allowed)
 *
 * All EXISTING users are back-filled to 1 so nobody currently in the system is
 * locked out. Only newly added employees (role 3) start at 0 and must accept
 * their contract before they can log in. Non-employee accounts are never gated
 * on this flag (see AuthController@login), so customers / team members that may
 * default to 0 are unaffected.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'contract_status')) {
                $table->tinyInteger('contract_status')->default(0)->after('is_default_pass');
            }
        });

        // Back-fill everyone who already exists as "accepted" so the new login
        // gate can never lock out a current user.
        DB::table('users')->update(['contract_status' => 1]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'contract_status')) {
                $table->dropColumn('contract_status');
            }
        });
    }
};
