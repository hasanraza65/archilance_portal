<?php

namespace App\Http\Controllers\API\employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use App\Models\User;
use App\Models\ProjectTask;
use App\Models\Project;
use App\Models\WorkSession;
use App\Models\InterneeRating;

class InterneeRatingController extends Controller
{
    private const RATING_FIELDS = [
        'technical_accuracy',
        'learning_improvement',
        'ownership_initiative',
        'communication_professionalism',
        'overall_recommendation',
    ];

    // Executives get admin-like visibility over all ratings (same convention used elsewhere in this app)
    private function isElevated($user)
    {
        return $user->employee_type === 'Executive';
    }

    /**
     * Walk parent_task_id up to the root/top-level task.
     * Handles unlimited nesting depth; falls back to the deepest task we could load if a parent is missing.
     */
    private function resolveRootTask($taskId, $tasksById)
    {
        $current = $tasksById->get($taskId);
        $depth = 0;

        while ($current && $current->parent_task_id && $depth < 10) {
            $parent = $tasksById->get($current->parent_task_id);

            if (!$parent) {
                // Parent not loaded yet — fetch it on demand
                $parent = ProjectTask::select('id', 'parent_task_id', 'task_title', 'project_id')
                    ->find($current->parent_task_id);
                if ($parent) {
                    $tasksById->put($parent->id, $parent);
                }
            }

            if (!$parent) break;

            $current = $parent;
            $depth++;
        }

        return $current;
    }

    /**
     * GET /employee/my-internees
     * Interns currently managed by the authenticated user.
     */
    public function myInternees(Request $request)
    {
        $user = Auth::user();

        $internees = User::where('internee_manager_id', $user->id)
            ->where('employee_type', 'Internee')
            ->get(['id', 'name', 'email', 'profile_pic', 'internee_manager_id']);

        return response()->json(['data' => $internees]);
    }

    /**
     * GET /employee/internee-rating-pending-check?date=YYYY-MM-DD
     *
     * Tells the caller (a manager) which internee/task ratings are still
     * outstanding for the given date (defaults to yesterday), so the frontend
     * can gate the "Start Timer" flow behind a grading step-through and resume
     * exactly where the manager left off.
     */
    public function pendingCheck(Request $request)
    {
        $manager = Auth::user();

        $targetDate = $request->filled('date')
            ? Carbon::parse($request->date)->toDateString()
            : Carbon::yesterday()->toDateString();

        $internees = User::where('internee_manager_id', $manager->id)
            ->where('employee_type', 'Internee')
            ->get(['id', 'name', 'email', 'profile_pic']);

        if ($internees->isEmpty()) {
            return response()->json([
                'is_manager' => false,
                'date' => $targetDate,
                'total_internees' => 0,
                'has_pending' => false,
                'total_pending' => 0,
                'next' => null,
                'pending' => [],
            ]);
        }

        $interneeIds = $internees->pluck('id');

        // Sessions that overlap the target date (same overlap logic as User::calculateWorkedTime)
        $sessions = WorkSession::whereIn('user_id', $interneeIds)
            ->whereDate('start_date', '<=', $targetDate)
            ->where(function ($q) use ($targetDate) {
                $q->whereDate('end_date', '>=', $targetDate)
                    ->orWhereNull('end_date')
                    ->orWhereDate('start_date', $targetDate);
            })
            ->get(['user_id', 'task_id']);

        if ($sessions->isEmpty()) {
            return response()->json([
                'is_manager' => true,
                'date' => $targetDate,
                'total_internees' => $internees->count(),
                'has_pending' => false,
                'total_pending' => 0,
                'next' => null,
                'pending' => [],
            ]);
        }

        $taskIds = $sessions->pluck('task_id')->unique()->values();

        $tasksById = ProjectTask::whereIn('id', $taskIds)
            ->get(['id', 'parent_task_id', 'task_title', 'project_id'])
            ->keyBy('id');

        // Resolve every worked task up to its root, building unique (internee, root_task) pairs
        $pairs = collect();
        foreach ($sessions as $session) {
            if (!$tasksById->has($session->task_id)) {
                continue; // task no longer exists
            }

            $root = $this->resolveRootTask($session->task_id, $tasksById);
            if (!$root) continue;

            $key = $session->user_id . '-' . $root->id;
            if (!$pairs->has($key)) {
                $pairs->put($key, [
                    'internee_id' => $session->user_id,
                    'task_id' => $root->id,
                    'task_title' => $root->task_title,
                    'project_id' => $root->project_id,
                ]);
            }
        }

        $projectIds = $pairs->pluck('project_id')->filter()->unique()->values();
        $projectsById = Project::whereIn('id', $projectIds)->get(['id', 'project_name'])->keyBy('id');

        $existingKeys = InterneeRating::where('manager_id', $manager->id)
            ->whereIn('internee_id', $interneeIds)
            ->whereDate('rating_date', $targetDate)
            ->get(['internee_id', 'task_id'])
            ->map(fn($r) => $r->internee_id . '-' . $r->task_id)
            ->flip();

        $interneesById = $internees->keyBy('id');

        $pending = [];
        foreach ($pairs as $key => $pair) {
            if (isset($existingKeys[$key])) {
                continue; // already rated
            }

            $internee = $interneesById->get($pair['internee_id']);
            $project = $projectsById->get($pair['project_id']);

            $pending[] = [
                'internee_id' => $pair['internee_id'],
                'internee_name' => $internee->name ?? null,
                'internee_email' => $internee->email ?? null,
                'internee_profile_pic' => $internee->profile_pic ?? null,
                'task_id' => $pair['task_id'],
                'task_title' => $pair['task_title'],
                'project_id' => $pair['project_id'],
                'project_name' => $project->project_name ?? null,
                'rating_date' => $targetDate,
            ];
        }

        usort($pending, function ($a, $b) {
            return $a['internee_id'] <=> $b['internee_id'] ?: $a['task_id'] <=> $b['task_id'];
        });

        return response()->json([
            'is_manager' => true,
            'date' => $targetDate,
            'total_internees' => $internees->count(),
            'has_pending' => count($pending) > 0,
            'total_pending' => count($pending),
            'next' => $pending[0] ?? null,
            'pending' => $pending,
        ]);
    }

    /**
     * GET /employee/internee-rating
     * Filters: internee_id, manager_id, task_id, project_id, date_from, date_to
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        $query = InterneeRating::with([
            'manager:id,name,email,profile_pic',
            'internee:id,name,email,profile_pic',
            'task:id,task_title,project_id',
            'task.project:id,project_name',
        ]);

        if (!$this->isElevated($user)) {
            $query->where(function ($q) use ($user) {
                $q->where('manager_id', $user->id)
                    ->orWhere('internee_id', $user->id);
            });
        }

        if ($request->filled('internee_id')) {
            $query->where('internee_id', $request->internee_id);
        }
        if ($request->filled('manager_id')) {
            $query->where('manager_id', $request->manager_id);
        }
        if ($request->filled('task_id')) {
            $query->where('task_id', $request->task_id);
        }
        if ($request->filled('project_id')) {
            $query->whereHas('task', function ($q) use ($request) {
                $q->where('project_id', $request->project_id);
            });
        }
        if ($request->filled('date_from')) {
            $query->whereDate('rating_date', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('rating_date', '<=', $request->date_to);
        }

        $ratings = $query->orderBy('rating_date', 'desc')->paginate(20);

        return response()->json($ratings);
    }

    /**
     * POST /employee/internee-rating
     */
    public function store(Request $request)
    {
        $manager = Auth::user();
        $didNotWork = $request->boolean('did_not_work');

        $validator = Validator::make($request->all(), array_merge([
            'internee_id' => 'required|integer|exists:users,id',
            'task_id' => 'required|integer|exists:project_tasks,id',
            'rating_date' => 'required|date|before_or_equal:today',
            'did_not_work' => 'sometimes|boolean',
            'comments' => 'nullable|string',
        ], $this->ratingFieldRules($didNotWork ? 'nullable' : 'required')));

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $internee = User::find($request->internee_id);

        if (!$internee || $internee->employee_type !== 'Internee') {
            return response()->json(['message' => 'Selected user is not an internee.'], 422);
        }

        if (!$this->isElevated($manager) && (int) $internee->internee_manager_id !== (int) $manager->id) {
            return response()->json(['message' => 'You are not the assigned manager for this internee.'], 403);
        }

        // Roll the submitted task up to its root/top-level task, regardless of what was sent
        $tasksById = ProjectTask::select('id', 'parent_task_id', 'task_title', 'project_id')
            ->where('id', $request->task_id)
            ->get()
            ->keyBy('id');

        $rootTask = $this->resolveRootTask($request->task_id, $tasksById);

        if (!$rootTask) {
            return response()->json(['message' => 'Task not found.'], 422);
        }

        $duplicate = InterneeRating::where('manager_id', $manager->id)
            ->where('internee_id', $internee->id)
            ->where('task_id', $rootTask->id)
            ->whereDate('rating_date', $request->rating_date)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'A rating already exists for this internee, task and date.'
            ], 422);
        }

        $rating = InterneeRating::create([
            'manager_id' => $manager->id,
            'internee_id' => $internee->id,
            'task_id' => $rootTask->id,
            'rating_date' => $request->rating_date,
            'did_not_work' => $didNotWork,
            'technical_accuracy' => $didNotWork ? null : $request->technical_accuracy,
            'learning_improvement' => $didNotWork ? null : $request->learning_improvement,
            'ownership_initiative' => $didNotWork ? null : $request->ownership_initiative,
            'communication_professionalism' => $didNotWork ? null : $request->communication_professionalism,
            'overall_recommendation' => $didNotWork ? null : $request->overall_recommendation,
            'comments' => $request->comments,
        ]);

        $rating->load([
            'manager:id,name,email,profile_pic',
            'internee:id,name,email,profile_pic',
            'task:id,task_title,project_id',
            'task.project:id,project_name',
        ]);

        return response()->json([
            'message' => 'Rating submitted successfully.',
            'data' => $rating,
        ], 201);
    }

    /**
     * GET /employee/internee-rating/{id}
     */
    public function show($id)
    {
        $user = Auth::user();

        $rating = InterneeRating::with([
            'manager:id,name,email,profile_pic',
            'internee:id,name,email,profile_pic',
            'task:id,task_title,project_id',
            'task.project:id,project_name',
        ])->findOrFail($id);

        if (
            !$this->isElevated($user)
            && (int) $rating->manager_id !== (int) $user->id
            && (int) $rating->internee_id !== (int) $user->id
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json(['data' => $rating]);
    }

    /**
     * PUT/PATCH /employee/internee-rating/{id}
     */
    public function update(Request $request, $id)
    {
        $manager = Auth::user();
        $rating = InterneeRating::findOrFail($id);

        if (!$this->isElevated($manager) && (int) $rating->manager_id !== (int) $manager->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $didNotWorkProvided = $request->has('did_not_work');
        $didNotWork = $didNotWorkProvided ? $request->boolean('did_not_work') : (bool) $rating->did_not_work;

        $validator = Validator::make($request->all(), array_merge([
            'rating_date' => 'sometimes|required|date|before_or_equal:today',
            'did_not_work' => 'sometimes|boolean',
            'comments' => 'nullable|string',
        ], $this->ratingFieldRules($didNotWork ? 'nullable' : 'sometimes')));

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // If the row is (or is becoming) a real rating, every star field must resolve to a value —
        // either freshly submitted here, or already present on the row.
        if (!$didNotWork) {
            foreach (self::RATING_FIELDS as $field) {
                $resolved = $request->has($field) ? $request->input($field) : $rating->{$field};
                if (is_null($resolved)) {
                    return response()->json([
                        'message' => "The {$field} field is required when did_not_work is false."
                    ], 422);
                }
            }
        }

        $newDate = $request->filled('rating_date') ? $request->rating_date : $rating->rating_date->toDateString();

        $duplicate = InterneeRating::where('manager_id', $rating->manager_id)
            ->where('internee_id', $rating->internee_id)
            ->where('task_id', $rating->task_id)
            ->whereDate('rating_date', $newDate)
            ->where('id', '!=', $rating->id)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'Another rating already exists for this internee, task and date.'
            ], 422);
        }

        $updateData = $request->only(array_merge(self::RATING_FIELDS, ['rating_date', 'comments']));

        if ($didNotWorkProvided) {
            $updateData['did_not_work'] = $didNotWork;
        }

        if ($didNotWork) {
            foreach (self::RATING_FIELDS as $field) {
                $updateData[$field] = null;
            }
        }

        $rating->fill($updateData);
        $rating->save();

        $rating->load([
            'manager:id,name,email,profile_pic',
            'internee:id,name,email,profile_pic',
            'task:id,task_title,project_id',
            'task.project:id,project_name',
        ]);

        return response()->json([
            'message' => 'Rating updated successfully.',
            'data' => $rating,
        ]);
    }

    /**
     * DELETE /employee/internee-rating/{id}
     */
    public function destroy($id)
    {
        $manager = Auth::user();
        $rating = InterneeRating::findOrFail($id);

        if (!$this->isElevated($manager) && (int) $rating->manager_id !== (int) $manager->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $rating->delete();

        return response()->json(['message' => 'Rating deleted successfully.']);
    }

    /**
     * @param string $mode 'required' (default, store), 'sometimes' (partial update),
     *                      or 'nullable' (did_not_work — stars are irrelevant)
     */
    private function ratingFieldRules($mode = 'required')
    {
        $prefix = match ($mode) {
            'sometimes' => 'sometimes|required',
            'nullable' => 'nullable',
            default => 'required',
        };
        $rules = [];
        foreach (self::RATING_FIELDS as $field) {
            $rules[$field] = $prefix . '|integer|min:1|max:5';
        }
        return $rules;
    }
}
