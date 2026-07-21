<?php

namespace App\Traits;

use App\Models\ProjectTask;

/**
 * Adds a computed `has_urgent_descendant` flag to tasks so a parent row can
 * signal that an Urgent task is nested somewhere below it — without loading the
 * whole subtree. Works off a single lightweight query of the project's tasks
 * (id, parent_task_id, priority) and an in-memory ancestor walk (no recursive
 * SQL, no extra packages).
 */
trait AnnotatesTaskUrgency
{
    /**
     * Ids (keyed for O(1) lookup) of tasks in $projectId that have at least one
     * Urgent descendant somewhere below them.
     */
    protected function urgentAncestorSet($projectId): array
    {
        if (empty($projectId)) {
            return [];
        }

        $all = ProjectTask::where('project_id', $projectId)
            ->get(['id', 'parent_task_id', 'priority']);

        $parentOf = [];
        $urgent = [];
        foreach ($all as $t) {
            $parentOf[$t->id] = $t->parent_task_id;
            if (strcasecmp((string) $t->priority, 'Urgent') === 0) {
                $urgent[] = $t->id;
            }
        }

        $ancestors = [];
        foreach ($urgent as $uid) {
            $p = $parentOf[$uid] ?? null;
            $guard = 0; // cycle/depth guard
            while ($p !== null && !isset($ancestors[$p]) && $guard < 1000) {
                $ancestors[$p] = true;
                $p = $parentOf[$p] ?? null;
                $guard++;
            }
        }

        return $ancestors;
    }

    /**
     * Set `has_urgent_descendant` on each task (and any already-loaded subTasks,
     * recursively) using a precomputed ancestor set.
     */
    protected function annotateUrgency($tasks, array $ancestorSet): void
    {
        if (!$tasks) {
            return;
        }
        foreach ($tasks as $t) {
            if (!$t) {
                continue;
            }
            $t->has_urgent_descendant = isset($ancestorSet[$t->id]);
            if ($t->relationLoaded('subTasks') && $t->subTasks) {
                $this->annotateUrgency($t->subTasks, $ancestorSet);
            }
        }
    }
}
