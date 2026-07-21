<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Previews the notification email templates by sending sample data to an
 * address. SMTP creds are read from env vars (never hard-coded), so run e.g.:
 *   T_USER=info@archilance.net T_PASS=*** php artisan notify:preview you@x.com
 */
class NotifyPreview extends Command
{
    protected $signature = 'notify:preview {email} {--only=} {--set=all}';
    protected $description = 'Send sample notification emails so you can preview the templates.';

    public function handle()
    {
        $to   = $this->argument('email');
        $host = getenv('T_HOST') ?: 'smtp.office365.com';
        $port = (int) (getenv('T_PORT') ?: 587);
        $user = getenv('T_USER');
        $pass = getenv('T_PASS');
        $from = getenv('T_FROM') ?: $user;

        if (!$user || !$pass) {
            $this->error('Set T_USER and T_PASS environment variables with your SMTP credentials.');
            return 1;
        }

        $transport = new EsmtpTransport($host, $port);
        $transport->setUsername($user);
        $transport->setPassword($pass);
        $mailer = new Mailer($transport);

        $only = $this->option('only');
        $set  = $this->option('set') ?: 'all';
        foreach ($this->samples() as $key => $s) {
            if ($only && $only !== $key) {
                continue;
            }
            if ($set !== 'all' && ($s['group'] ?? 'activity') !== $set) {
                continue;
            }
            try {
                $html = view($s['view'] ?? 'mails.notification', $s['data'])->render();
                $email = (new Email())
                    ->from(new Address($from, 'Archilance LLC'))
                    ->to($to)
                    ->subject($s['subject'])
                    ->html($html);
                $mailer->send($email);
                $this->info("✓ Sent [{$key}] — {$s['subject']}");
            } catch (\Throwable $e) {
                $this->error("✗ Failed [{$key}] — " . $e->getMessage());
            }
        }

        $this->line('');
        $this->info("Done. Check {$to} (and the Spam folder for first-time sends).");
        return 0;
    }

    private function samples(): array
    {
        $app = rtrim(env('FRONTEND_URL', 'http://archilance.org'), '/');

        return [
            'chat' => [
                'subject' => 'Ali Khan sent you a message — Archilance',
                'data' => [
                    'emoji' => '💬',
                    'heading' => 'New message',
                    'greetingName' => 'Hasan',
                    'intro' => '<strong>Ali Khan</strong> sent you a new message.',
                    'quoteAuthor' => 'Ali Khan',
                    'quote' => "Hey, can you review the latest mockups before our call today?",
                    'cta' => ['text' => 'Open chat', 'url' => "{$app}/chat"],
                    'signoff' => 'Reply directly inside Archilance.',
                ],
            ],
            'comment' => [
                'subject' => 'New comment on “Hero section” — Archilance',
                'data' => [
                    'emoji' => '🗨️',
                    'heading' => 'New comment',
                    'greetingName' => 'Hasan',
                    'intro' => "<strong>Ali Khan</strong> left a comment on a task you're assigned to.",
                    'quoteAuthor' => 'Ali Khan',
                    'quote' => "Please update the hero copy and push to staging when you get a chance.",
                    'details' => [
                        ['label' => 'Job', 'value' => 'Website Revamp'],
                        ['label' => 'Project', 'value' => 'Homepage'],
                        ['label' => 'Task', 'value' => 'Hero section'],
                        ['label' => 'Status', 'badge' => ['text' => 'In Progress', 'kind' => 'status']],
                        ['label' => 'Priority', 'badge' => ['text' => 'High', 'kind' => 'priority']],
                    ],
                    'cta' => ['text' => 'View task', 'url' => "{$app}/project/123"],
                ],
            ],
            'assignment' => [
                'subject' => 'You’ve been assigned to “Hero section” — Archilance',
                'data' => [
                    'emoji' => '📌',
                    'heading' => 'New assignment',
                    'greetingName' => 'Hasan',
                    'intro' => '<strong>Sarah (Admin)</strong> assigned you to a new task.',
                    'details' => [
                        ['label' => 'Job', 'value' => 'Website Revamp'],
                        ['label' => 'Project', 'value' => 'Homepage'],
                        ['label' => 'Task', 'value' => 'Hero section'],
                        ['label' => 'Due date', 'value' => 'Jul 25, 2026'],
                        ['label' => 'Priority', 'badge' => ['text' => 'Urgent', 'kind' => 'priority']],
                    ],
                    'cta' => ['text' => 'View task', 'url' => "{$app}/project/123"],
                    'signoff' => 'Jump in whenever you’re ready.',
                ],
            ],
            'status' => [
                'subject' => 'Status updated: “Hero section” → Client Review — Archilance',
                'data' => [
                    'emoji' => '🔄',
                    'heading' => 'Status updated',
                    'greetingName' => 'Hasan',
                    'intro' => "The status of a task you're assigned to has changed.",
                    'details' => [
                        ['label' => 'Task', 'value' => 'Hero section'],
                        ['label' => 'Project', 'value' => 'Homepage'],
                        ['label' => 'Previous', 'value' => 'Backlog'],
                        ['label' => 'New status', 'badge' => ['text' => 'Client Review', 'kind' => 'status']],
                        ['label' => 'Changed by', 'value' => 'Ali Khan'],
                    ],
                    'cta' => ['text' => 'View task', 'url' => "{$app}/project/123"],
                ],
            ],
            'due' => [
                'subject' => '⏰ You have 3 tasks due soon — Archilance',
                'data' => [
                    'emoji' => '⏰',
                    'heading' => 'Tasks due soon',
                    'greetingName' => 'Hasan',
                    'intro' => 'Here are your tasks with an approaching due date. Please make sure they’re on track.',
                    'items' => [
                        ['title' => 'Hero section', 'meta' => 'Website Revamp • Homepage • Due tomorrow (Jul 19)', 'badge' => ['text' => 'In Progress', 'kind' => 'status'], 'url' => "{$app}/project/123"],
                        ['title' => 'Contact form', 'meta' => 'Website Revamp • Due in 2 days (Jul 20)', 'badge' => ['text' => 'Backlog', 'kind' => 'status'], 'url' => "{$app}/project/124"],
                        ['title' => 'Logo export', 'meta' => 'Brand Kit • Due today (Jul 18)', 'badge' => ['text' => 'On Hold', 'kind' => 'status'], 'url' => "{$app}/project/125"],
                    ],
                    'cta' => ['text' => 'View my tasks', 'url' => "{$app}/members"],
                    'signoff' => 'Tip: keep your task statuses up to date as you make progress.',
                ],
            ],

            // ---- Redesigned existing templates (group: templates) ----
            'project_message' => [
                'subject' => 'New message in “Website Revamp” — Archilance',
                'view' => 'mails.new-project-message',
                'group' => 'templates',
                'data' => [
                    'message_text' => "Team, the new homepage board is ready — please review and leave your feedback today.",
                    'project_title' => 'Website Revamp',
                    'project_id' => 12,
                ],
            ],
            'forgot_password' => [
                'subject' => 'Your Archilance password has been reset',
                'view' => 'mails.forgot-password',
                'group' => 'templates',
                'data' => [
                    'name' => 'Hasan',
                    'email' => 'ranahasanraza24@gmail.com',
                    'password' => 'Temp#4821',
                ],
            ],
            'team_invite' => [
                'subject' => 'You’ve been invited to join a team — Archilance',
                'view' => 'mails.team-invite',
                'group' => 'templates',
                'data' => [
                    'name' => 'Hasan',
                    'email' => 'ranahasanraza24@gmail.com',
                    'password' => 'Temp#4821',
                    'customerName' => 'Acme Corp',
                ],
            ],
            'leave_request' => [
                'subject' => 'New leave request from Ali Khan — Archilance',
                'view' => 'mails.new-leave-request',
                'group' => 'templates',
                'data' => [
                    'sender_name' => 'Ali Khan',
                    'leaveType' => 'Annual Leave',
                    'startDate' => \Carbon\Carbon::parse('2026-07-25'),
                    'endDate' => \Carbon\Carbon::parse('2026-07-29'),
                ],
            ],
        ];
    }
}
