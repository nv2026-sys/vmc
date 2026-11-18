<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignJobResourcesRequest;
use App\Http\Requests\CloseDuplicateIncidentRequest;
use App\Http\Requests\CreateIncidentRequest;
use App\Http\Requests\ReplyToDriverRequest;
use App\Http\Requests\TransferIncidentTo112Request;
use App\Http\Requests\UpdateAssignmentStatusRequest;
use App\Models\EmergencyJob;
use App\Models\Ward;
use App\Services\IncidentWorkflow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class IncidentController extends Controller
{
    public function __construct(private readonly IncidentWorkflow $workflow) {}

    public function create(Request $request)
    {
        $user = $request->user();
        $wards = Ward::query()->where('is_active', true);
        if (! $user->hasRole('SUPER_ADMIN') && ! $user->hasRole('CONTROL_ROOM_ADMIN')) {
            $stationIds = $user->fireStations()->pluck('fire_stations.id')
                ->merge($user->managedFireStations()->pluck('id'))
                ->unique();
            $wards->whereHas(
                'fireStations',
                fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
            );
        }

        $search = trim((string) $request->query('caller_search', ''));
        $request->validate(['caller_search' => ['nullable', 'string', 'min:3', 'max:120']]);

        return view('incidents.create', [
            'wards' => $wards->orderBy('name')->get(['id', 'name', 'code']),
            'callerSearch' => $search,
            'callerResults' => $search === '' ? collect() : $this->workflow->callers($user, $search),
        ]);
    }

    public function queue(Request $request, string $state)
    {
        $queues = [
            'pending' => ['Pending incidents', [EmergencyJob::STATUS_RECEIVED, EmergencyJob::STATUS_ASSIGNED]],
            'active' => ['Active incidents', [
                EmergencyJob::STATUS_ASSIGNED,
                EmergencyJob::STATUS_EN_ROUTE,
                EmergencyJob::STATUS_ARRIVED,
                EmergencyJob::STATUS_IN_PROGRESS,
            ]],
            'assigned' => ['Assigned incidents', [EmergencyJob::STATUS_ASSIGNED]],
            'en-route' => ['Units en route', [EmergencyJob::STATUS_EN_ROUTE]],
            'arrived' => ['Units arrived', [EmergencyJob::STATUS_ARRIVED]],
            'in-progress' => ['Incidents in progress', [EmergencyJob::STATUS_IN_PROGRESS]],
            'completed' => ['Completed incidents', [EmergencyJob::STATUS_COMPLETED]],
            'duplicates' => ['Duplicate incidents', [EmergencyJob::STATUS_DUPLICATE]],
            'transferred' => ['Transferred to 112', [EmergencyJob::STATUS_TRANSFERRED_TO_112]],
        ];
        abort_unless(isset($queues[$state]), 404);
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        [$title, $statuses] = $queues[$state];
        $search = trim($validated['search'] ?? '');
        $query = $this->workflow->visibleJobs($request->user())
            ->with('ward:id,name,code')
            ->whereIn('status', $statuses);

        if ($search !== '') {
            $query->where(function (Builder $jobs) use ($search): void {
                foreach (['job_id', 'caller_name', 'caller_phone', 'incident_type', 'society_name', 'incident_address'] as $column) {
                    $jobs->orWhereLike($column, '%'.$search.'%');
                }
            });
        }

        return view('pages.catalog', [
            'kind' => 'jobs',
            'title' => $title,
            'search' => $search,
            'records' => $query->orderByDesc('occurred_at')->paginate(10)->withQueryString(),
            'canManage' => false,
            'canCreate' => $request->user()->hasPermission('jobs.create'),
        ]);
    }

    public function store(CreateIncidentRequest $request)
    {
        $job = $this->workflow->register($request->user(), $request->validated());

        return redirect()->route('app.jobs.show', $job)->with('status', 'Incident registered.');
    }

    public function show(Request $request, EmergencyJob $job)
    {
        $job = $this->workflow->findVisibleJobOrFail($request->user(), $job->id)->load([
            'ward',
            'creator:id,name',
            'callLogs.creator:id,name',
            'assignments.fireStation:id,name,code',
            'assignments.vehicle:id,vehicle_number',
            'assignments.team:id,name',
            'assignments.teamLeader',
            'assignments.assignedDriver:id,name',
            'assignments.assigner:id,name',
            'assignments.actionEvents.actor:id,name',
            'statusHistory.actor:id,name',
            'statusHistory.assignment.vehicle:id,vehicle_number',
            'statusHistory.assignment.assignedDriver:id,name',
            'remarksHistory' => fn ($remarks) => $remarks
                ->with('user:id,name')
                ->orderBy('created_at'),
            'externalReferences.creator:id,name',
        ]);
        $communicationActivity = $job->assignments->flatMap(fn ($assignment) => $assignment->actionEvents
            ->whereIn('action', ['need_help', 'escalated'])
            ->map(fn ($event): array => [
                'type' => $event->action,
                'occurred_at' => $event->occurred_at,
                'assignment_id' => $assignment->id,
                'vehicle_number' => $assignment->vehicle?->vehicle_number,
                'actor' => $event->actor?->name ?? 'Driver unavailable',
                'details' => $event->details ?? [],
            ]));
        $communicationActivity = $communicationActivity->concat(
            $job->statusHistory
                ->filter(fn ($history): bool => trim((string) $history->remarks) !== ''
                    && $history->assignment?->assignedDriver !== null
                    && $history->actor !== null
                    && (int) $history->actor->id !== (int) $history->assignment->assigned_driver_user_id)
                ->map(fn ($history): array => [
                    'type' => 'reply',
                    'occurred_at' => $history->changed_at,
                    'assignment_id' => $history->job_assignment_id,
                    'vehicle_number' => $history->assignment?->vehicle?->vehicle_number,
                    'actor' => $history->actor?->name ?? 'Control room',
                    'details' => ['reply' => $history->remarks],
                ]),
        )->concat(
            $job->remarksHistory
                ->reject(fn ($remark): bool => preg_match(
                    '/^Driver action \[(?:need_help|escalated)\] assignment #\d+:/',
                    $remark->body,
                ) === 1)
                ->map(function ($remark): array {
                    $body = preg_replace('/^Driver action \[[^\]]+\] assignment #\d+:\s*/', '', $remark->body);

                    return [
                        'type' => 'driver_remark',
                        'occurred_at' => $remark->created_at,
                        'assignment_id' => null,
                        'vehicle_number' => null,
                        'actor' => $remark->user?->name ?? 'Driver unavailable',
                        'details' => ['message' => $body ?? $remark->body],
                    ];
                }),
        )->sortBy('occurred_at')->values();
        $canAssign = $request->user()->hasPermission('jobs.assign')
            && in_array($job->status, [EmergencyJob::STATUS_RECEIVED, EmergencyJob::STATUS_ASSIGNED], true);

        return view('incidents.show', [
            'job' => $job,
            'communicationActivity' => $communicationActivity,
            'replyableAssignments' => $job->assignments
                ->filter(fn ($assignment): bool => $request->user()->hasPermission('jobs.update_status')
                    && $assignment->assigned_driver_user_id !== null
                    && (int) $assignment->assigned_driver_user_id !== (int) $request->user()->id
                    && $this->workflow->canUpdateAssignment($request->user(), $assignment))
                ->values(),
            'assignableStations' => $canAssign ? $this->workflow->assignableStations($request->user(), $job) : collect(),
            'canAssign' => $canAssign,
            'updatableAssignmentIds' => $job->assignments
                ->filter(fn ($assignment): bool => $this->workflow->canUpdateAssignment($request->user(), $assignment))
                ->map(fn ($assignment): string => (string) $assignment->getKey())
                ->all(),
            'possibleDuplicates' => $job->status === EmergencyJob::STATUS_RECEIVED
                ? $this->workflow->possibleDuplicates($request->user(), $job->caller_phone, $job->id)
                : collect(),
            'canCloseDuplicate' => $request->user()->hasPermission('jobs.close_duplicate'),
            'canTransferTo112' => $request->user()->hasPermission('jobs.transfer_112'),
            'canArchive' => $request->user()->hasPermission('jobs.archive')
                && $this->workflow->isArchivable($job)
                && ! $job->assignments->contains(fn ($assignment): bool => $assignment->released_at === null),
        ]);
    }

    public function closeDuplicate(CloseDuplicateIncidentRequest $request, EmergencyJob $job)
    {
        $this->workflow->closeAsDuplicate(
            $request->user(),
            $job,
            (int) $request->validated('duplicate_of'),
            $request->validated('remarks'),
        );

        return redirect()->route('app.jobs.show', $job)->with('status', 'Incident closed as a duplicate.');
    }

    public function transferTo112(TransferIncidentTo112Request $request, EmergencyJob $job)
    {
        $this->workflow->transferTo112(
            $request->user(),
            $job,
            $request->validated('reference_number'),
            $request->validated('details'),
        );

        return redirect()->route('app.jobs.show', $job)->with('status', 'Incident transfer to 112 recorded.');
    }

    public function assignResources(AssignJobResourcesRequest $request, EmergencyJob $job)
    {
        $this->workflow->assignResources($request->user(), $job, $request->validated());

        return redirect()->route('app.jobs.show', $job)->with(
            'status',
            'Response resources assigned. Busy resources are queued automatically when eligible.',
        );
    }

    public function updateAssignmentStatus(
        UpdateAssignmentStatusRequest $request,
        EmergencyJob $job,
        int $assignmentId,
    ) {
        $this->workflow->updateAssignmentStatus(
            $request->user(),
            $job,
            $assignmentId,
            $request->validated(),
        );

        return redirect()->route('app.jobs.show', $job)->with('status', 'Unit status updated.');
    }

    public function replyToDriver(ReplyToDriverRequest $request, EmergencyJob $job)
    {
        $validated = $request->validated();
        $this->workflow->replyToDriver(
            $request->user(),
            $job,
            (int) $validated['assignment_id'],
            $validated['reply'],
        );

        return redirect()->to(route('app.jobs.show', $job).'#incident-conversation')
            ->with('status', 'Reply sent to driver.');
    }
}
