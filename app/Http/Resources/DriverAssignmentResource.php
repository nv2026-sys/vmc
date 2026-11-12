<?php

namespace App\Http\Resources;

use App\Models\JobAssignment;
use App\Models\JobStatusHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var JobAssignment $assignment */
        $assignment = $this->resource;
        $job = $assignment->emergencyJob;
        $vehicle = $assignment->vehicle;
        $gps = $vehicle?->latestGpsLog;
        $history = JobStatusHistory::query()
            ->where('job_assignment_id', $assignment->id)
            ->orderBy('changed_at')
            ->get();
        $firstGps = $assignment->gpsLogs()->oldest('recorded_at')->first();
        $events = $assignment->actionEvents->sortBy('occurred_at')->values();
        $escalation = $events->where('action', 'escalated')->last();
        $helpRequest = $events->where('action', 'need_help')->last();
        $issueReport = $events->where('action', 'reported_issues')->last();
        $closeEvent = $events->where('action', 'closed')->last();
        $issueDetails = $issueReport?->details ?? [];

        return [
            'id' => $job->job_id,
            'assignmentId' => (int) $assignment->id,
            'emergencyType' => $job->incident_type,
            'title' => $job->society_name ?: $job->incident_address,
            'location' => $job->incident_address,
            'startingLocation' => $assignment->fireStation?->name,
            'destinationLocation' => $job->incident_address,
            'route' => null,
            'distanceKm' => null,
            'callerName' => $job->caller_name,
            'callerMobile' => $job->caller_phone,
            'receivedAt' => $job->occurred_at?->toIso8601String(),
            'startedAt' => $history->firstWhere('new_status', JobAssignment::STATUS_EN_ROUTE)?->changed_at?->toIso8601String(),
            'reachedAt' => $history->firstWhere('new_status', JobAssignment::STATUS_ARRIVED)?->changed_at?->toIso8601String(),
            'completedAt' => $history->firstWhere('new_status', JobAssignment::STATUS_COMPLETED)?->changed_at?->toIso8601String(),
            'status' => $assignment->status,
            'declineReason' => $assignment->cancellation_reason,
            'escalationReason' => $escalation?->details['reason'] ?? null,
            'needHelpReason' => $helpRequest?->details['reason'] ?? null,
            'helpType' => $helpRequest?->details['help_type'] ?? null,
            'helpComments' => $helpRequest?->details['comments'] ?? null,
            'selectedIssues' => $issueDetails['selected_issues'] ?? null,
            'issueRemarks' => $issueDetails['issue_remarks'] ?? null,
            'closeReason' => $closeEvent?->details['reason'] ?? null,
            'closedAt' => $closeEvent?->occurred_at?->toIso8601String(),
            'actionHistory' => $events->map(fn ($event): array => [
                'action' => $event->action,
                'details' => $event->details,
                'occurredAt' => $event->occurred_at?->toIso8601String(),
            ])->all(),
            'currentSpeedKmh' => $gps?->speed_kph === null ? null : (float) $gps->speed_kph,
            'expectedReachTime' => null,
            'startLatitude' => $firstGps?->latitude === null ? null : (float) $firstGps->latitude,
            'startLongitude' => $firstGps?->longitude === null ? null : (float) $firstGps->longitude,
            'destLatitude' => $job->latitude === null ? null : (float) $job->latitude,
            'destLongitude' => $job->longitude === null ? null : (float) $job->longitude,
            'latitude' => $gps?->latitude === null
                ? ($vehicle?->latitude === null ? null : (float) $vehicle->latitude)
                : (float) $gps->latitude,
            'longitude' => $gps?->longitude === null
                ? ($vehicle?->longitude === null ? null : (float) $vehicle->longitude)
                : (float) $gps->longitude,
        ];
    }
}
