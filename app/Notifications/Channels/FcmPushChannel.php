<?php

namespace App\Notifications\Channels;

use App\Jobs\SendFcmNotification;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class FcmPushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notifiable instanceof User) {
            throw new InvalidArgumentException('FCM notifications can only be sent to users.');
        }

        $deviceIds = $notifiable->devices()->pluck('id');
        if ($deviceIds->isEmpty()) {
            return;
        }

        if (! config('services.firebase.project_id') || ! config('services.firebase.credentials')) {
            Log::warning('FCM delivery skipped because Firebase credentials are not configured.', [
                'user_id' => $notifiable->id,
            ]);

            return;
        }

        $notificationData = $notification->toDatabase($notifiable);
        $event = (string) ($notificationData['event'] ?? 'notification');
        $screen = isset($notificationData['vehicle_id'])
            ? 'vehicle_detail'
            : (isset($notificationData['job_id']) || isset($notificationData['incident_id'])
                || isset($notificationData['assignment_id'])
                ? 'incident_detail'
                : 'notifications');
        $data = [
            'event' => $event,
            'screen' => $screen,
        ];

        foreach (['job_id', 'incident_id', 'assignment_id', 'vehicle_id'] as $key) {
            if (isset($notificationData[$key]) && is_scalar($notificationData[$key])) {
                $data[$key] = (string) $notificationData[$key];
            }
        }

        $titles = [
            'dispatch.assigned' => 'New dispatch assignment',
            'assignment.queued' => 'Assignment queued',
            'assignment.status_changed' => 'Assignment updated',
            'assignment.remark_reply' => 'New incident update',
            'driver_assignment.escalated' => 'Driver escalation',
            'driver_assignment.need_help' => 'Driver requested assistance',
            'driver_assignment.reported_issues' => 'Incident issues reported',
            'vehicle.operational_status_changed' => 'Vehicle status changed',
        ];
        $title = $titles[$event] ?? 'Emergency dispatch update';
        $body = is_string($notificationData['message'] ?? null)
            ? $notificationData['message']
            : $title;
        $message = [
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ];

        foreach ($deviceIds as $deviceId) {
            SendFcmNotification::dispatch((int) $deviceId, $message)->afterCommit();
        }
    }
}
