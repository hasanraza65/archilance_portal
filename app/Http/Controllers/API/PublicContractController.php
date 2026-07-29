<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\User;
use App\Traits\AuthorizesContractManagers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Public, NO-LOGIN endpoints for a contract recipient. Access is guarded solely
 * by the unguessable 48-char token in the link.
 */
class PublicContractController extends Controller
{
    use AuthorizesContractManagers; // for sendContractMail() + contractManagerAudience()

    /** GET /api/contracts/public/{token} — the recipient's view payload. */
    public function show($token)
    {
        $contract = Contract::where('token', $token)->first();

        if (!$contract) {
            return response()->json(['message' => 'This contract link is invalid or has expired.'], 404);
        }

        return response()->json([
            'title'          => $contract->title,
            'body'           => $contract->body,
            'status'         => $contract->status,
            'recipient_name' => $contract->recipient_name,
            'accepted_at'    => $contract->accepted_at,
            'company'        => 'Archilance LLC',
        ]);
    }

    /**
     * POST /api/contracts/public/{token}/accept
     * The recipient accepts. Unlocks their login, issues a fresh password, and
     * emails them a welcome + credentials. Admins/Executives are alerted.
     */
    public function accept(Request $request, $token)
    {
        $contract = Contract::where('token', $token)->first();

        if (!$contract) {
            return response()->json(['message' => 'This contract link is invalid or has expired.'], 404);
        }

        if ($contract->status === 'Accepted') {
            return response()->json([
                'message'      => 'This contract has already been accepted.',
                'already'      => true,
                'accepted_at'  => $contract->accepted_at,
            ]);
        }

        $ip = $request->header('X-Forwarded-For') ?? $request->ip();

        $contract->status      = 'Accepted';
        $contract->accepted_at = now();
        $contract->accepted_ip = $ip;
        $contract->save();

        $recipient = User::find($contract->recipient_id);

        if ($recipient) {
            // Issue fresh credentials so the employee can now sign in. The
            // welcome email is sent FIRST — only persist the new password if the
            // mail goes out, so a mail failure can't leave them with an unknown
            // password.
            $plainPassword = Str::random(10);
            $mailed = $this->sendWelcomeEmail($recipient, $plainPassword);

            $recipient->contract_status = 1; // unlock login regardless
            if ($mailed) {
                $recipient->password        = Hash::make($plainPassword);
                $recipient->is_default_pass = 1; // prompt a change after first login
            }
            $recipient->save();

            $this->alertManagers($contract);
        }

        return response()->json([
            'message' => 'Thank you! Your contract has been accepted. Check your email for your login details.',
        ]);
    }

    /* ------------------------------------------------------------------ */

    private function sendWelcomeEmail(User $recipient, string $plainPassword): bool
    {
        if (empty($recipient->email)) {
            return false;
        }

        $first = trim((string) $recipient->name);
        $greeting = $first !== '' ? explode(' ', $first)[0] : 'there';

        try {
            \Mail::send('mails.notification', [
                'emoji'        => '🎉',
                'heading'      => 'Welcome to Archilance LLC!',
                'greetingName' => $greeting,
                'intro'        => 'Thank you for accepting your employment contract. Your account is now <strong>active</strong>. Use the credentials below to sign in to the Archilance Portal and the Time Tracker desktop app.',
                'details'      => [
                    ['label' => 'Login Email',        'value' => $recipient->email],
                    ['label' => 'Temporary Password', 'value' => $plainPassword],
                ],
                'cta'          => [
                    'text' => 'Log in to the Portal',
                    'url'  => frontendUrl('/'),
                ],
                'signoff'      => 'For your security, please change your password after your first login. The same email and password also sign you in to the Archilance Time Tracker desktop app.',
                'accent'       => '16a34a',
            ], function ($message) use ($recipient) {
                $message->from('info@archilance.net', 'Archilance LLC')
                    ->to($recipient->email)
                    ->subject('Welcome to Archilance LLC — Your Account is Ready');
            });

            return true;
        } catch (\Throwable $e) {
            \Log::warning('Contract welcome email failed for user ' . ($recipient->id ?? '?') . ': ' . $e->getMessage());
            return false;
        }
    }

    private function alertManagers(Contract $contract): void
    {
        $when = now()->format('M j, Y g:i A');

        foreach ($this->contractManagerAudience() as $mgr) {
            if (empty($mgr->email)) {
                continue;
            }
            $first = trim((string) $mgr->name);
            $greeting = $first !== '' ? explode(' ', $first)[0] : 'there';

            $this->sendContractMail(
                $mgr->email,
                'Contract Accepted: ' . $contract->recipient_name,
                [
                    'emoji'        => '✅',
                    'heading'      => 'Contract Accepted',
                    'greetingName' => $greeting,
                    'intro'        => '<strong>' . e($contract->recipient_name) . '</strong> has accepted their employment contract.',
                    'details'      => [
                        ['label' => 'Employee',    'value' => $contract->recipient_name],
                        ['label' => 'Email',       'value' => $contract->recipient_email],
                        ['label' => 'Contract',    'value' => $contract->title],
                        ['label' => 'Accepted at', 'value' => $when],
                    ],
                    'accent'       => '16a34a',
                ]
            );
        }
    }
}
