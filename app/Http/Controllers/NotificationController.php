<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\InAppNotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request, InAppNotificationService $notifications)
    {
        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $user = $request->user();
        $limit = $validated['limit'] ?? 20;

        $items = Notification::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Notification $notification) => $this->shape($user, $notification, $notifications))
            ->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'unread_count' => Notification::query()
                    ->where('user_id', $user->id)
                    ->where('is_read', false)
                    ->count(),
            ],
        ]);
    }

    public function markRead(Request $request, InAppNotificationService $notifications, int $notification)
    {
        $item = Notification::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($notification)
            ->firstOrFail();

        if (! $item->is_read) {
            $item->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
        }

        return response()->json([
            'notification' => $this->shape($request->user(), $item->fresh(), $notifications),
        ]);
    }

    public function markAllRead(Request $request)
    {
        Notification::query()
            ->where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'meta' => ['unread_count' => 0],
        ]);
    }

    private function shape($user, Notification $notification, InAppNotificationService $notifications): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type,
            'title' => $notification->title,
            'message' => $notification->message,
            'link' => $notifications->accessibleLink($user, $notification),
            'is_read' => $notification->is_read,
            'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
        ];
    }
}
