<?php

namespace App\Http\Controllers\API\admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Note;
use Validator;
use Auth;

class NoteController extends Controller
{
    /**
     * Display a listing of notes
     */
    public function index(Request $request)
    {
        $notes = Note::latest()->paginate(10);

        return response()->json([
            'status' => true,
            'message' => 'Notes fetched successfully',
            'data' => $notes
        ], 200);
    }

    /**
     * Store a newly created note
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'project_id' => 'nullable|integer',
            'type' => 'nullable|string',
            'note_text' => 'required', // we’ll validate inside
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = Auth::user()->id;

        // ✅ If note_text is array (multiple notes)
        if (is_array($request->note_text)) {

            $notesData = [];

            foreach ($request->note_text as $text) {
                if (!empty($text)) {
                    $notesData[] = [
                        'user_id' => $userId,
                        'project_id' => $request->project_id,
                        'note_text' => $text,
                        'status' => 0,
                        'type' => $request->type,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            // Bulk insert
            Note::insert($notesData);

            return response()->json([
                'status' => true,
                'message' => 'Notes created successfully',
                'count' => count($notesData)
            ], 201);
        }

        // ✅ Single note (existing behavior)
        $note = Note::create([
            'user_id' => $userId,
            'project_id' => $request->project_id,
            'note_text' => $request->note_text,
            'status' => 0,
            'type' => $request->type
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Note created successfully',
            'data' => $note
        ], 201);
    }

    public function bulkStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'notes' => 'required|array|min:1',
            'notes.*.note_text' => 'required|string',
            'notes.*.project_id' => 'nullable|integer',
            'notes.*.type' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $userId = Auth::id();

        $notesData = [];

        foreach ($request->notes as $note) {
            $notesData[] = [
                'user_id' => $userId,
                'project_id' => $note['project_id'] ?? null,
                'note_text' => $note['note_text'],
                'status' => 0,
                'type' => $note['type'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        Note::insert($notesData);

        return response()->json([
            'status' => true,
            'message' => 'Notes created successfully',
            'count' => count($notesData)
        ], 201);
    }

    /**
     * Display the specified note
     */
    public function show($id)
    {
        $note = Note::find($id);

        if (!$note) {
            return response()->json([
                'status' => false,
                'message' => 'Note not found'
            ], 404);
        }

        return response()->json([
            'status' => true,
            'data' => $note
        ], 200);
    }

    /**
     * Update the specified note
     */
    public function update(Request $request, $id)
    {
        $note = Note::find($id);

        if (!$note) {
            return response()->json([
                'status' => false,
                'message' => 'Note not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'note_text' => 'required|string',
            'project_id' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $note->update([
            'project_id' => $request->project_id,
            'note_text' => $request->note_text
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Note updated successfully',
            'data' => $note
        ], 200);
    }

    /**
     * Remove the specified note
     */
    public function destroy($id)
    {
        $note = Note::find($id);

        if (!$note) {
            return response()->json([
                'status' => false,
                'message' => 'Note not found'
            ], 404);
        }

        $note->delete();

        return response()->json([
            'status' => true,
            'message' => 'Note deleted successfully'
        ], 200);
    }

    /**
     * Update note status (Custom API)
     */
    public function updateStatus(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'note_id' => 'required|integer|exists:notes,id',
            'status' => 'required|integer|in:0,1'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $note = Note::find($request->note_id);



        $note->update([
            'status' => $request->status
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Status updated successfully',
            'data' => $note
        ], 200);
    }
}
