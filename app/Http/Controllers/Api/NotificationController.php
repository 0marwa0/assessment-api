<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

// R12: the bell. Everyone sees only their own notifications, newest first.
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = max(1, min(100, $request->integer('limit', 30)));

        $items = $user->notifications()->latest()->limit($limit)->get();

        return response()->json([
            'data' => $items->map(fn (DatabaseNotification $n): array => self::toArray($n))->values(),
            'unread_count' => $user->unreadNotifications()->count(),
            'email_notifications' => $user->email_notifications !== false,
        ]);
    }

    public function read(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        return response()->json([
            'data' => self::toArray($notification),
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread_count' => 0]);
    }

    /** "Settings": the one email preference (design N-Panel). */
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate(['email_notifications' => ['required', 'boolean']]);

        $user = $request->user();
        $user->email_notifications = $validated['email_notifications'];
        $user->save();

        return response()->json(['email_notifications' => (bool) $user->email_notifications]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(DatabaseNotification $n): array
    {
        $data = is_array($n->data) ? $n->data : [];

        return [
            'id' => $n->id,
            'kind' => $data['kind'] ?? 'info',
            'title' => $data['title'] ?? '',
            'text' => $data['text'] ?? '',
            'meta' => $data['meta'] ?? null,
            'link' => $data['link'] ?? null,
            'read_at' => $n->read_at?->toISOString(),
            'created_at' => $n->created_at?->toISOString(),
        ];
    }
}
