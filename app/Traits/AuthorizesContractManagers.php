<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * The Contracts module is available to Admins (user_role 2) and Executives
 * (user_role 3 with employee_type 'Executive'). Both share the SAME fixed
 * /api/contracts* routes (not role-prefixed), so authorization is enforced here
 * in the controllers rather than via route middleware.
 */
trait AuthorizesContractManagers
{
    /** Abort with 403 unless the caller is an Admin or an Executive. Returns the user. */
    protected function authorizeContractManager()
    {
        $u = Auth::user();

        $isAdmin     = (int) ($u->user_role ?? 0) === 2;
        $isExecutive = ($u->employee_type ?? null) === 'Executive';

        if (!$isAdmin && !$isExecutive) {
            abort(403, 'Only admins and executives can manage contracts.');
        }

        return $u;
    }

    /**
     * Everyone who should be alerted when a contract is accepted:
     * all Admins (role 2) + all Executives (role 3 + employee_type 'Executive').
     */
    protected function contractManagerAudience()
    {
        return User::where('user_role', 2)
            ->orWhere(function ($q) {
                $q->where('user_role', 3)->where('employee_type', 'Executive');
            })
            ->get();
    }

    /**
     * Send one of the module's transactional emails through the shared
     * mails.notification blade. Bypasses per-category opt-outs (contract mail is
     * transactional, not a preference-based notification) and never throws.
     */
    protected function sendContractMail($toEmail, string $subject, array $data): void
    {
        if (empty($toEmail)) {
            return;
        }
        try {
            \Mail::send('mails.notification', $data, function ($message) use ($toEmail, $subject) {
                $message->from('info@archilance.net', 'Archilance LLC')
                    ->to($toEmail)
                    ->subject($subject);
            });
        } catch (\Throwable $e) {
            \Log::warning('Contract email failed for ' . $toEmail . ': ' . $e->getMessage());
        }
    }
}
