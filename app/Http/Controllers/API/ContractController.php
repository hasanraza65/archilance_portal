<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\User;
use App\Traits\AuthorizesContractManagers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class ContractController extends Controller
{
    use AuthorizesContractManagers;

    /** Attach the public share link to a contract (or collection) for the response. */
    private function withPublicUrl($contract)
    {
        $contract->public_url = frontendUrl('contract/view/' . $contract->token);
        return $contract;
    }

    /**
     * GET /api/contracts
     * Sent-contracts list for admins/executives. Paginated envelope.
     * Optional ?search= (recipient name/email/title) and ?status=.
     */
    public function index(Request $request)
    {
        $this->authorizeContractManager();

        $query = Contract::with([
            'recipient:id,name,email',
            'creator:id,name',
            'template:id,title',
        ])->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $like = '%' . trim((string) $request->input('search')) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('recipient_name', 'like', $like)
                    ->orWhere('recipient_email', 'like', $like)
                    ->orWhere('title', 'like', $like);
            });
        }

        $perPage = (int) $request->input('per_page', 15);
        $perPage = max(1, min($perPage, 100));

        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->each(fn ($c) => $this->withPublicUrl($c));

        return response()->json($paginator);
    }

    /**
     * POST /api/contracts
     * Generate & send a contract to one recipient. `body` is the FINAL HTML the
     * sender saw/edited in the preview (already variable-merged on the client).
     */
    public function store(Request $request)
    {
        $this->authorizeContractManager();

        $validator = Validator::make($request->all(), [
            'recipient_id' => 'required|integer|exists:users,id',
            'template_id'  => 'nullable|integer|exists:contract_templates,id',
            'title'        => 'required|string|max:255',
            'body'         => 'required|string',
            'variables'    => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $recipient = User::find($request->recipient_id);
        if (empty($recipient->email)) {
            return response()->json([
                'message' => 'This employee has no email address on file, so the contract cannot be delivered.',
            ], 422);
        }

        $contract = Contract::create([
            'template_id'     => $request->template_id,
            'recipient_id'    => $recipient->id,
            'created_by'      => Auth::id(),
            'title'           => $request->title,
            'body'            => $request->body,
            'variables'       => $request->input('variables', []),
            'recipient_name'  => $recipient->name,
            'recipient_email' => $recipient->email,
            'token'           => $this->uniqueToken(),
            'status'          => 'Sent',
            'sent_at'         => now(),
        ]);

        $this->sendContractSentEmail($contract);

        return response()->json([
            'message' => 'Contract generated and sent to ' . $recipient->name . '.',
            'data'    => $this->withPublicUrl($contract->fresh(['recipient:id,name,email'])),
        ], 201);
    }

    /** GET /api/contracts/{id} */
    public function show($id)
    {
        $this->authorizeContractManager();

        $contract = Contract::with([
            'recipient:id,name,email,phone',
            'creator:id,name',
            'template:id,title',
        ])->findOrFail($id);

        return response()->json($this->withPublicUrl($contract));
    }

    /**
     * PUT/PATCH /api/contracts/{id}
     * Edit an already-generated contract's title/body (does NOT touch the template).
     */
    public function update(Request $request, $id)
    {
        $this->authorizeContractManager();

        $contract = Contract::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'body'  => 'sometimes|required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->has('title')) $contract->title = $request->title;
        if ($request->has('body'))  $contract->body = $request->body;
        $contract->save();

        return response()->json([
            'message' => 'Contract updated successfully.',
            'data'    => $this->withPublicUrl($contract),
        ]);
    }

    /**
     * PATCH /api/contracts/{id}/status
     * Manual status override by an admin/executive. Setting it to Accepted also
     * unlocks the recipient's login (contract_status = 1). We never re-lock a user
     * from here, and no password/welcome email is sent (that belongs to the
     * self-serve acceptance flow).
     */
    public function updateStatus(Request $request, $id)
    {
        $this->authorizeContractManager();

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:Sent,Accepted,Declined',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $contract = Contract::findOrFail($id);
        $contract->status = $request->status;

        if ($request->status === 'Accepted' && empty($contract->accepted_at)) {
            $contract->accepted_at = now();
        }
        $contract->save();

        if ($request->status === 'Accepted') {
            $recipient = User::find($contract->recipient_id);
            if ($recipient && (int) ($recipient->contract_status ?? 0) === 0) {
                $recipient->contract_status = 1; // unlock login
                $recipient->save();
            }
        }

        return response()->json([
            'message' => 'Contract status updated to ' . $request->status . '.',
            'data'    => $this->withPublicUrl($contract),
        ]);
    }

    /** POST /api/contracts/{id}/resend — email the contract link to the recipient again. */
    public function resend($id)
    {
        $this->authorizeContractManager();

        $contract = Contract::findOrFail($id);

        if (empty($contract->recipient_email)) {
            return response()->json(['message' => 'No recipient email on file.'], 422);
        }

        $this->sendContractSentEmail($contract);

        return response()->json(['message' => 'Contract re-sent to ' . $contract->recipient_email . '.']);
    }

    /** DELETE /api/contracts/{id} */
    public function destroy($id)
    {
        $this->authorizeContractManager();

        Contract::findOrFail($id)->delete();

        return response()->json(['message' => 'Contract deleted successfully.']);
    }

    /* ------------------------------------------------------------------ */

    private function uniqueToken(): string
    {
        do {
            $token = Str::random(48);
        } while (Contract::where('token', $token)->exists());

        return $token;
    }

    private function sendContractSentEmail(Contract $contract): void
    {
        $first = trim((string) $contract->recipient_name);
        $greeting = $first !== '' ? explode(' ', $first)[0] : 'there';

        $this->sendContractMail(
            $contract->recipient_email,
            'Your Employment Contract from Archilance LLC',
            [
                'emoji'        => '📄',
                'heading'      => 'Your Employment Contract',
                'greetingName' => $greeting,
                'intro'        => 'You have received your employment contract from <strong>Archilance LLC</strong>. Please review it carefully and confirm your acceptance using the button below. No login is required.',
                'details'      => [
                    ['label' => 'Contract', 'value' => $contract->title],
                    ['label' => 'Prepared for', 'value' => $contract->recipient_name],
                ],
                'cta'          => [
                    'text' => 'Review & Accept Contract',
                    'url'  => frontendUrl('contract/view/' . $contract->token),
                ],
                'signoff'      => 'If you have any questions, simply reply to this email.',
                'accent'       => '4f46e5',
            ]
        );
    }
}
