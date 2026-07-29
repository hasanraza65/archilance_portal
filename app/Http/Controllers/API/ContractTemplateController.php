<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ContractTemplate;
use App\Support\ContractVariables;
use App\Traits\AuthorizesContractManagers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class ContractTemplateController extends Controller
{
    use AuthorizesContractManagers;

    /**
     * GET /api/contract-variables
     * The placeholder catalog for the editor sidebar + the auto/default values
     * (recipient fields blank here; the send flow merges the picked employee in).
     */
    public function variables()
    {
        $this->authorizeContractManager();

        return response()->json([
            'catalog'  => ContractVariables::catalog(),
            'defaults' => ContractVariables::resolveDefaults(null),
        ]);
    }

    /** GET /api/contract-templates */
    public function index(Request $request)
    {
        $this->authorizeContractManager();

        $query = ContractTemplate::query()->latest('id');

        if ($request->filled('search')) {
            $like = '%' . trim((string) $request->input('search')) . '%';
            $query->where('title', 'like', $like);
        }

        // Small dataset — return the full list (newest first).
        return response()->json($query->get());
    }

    /** POST /api/contract-templates */
    public function store(Request $request)
    {
        $this->authorizeContractManager();

        $validator = Validator::make($request->all(), [
            'title'     => 'required|string|max:255',
            'body'      => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $template = ContractTemplate::create([
            'title'      => $request->title,
            'body'       => $request->body ?? '',
            'is_active'  => $request->has('is_active') ? (bool) $request->boolean('is_active') : true,
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'message' => 'Template created successfully.',
            'data'    => $template,
        ], 201);
    }

    /** GET /api/contract-templates/{id} */
    public function show($id)
    {
        $this->authorizeContractManager();

        return response()->json(ContractTemplate::findOrFail($id));
    }

    /** PUT/PATCH /api/contract-templates/{id} */
    public function update(Request $request, $id)
    {
        $this->authorizeContractManager();

        $template = ContractTemplate::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title'     => 'sometimes|required|string|max:255',
            'body'      => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($request->has('title'))     $template->title = $request->title;
        if ($request->has('body'))      $template->body = $request->body ?? '';
        if ($request->has('is_active')) $template->is_active = (bool) $request->boolean('is_active');

        $template->save();

        return response()->json([
            'message' => 'Template updated successfully.',
            'data'    => $template,
        ]);
    }

    /** DELETE /api/contract-templates/{id} */
    public function destroy($id)
    {
        $this->authorizeContractManager();

        $template = ContractTemplate::findOrFail($id);
        $template->delete(); // soft delete — generated contracts keep their own body snapshot

        return response()->json(['message' => 'Template deleted successfully.']);
    }
}
