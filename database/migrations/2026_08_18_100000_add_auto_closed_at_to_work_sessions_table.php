<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a session close as PROVISIONAL — "the watchdog guessed this", as opposed
 * to "the user pressed Stop".
 *
 * Why this column exists
 * ----------------------
 * CheckHeartBeat closes a session when the app goes quiet for 20 minutes. That is
 * a guess: a sleeping laptop or a dropped wifi looks identical to a finished day.
 * Until now that guess was written the same way as a real stop, so nothing could
 * ever correct it — a session truncated at 20:08 stayed truncated even though the
 * app kept heartbeating, screenshotting and logging activity until 02:19 (see
 * session 30151, 17 Aug 2026: 6h14m of evidenced work lost).
 *
 * With this column the guess is distinguishable, so an explicit Stop arriving
 * later can extend the session to the real end. NULL = closed for real (either by
 * the user, or the session is still open); non-NULL = closed by the watchdog and
 * still correctable.
 *
 * SAFETY: additive and nullable. Every existing query (`whereNull('end_time')`,
 * `whereNull('end_date')`, the worked-time maths) is untouched, and all existing
 * rows default to NULL, which reads as "not auto-closed" — the conservative
 * value. Nothing behaves differently until the new code sets it.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('work_sessions', 'auto_closed_at')) {
            return;
        }

        Schema::table('work_sessions', function (Blueprint $table) {
            $table->timestamp('auto_closed_at')->nullable()->after('type');
            $table->index('auto_closed_at');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('work_sessions', 'auto_closed_at')) {
            return;
        }

        Schema::table('work_sessions', function (Blueprint $table) {
            $table->dropIndex(['auto_closed_at']);
            $table->dropColumn('auto_closed_at');
        });
    }
};
