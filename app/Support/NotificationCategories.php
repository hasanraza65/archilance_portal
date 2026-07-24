<?php

namespace App\Support;

/**
 * Single source of truth for the categories of EMAIL notifications a user can
 * toggle on/off. Both the preferences API and the send-time enforcement read
 * from here, so adding a category is a one-line change (plus wiring the send
 * site to pass its key).
 *
 * Default is OPT-OUT: a category the user has never touched is treated as
 * enabled, so existing behaviour (everyone gets emails) is preserved until they
 * change something.
 */
class NotificationCategories
{
    /**
     * @return array<int, array{key:string,label:string,description:string,group:string}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'assignment',
                'label' => 'Assignments',
                'description' => "When you're assigned to a job, project, or task.",
                'group' => 'Work',
            ],
            [
                'key' => 'status_change',
                'label' => 'Status updates',
                'description' => "When the status changes on a job, project, or task you're assigned to.",
                'group' => 'Work',
            ],
            [
                'key' => 'comment',
                'label' => 'Comments & mentions',
                'description' => 'When someone comments on, or @mentions you in, a task.',
                'group' => 'Work',
            ],
            [
                'key' => 'due_reminder',
                'label' => 'Deadline reminders',
                'description' => 'Daily digest for tasks with an approaching or overdue due date.',
                'group' => 'Reminders',
            ],
            [
                'key' => 'chat_message',
                'label' => 'Direct messages',
                'description' => 'When someone sends you a 1-to-1 chat message.',
                'group' => 'Messages',
            ],
            [
                'key' => 'project_message',
                'label' => 'Project chat',
                'description' => "When a new message is posted in a job's chat you're part of.",
                'group' => 'Messages',
            ],
        ];
    }

    /**
     * Just the valid keys.
     *
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    /**
     * Resolve a user's stored preferences into a COMPLETE map (every category
     * present), filling any missing category with its default (enabled).
     *
     * @param  array|null  $stored
     * @return array<string, bool>
     */
    public static function resolve($stored): array
    {
        $stored = is_array($stored) ? $stored : [];
        $resolved = [];
        foreach (self::keys() as $key) {
            $resolved[$key] = array_key_exists($key, $stored)
                ? (bool) $stored[$key]
                : true; // opt-out default
        }
        return $resolved;
    }
}
