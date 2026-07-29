<?php

namespace App\Console\Commands;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Emails Admins & Executives a digest of every employee whose probation period
 * end date is reached (defaults to "today"). Employees with a NULL probation date
 * are ignored. Each row links straight to that employee's edit page.
 *
 * Options:
 *   --date=YYYY-MM-DD  Check a specific date instead of today (for testing).
 *   --to=EMAIL         Send the digest ONLY to this address (testing) instead of
 *                      the real Admin/Executive audience.
 *   --sample           If nobody matches the date, use one recent employee with a
 *                      probation date so a test email still has content.
 *
 * No DB changes required — it reads the existing users.probation_period_end_date.
 */
class ProbationEndReminder extends Command
{
    protected $signature = 'notify:probation-reminders {--date=} {--to=} {--sample}';
    protected $description = 'Email admins & executives when an employee\'s probation period end date is reached.';

    public function handle()
    {
        $target = $this->option('date')
            ? Carbon::parse($this->option('date'))->toDateString()
            : Carbon::today()->toDateString();

        $override = trim((string) $this->option('to'));

        // Employees whose probation ends on the target date (NULLs ignored).
        $employees = User::whereNotNull('probation_period_end_date')
            ->whereDate('probation_period_end_date', $target)
            ->orderBy('name')
            ->get();

        // Testing aid: nobody ends today → borrow one recent employee so the test
        // email still renders.
        if ($employees->isEmpty() && $this->option('sample')) {
            $employees = User::whereNotNull('probation_period_end_date')
                ->orderByDesc('probation_period_end_date')
                ->limit(1)
                ->get();
            if ($employees->isNotEmpty()) {
                $this->warn("No employees end probation on {$target} — using a sample for the test email.");
            }
        }

        if ($employees->isEmpty()) {
            $this->info("No employees reached their probation end date on {$target}. Nothing to send.");
            return self::SUCCESS;
        }

        // One digest listing every matched employee, each with a direct edit link.
        $items = [];
        foreach ($employees as $emp) {
            $ended = Carbon::parse($emp->probation_period_end_date)->format('M j, Y');
            $items[] = [
                'title' => $emp->name ?: ('Employee #' . $emp->id),
                'meta'  => trim(($emp->email ? $emp->email . ' • ' : '') . 'Probation ended ' . $ended),
                'url'   => frontendUrl('employees/edit/' . $emp->id),
            ];
        }

        $count   = count($items);
        $subject = '🎓 Probation ' . ($count > 1 ? 'periods ending' : 'period ending')
            . ' — ' . $count . ' employee' . ($count > 1 ? 's' : '') . ' — Archilance';

        $data = [
            'emoji'   => '🎓',
            'heading' => 'Probation period ' . ($count > 1 ? 'endings' : 'ending'),
            'intro'   => 'The following employee' . ($count > 1 ? 's have' : ' has')
                . ' reached their probation period end date. Please review '
                . ($count > 1 ? 'their records' : 'the record') . ' and take any required action.',
            'items'   => $items,
            'cta'     => ['text' => 'Open Employees', 'url' => frontendUrl('employees')],
            'signoff' => 'Click an employee above to open their profile directly.',
            'accent'  => '4f46e5',
        ];

        // Recipients: the test override, or every Admin + Executive.
        if ($override !== '') {
            $recipients = [['email' => $override, 'name' => 'there']];
        } else {
            $recipients = $this->adminAndExecutiveAudience()
                ->filter(fn ($u) => !empty($u->email))
                ->map(fn ($u) => ['email' => $u->email, 'name' => $u->name])
                ->values()
                ->all();
        }

        $sent = 0;
        foreach ($recipients as $r) {
            $first    = trim((string) $r['name']);
            $greeting = $first !== '' ? explode(' ', $first)[0] : 'there';
            if ($this->sendMail($r['email'], $subject, array_merge($data, ['greetingName' => $greeting]))) {
                $sent++;
            }
        }

        $this->info("Probation reminders: {$count} employee(s) matched {$target}; digest emailed to {$sent} recipient(s).");
        return self::SUCCESS;
    }

    /** All Admins (role 2) + Executives (role 3 + employee_type 'Executive'). */
    private function adminAndExecutiveAudience()
    {
        return User::where('user_role', 2)
            ->orWhere(function ($q) {
                $q->where('user_role', 3)->where('employee_type', 'Executive');
            })
            ->get();
    }

    private function sendMail($toEmail, string $subject, array $data): bool
    {
        if (empty($toEmail)) {
            return false;
        }
        try {
            \Mail::send('mails.notification', $data, function ($m) use ($toEmail, $subject) {
                $m->from('info@archilance.net', 'Archilance LLC')
                    ->to($toEmail)
                    ->subject($subject);
            });
            return true;
        } catch (\Throwable $e) {
            \Log::warning('Probation reminder email failed for ' . $toEmail . ': ' . $e->getMessage());
            return false;
        }
    }
}
