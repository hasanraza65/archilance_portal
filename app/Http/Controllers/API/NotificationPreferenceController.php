<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Support\NotificationCategories;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Per-user EMAIL notification preferences. Available to every authenticated user
 * (shared route group). In-app notifications are unaffected — this only governs
 * whether a category's EMAIL is sent.
 */
class NotificationPreferenceController extends Controller
{
    // GET /api/notification-preferences
    public function show()
    {
        $user = Auth::user();

        return response()->json([
            'categories'  => NotificationCategories::all(),
            'preferences' => NotificationCategories::resolve(
                $user ? $user->email_notification_preferences : null
            ),
        ]);
    }

    // PUT /api/notification-preferences   body: { "preferences": { "assignment": true, ... } }
    public function update(Request $request)
    {
        $request->validate([
            'preferences'   => 'required|array',
            'preferences.*' => 'boolean',
        ]);

        $user = Auth::user();
        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $incoming = $request->input('preferences', []);

        // Start from the user's current resolved map, then apply ONLY known
        // category keys (ignoring anything unexpected the client might send).
        $resolved = NotificationCategories::resolve($user->email_notification_preferences);

        foreach (NotificationCategories::keys() as $key) {
            if (array_key_exists($key, $incoming)) {
                $resolved[$key] = filter_var($incoming[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        $user->email_notification_preferences = $resolved;
        $user->save();

        return response()->json([
            'message'     => 'Notification preferences updated.',
            'categories'  => NotificationCategories::all(),
            'preferences' => $resolved,
        ]);
    }
}
