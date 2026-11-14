<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function latest(Request $request): JsonResponse
    {
        $user = $request->user();
        $notifications = $user->notifications()->latest()->limit(10)->get();

        return response()->json([
            'unread' => $user->unreadNotifications()->count(),
            'notifications' => $notifications->map(fn (DatabaseNotification $notification): array => [
                'id' => $notification->id,
                'event' => $notification->data['event'] ?? $notification->type,
                'job_id' => $notification->data['job_id'] ?? null,
                'vehicle_number' => $notification->data['vehicle_number'] ?? null,
                'message' => $notification->data['message'] ?? null,
                'reply' => $notification->data['reply'] ?? null,
                'previous_status' => $notification->data['previous_status'] ?? null,
                'new_status' => $notification->data['new_status'] ?? null,
                'created_at' => $notification->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function index(Request $request): View
    {
        return view('notifications.index', [
            'notifications' => $request->user()->notifications()
                ->latest()
                ->paginate(20),
            'unreadCount' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $notificationId): RedirectResponse
    {
        $notification = $request->user()->notifications()->whereKey($notificationId)->firstOrFail();
        $notification->markAsRead();

        return redirect()->route('notifications.index')->with('status', 'Notification marked as read.');
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return redirect()->route('notifications.index')->with('status', 'All notifications marked as read.');
    }
}
