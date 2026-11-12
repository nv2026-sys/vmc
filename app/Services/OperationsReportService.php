<?php

namespace App\Services;

use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\JobAssignment;
use App\Models\JobStatusHistory;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OperationsReportService
{
    public function report(User $actor, array $filters, bool $preserveApiResponseMetrics = false): array
    {
        $from = Carbon::createFromFormat('Y-m-d', $filters['from'])->startOfDay();
        $to = Carbon::createFromFormat('Y-m-d', $filters['to'])->endOfDay();
        $stations = $this->visibleStations($actor);
        $stationId = isset($filters['station_id']) ? (int) $filters['station_id'] : null;

        if ($stationId !== null && ! $stations->contains('id', $stationId)) {
            throw ValidationException::withMessages([
                'station_id' => 'The selected station is outside your access scope.',
            ]);
        }

        $jobs = $this->filteredJobs($actor, $filters, $stations->modelKeys(), $from, $to);

        if ($stationId !== null) {
            $jobs->where(function (Builder $query) use ($stationId): void {
                $query->whereHas(
                    'ward.fireStations',
                    fn (Builder $query) => $query->where('fire_stations.id', $stationId),
                )->orWhereHas(
                    'assignments',
                    fn (Builder $query) => $query->where('fire_station_id', $stationId),
                );
            });
        }

        $total = (clone $jobs)->count();
        $statusCounts = (clone $jobs)
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $priorityCounts = (clone $jobs)
            ->selectRaw('priority, COUNT(*) AS aggregate')
            ->groupBy('priority')
            ->orderByDesc('aggregate')
            ->pluck('aggregate', 'priority');
        $typeCounts = (clone $jobs)
            ->selectRaw('incident_type, COUNT(*) AS aggregate')
            ->groupBy('incident_type')
            ->orderByDesc('aggregate')
            ->pluck('aggregate', 'incident_type');
        $reportDateExpression = DB::connection()->getDriverName() === 'sqlsrv'
            ? 'CONVERT(varchar(10), occurred_at, 23)'
            : 'DATE(occurred_at)';
        $dailyCounts = (clone $jobs)
            ->selectRaw($reportDateExpression.' AS report_date, COUNT(*) AS aggregate')
            ->groupByRaw($reportDateExpression)
            ->orderBy('report_date')
            ->pluck('aggregate', 'report_date');
        $responseStats = $this->responseStats($jobs, $preserveApiResponseMetrics);
        $pendingStatuses = $preserveApiResponseMetrics
            ? [EmergencyJob::STATUS_RECEIVED, EmergencyJob::STATUS_ASSIGNED]
            : $this->pendingStatuses();
        $stationWorkload = $this->stationWorkload($jobs, $stations);
        $scopeJobs = $this->visibleJobs($actor, $stations->modelKeys());
        $wardOptions = $this->visibleWards($actor, $stations->modelKeys());
        $vehicleOptions = $this->visibleVehicles($actor, $stations->modelKeys());
        $incidentTypes = (clone $scopeJobs)->select('incident_type')->distinct()->orderBy('incident_type')->pluck('incident_type');
        $priorityOptions = (clone $scopeJobs)->select('priority')->distinct()->orderBy('priority')->pluck('priority');
        $statusOptions = (clone $scopeJobs)->select('status')->distinct()->orderBy('status')->pluck('status');
        $assignmentStationIds = $stationId !== null || ! $this->canViewAllStations($actor)
            ? ($stationId !== null ? [$stationId] : $stations->modelKeys())
            : null;
        $incidentRecords = $this->incidentQuery($jobs)
            ->with($this->reportRelations($assignmentStationIds))
            ->orderByDesc('occurred_at')
            ->get();
        $recordMetrics = $incidentRecords->mapWithKeys(fn (EmergencyJob $job): array => [
            $job->id => $this->recordMetrics($job),
        ]);
        $wardReports = $this->wardReports($incidentRecords, $recordMetrics);
        $typeReports = $this->typeReports($incidentRecords, $recordMetrics, $total);
        $priorityReports = $this->priorityReports($incidentRecords);
        $vehicleReports = $this->vehicleReports($incidentRecords);
        $responseReports = $incidentRecords->map(fn (EmergencyJob $job): array => [
            'id' => (int) $job->id,
            'job_id' => $job->job_id,
            'ward' => $job->ward?->name,
            'type' => str_replace('_', ' ', $job->incident_type),
            'details' => $job->remarks,
            'location' => $job->incident_address,
            'caller_name' => $job->caller_name,
            'caller_phone' => $job->caller_phone,
            'priority' => strtoupper($job->priority),
            'status' => strtoupper($job->status),
            'created_at' => $job->occurred_at,
            ...$recordMetrics[$job->id],
            'vehicle_number' => $job->assignments->sortByDesc('assigned_at')->first()?->vehicle?->vehicle_number,
            'vehicle_status' => $job->assignments->sortByDesc('assigned_at')->first()?->status,
            'elapsed_seconds' => max(0, (int) $job->occurred_at?->diffInSeconds(now())),
            'view_url' => route('app.jobs.show', $job),
        ])->values()->all();

        return [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'station_id' => $stationId,
                'ward_id' => isset($filters['ward_id']) ? (int) $filters['ward_id'] : null,
                'area' => trim($filters['area'] ?? ''),
                'incident_type' => $filters['incident_type'] ?? '',
                'priority' => $filters['priority'] ?? '',
                'status' => $filters['status'] ?? '',
                'vehicle_id' => isset($filters['vehicle_id']) ? (int) $filters['vehicle_id'] : null,
                'caller' => trim($filters['caller'] ?? ''),
            ],
            'summary' => [
                'total_jobs' => $total,
                'pending_jobs' => array_sum(array_map(
                    fn (string $status): int => (int) ($statusCounts[$status] ?? 0),
                    $pendingStatuses,
                )),
                'active_jobs' => (int) ($statusCounts[EmergencyJob::STATUS_EN_ROUTE] ?? 0)
                    + (int) ($statusCounts[EmergencyJob::STATUS_ARRIVED] ?? 0)
                    + (int) ($statusCounts[EmergencyJob::STATUS_IN_PROGRESS] ?? 0),
                'completed_jobs' => (int) ($statusCounts[EmergencyJob::STATUS_COMPLETED] ?? 0),
                'duplicate_jobs' => (int) ($statusCounts[EmergencyJob::STATUS_DUPLICATE] ?? 0),
                'transferred_jobs' => (int) ($statusCounts[EmergencyJob::STATUS_TRANSFERRED_TO_112] ?? 0),
                'critical_jobs' => (int) ($priorityCounts['CRITICAL'] ?? 0),
                'high_jobs' => (int) ($priorityCounts['HIGH'] ?? 0),
                'resolved_jobs' => (int) ($statusCounts[EmergencyJob::STATUS_COMPLETED] ?? 0),
                'completion_rate' => $total === 0
                    ? 0
                    : round(((int) ($statusCounts[EmergencyJob::STATUS_COMPLETED] ?? 0)) / $total * 100, 1),
                'average_response_minutes' => $responseStats['average_response_minutes'],
                'average_resolution_minutes' => $responseStats['average_resolution_minutes'],
                'jobs_with_response_time' => $responseStats['jobs_with_response_time'],
                'jobs_with_resolution_time' => $responseStats['jobs_with_resolution_time'],
            ],
            'daily' => $this->dailySeries($from, $to, $dailyCounts),
            'statuses' => $this->distribution($statusCounts, $total),
            'priorities' => $this->distribution($priorityCounts, $total),
            'incident_types' => $this->distribution($typeCounts, $total),
            'stations' => $stationWorkload,
            'wards' => $wardReports,
            'ward_incidents' => $this->wardReportsFiltered($incidentRecords),
            'type_reports' => $typeReports,
            'priority_reports' => $priorityReports,
            'vehicle_reports' => $vehicleReports,
            'response_reports' => $responseReports,
            'incident_types_available' => $incidentTypes->all(),
            'priorities_available' => $priorityOptions->all(),
            'statuses_available' => $statusOptions->all(),
            'wards_available' => $wardOptions->map(fn (Ward $ward): array => ['id' => (int) $ward->id, 'name' => $ward->name])->values()->all(),
            'vehicles_available' => $vehicleOptions->map(fn (Vehicle $vehicle): array => ['id' => (int) $vehicle->id, 'vehicle_number' => $vehicle->vehicle_number])->values()->all(),
            'stations_available' => $stations->map(fn (FireStation $station): array => [
                'id' => (int) $station->id,
                'name' => $station->name,
                'code' => $station->code,
            ])->values()->all(),
        ];
    }

    public function incidentTable(User $actor, array $filters): array
    {
        $stations = $this->visibleStations($actor);
        $from = Carbon::createFromFormat('Y-m-d', $filters['from'])->startOfDay();
        $to = Carbon::createFromFormat('Y-m-d', $filters['to'])->endOfDay();
        $stationId = isset($filters['station_id']) ? (int) $filters['station_id'] : null;
        if ($stationId !== null && ! $stations->contains('id', $stationId)) {
            throw ValidationException::withMessages([
                'station_id' => 'The selected station is outside your access scope.',
            ]);
        }

        $jobs = $this->filteredJobs($actor, $filters, $stations->modelKeys(), $from, $to);
        $recordsTotal = (clone $jobs)->count();
        $search = trim($filters['search'] ?? '');
        if ($search !== '') {
            $jobs->where(function (Builder $query) use ($search): void {
                foreach (['job_id', 'incident_type', 'remarks', 'incident_address', 'society_name', 'caller_name', 'caller_phone', 'priority', 'status'] as $column) {
                    $query->orWhereLike($column, '%'.$search.'%');
                }
                $query->orWhereHas('ward', fn (Builder $ward) => $ward->whereLike('name', '%'.$search.'%'))
                    ->orWhereHas('assignments.vehicle', fn (Builder $vehicle) => $vehicle->whereLike('vehicle_number', '%'.$search.'%'));
            });
        }

        $recordsFiltered = (clone $jobs)->count();
        $orderColumns = [
            1 => 'job_id',
            2 => 'occurred_at',
            3 => 'incident_type',
            4 => 'remarks',
            5 => 'incident_address',
            7 => 'caller_name',
            8 => 'caller_phone',
            9 => 'priority',
            12 => 'status',
        ];
        $orderColumn = (int) ($filters['order_column'] ?? 2);
        if (isset($orderColumns[$orderColumn])) {
            $jobs->orderBy($orderColumns[$orderColumn], $filters['order_dir'] ?? 'desc');
        } elseif ($orderColumn === 6) {
            $jobs->orderBy(
                Ward::query()->select('name')->whereColumn('wards.id', 'emergency_jobs.ward_id'),
                $filters['order_dir'] ?? 'asc',
            );
        } elseif ($orderColumn === 10) {
            $jobs->orderBy(
                Vehicle::query()->select('vehicle_number')
                    ->join('job_assignments', 'job_assignments.vehicle_id', '=', 'vehicles.id')
                    ->whereColumn('job_assignments.emergency_job_id', 'emergency_jobs.id')
                    ->orderBy('job_assignments.assigned_at')
                    ->limit(1),
                $filters['order_dir'] ?? 'asc',
            );
        } elseif ($orderColumn === 11) {
            $jobs->orderBy(
                JobAssignment::query()->select('status')
                    ->whereColumn('job_assignments.emergency_job_id', 'emergency_jobs.id')
                    ->orderByDesc('job_assignments.assigned_at')
                    ->limit(1),
                $filters['order_dir'] ?? 'asc',
            );
        } else {
            $jobs->orderByDesc('occurred_at');
        }

        $assignmentStationIds = $stationId !== null || ! $this->canViewAllStations($actor)
            ? ($stationId !== null ? [$stationId] : $stations->modelKeys())
            : null;
        $records = $this->incidentQuery($jobs)
            ->with($this->reportRelations($assignmentStationIds))
            ->skip((int) ($filters['start'] ?? 0))
            ->take((int) ($filters['length'] ?? 25))
            ->get();

        return [
            'draw' => (int) ($filters['draw'] ?? 0),
            'recordsTotal' => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data' => $records->values()->map(fn (EmergencyJob $job, int $index): array => $this->tableRow(
                $job,
                (int) ($filters['start'] ?? 0) + $index + 1,
            ))->all(),
        ];
    }

    private function tableRow(EmergencyJob $job, int $number): array
    {
        $metrics = $this->recordMetrics($job);
        $assignment = $job->assignments->sortByDesc('assigned_at')->first();

        return [
            'number' => $number,
            'job_id' => $job->job_id,
            'occurred_at' => $job->occurred_at?->format('Y-m-d H:i:s') ?? '--',
            'type' => str_replace('_', ' ', $job->incident_type),
            'details' => $job->remarks,
            'location' => $job->incident_address,
            'ward' => $job->ward?->name,
            'caller_name' => $job->caller_name,
            'caller_phone' => $job->caller_phone,
            'priority' => strtoupper($job->priority),
            'vehicle_number' => $assignment?->vehicle?->vehicle_number,
            'vehicle_status' => $assignment?->status,
            'status' => strtoupper($job->status),
            'pickup_minutes' => $metrics['pickup_minutes'],
            'response_minutes' => $metrics['response_minutes'],
            'elapsed_seconds' => max(0, (int) $job->occurred_at?->diffInSeconds(now())),
            'resolution_minutes' => $metrics['resolution_minutes'],
            'view_url' => route('app.jobs.show', $job),
        ];
    }

    private function filteredJobs(User $actor, array $filters, array $stationIds, Carbon $from, Carbon $to): Builder
    {
        $jobs = $this->visibleJobs($actor, $stationIds)->whereBetween('occurred_at', [$from, $to]);
        $stationId = isset($filters['station_id']) ? (int) $filters['station_id'] : null;
        if ($stationId !== null) {
            $jobs->where(fn (Builder $query) => $query
                ->whereHas('ward.fireStations', fn (Builder $stations) => $stations->where('fire_stations.id', $stationId))
                ->orWhereHas('assignments', fn (Builder $assignments) => $assignments->where('fire_station_id', $stationId)));
        }

        if (isset($filters['ward_id']) && $filters['ward_id'] !== '') {
            $wardId = (int) $filters['ward_id'];
            if (! $this->visibleWards($actor, $stationIds)->contains('id', $wardId)) {
                throw ValidationException::withMessages(['ward_id' => 'The selected ward is outside your access scope.']);
            }
            $jobs->where('ward_id', $wardId);
        }
        if (! empty($filters['area'])) {
            $area = trim($filters['area']);
            $jobs->where(fn (Builder $query) => $query
                ->whereLike('incident_address', '%'.$area.'%')
                ->orWhereLike('society_name', '%'.$area.'%'));
        }
        if (! empty($filters['incident_type'])) {
            $jobs->where('incident_type', $filters['incident_type']);
        }
        if (! empty($filters['priority'])) {
            $jobs->where('priority', $filters['priority']);
        }
        if (! empty($filters['status'])) {
            $jobs->where('status', $filters['status']);
        }
        if (isset($filters['vehicle_id']) && $filters['vehicle_id'] !== '') {
            $vehicleId = (int) $filters['vehicle_id'];
            if (! $this->visibleVehicles($actor, $stationIds)->contains('id', $vehicleId)) {
                throw ValidationException::withMessages(['vehicle_id' => 'The selected vehicle is outside your access scope.']);
            }
            $jobs->whereHas('assignments', fn (Builder $assignments) => $assignments->where('vehicle_id', $vehicleId));
        }
        if (! empty($filters['caller'])) {
            $caller = trim($filters['caller']);
            $jobs->where(fn (Builder $query) => $query
                ->whereLike('caller_name', '%'.$caller.'%')
                ->orWhereLike('caller_phone', '%'.$caller.'%'));
        }

        return $jobs;
    }

    private function incidentQuery(Builder $jobs): Builder
    {
        return $jobs->select('emergency_jobs.*');
    }

    private function reportRelations(?array $stationIds): array
    {
        return [
            'ward:id,name',
            'assignments' => function ($assignments) use ($stationIds): void {
                if ($stationIds !== null) {
                    $assignments->whereIn('fire_station_id', $stationIds);
                }
                $assignments->with([
                    'vehicle:id,vehicle_number,status',
                    'statusHistory',
                ]);
            },
            'statusHistory',
        ];
    }

    private function visibleWards(User $actor, array $stationIds): Collection
    {
        $wards = Ward::query()->where('is_active', true);
        if (! $this->canViewAllStations($actor)) {
            $wards->whereHas('fireStations', fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds));
        }

        return $wards->orderBy('name')->get(['id', 'name']);
    }

    private function visibleVehicles(User $actor, array $stationIds): Collection
    {
        $vehicles = Vehicle::query();
        if (! $this->canViewAllStations($actor)) {
            $vehicles->whereIn('fire_station_id', $stationIds);
        }

        return $vehicles->orderBy('vehicle_number')->get(['id', 'vehicle_number']);
    }

    private function recordMetrics(EmergencyJob $job): array
    {
        $occurredAt = $job->occurred_at;
        $assignments = $job->assignments;
        $assignedAt = $assignments->pluck('assigned_at')->filter()
            ->map(fn ($time) => Carbon::parse($time))
            ->filter(fn (Carbon $time): bool => $occurredAt && $time->greaterThanOrEqualTo($occurredAt))
            ->sort()->first();
        $departureAt = $assignments->flatMap->statusHistory
            ->where('new_status', JobAssignment::STATUS_EN_ROUTE)
            ->pluck('changed_at')->filter()->map(fn ($time) => Carbon::parse($time))
            ->filter(fn (Carbon $time): bool => $occurredAt && $time->greaterThanOrEqualTo($occurredAt))
            ->sort()->first();
        $arrivalAt = $assignments->flatMap->statusHistory
            ->where('new_status', JobAssignment::STATUS_ARRIVED)
            ->pluck('changed_at')->filter()->map(fn ($time) => Carbon::parse($time))
            ->filter(fn (Carbon $time): bool => $occurredAt && $time->greaterThanOrEqualTo($occurredAt))
            ->sort()->first();
        $resolutionAt = $job->statusHistory
            ->where('new_status', EmergencyJob::STATUS_COMPLETED)
            ->whereNull('job_assignment_id')
            ->pluck('changed_at')->filter()->map(fn ($time) => Carbon::parse($time))
            ->filter(fn (Carbon $time): bool => $occurredAt && $time->greaterThanOrEqualTo($occurredAt))
            ->sort()->first();

        return [
            'assigned_at' => $assignedAt,
            'departure_at' => $departureAt,
            'arrival_at' => $arrivalAt,
            'resolution_at' => $resolutionAt,
            'pickup_minutes' => $occurredAt && $assignedAt ? round($occurredAt->diffInSeconds($assignedAt) / 60, 1) : null,
            'response_minutes' => $occurredAt && $arrivalAt ? round($occurredAt->diffInSeconds($arrivalAt) / 60, 1) : null,
            'resolution_minutes' => $occurredAt && $resolutionAt ? round($occurredAt->diffInSeconds($resolutionAt) / 60, 1) : null,
        ];
    }

    private function wardReports(Collection $jobs, Collection $metrics): array
    {
        return $jobs->groupBy(fn (EmergencyJob $job): string => (string) ($job->ward_id ?? 0))
            ->map(function (Collection $wardJobs) use ($metrics): array {
                $first = $wardJobs->first();
                $times = $wardJobs->map(fn (EmergencyJob $job): array => $metrics[$job->id]);

                return [
                    'ward_id' => $first->ward_id,
                    'ward' => $first->ward?->name ?? 'Unassigned',
                    'total' => $wardJobs->count(),
                    'critical' => $wardJobs->where('priority', 'CRITICAL')->count(),
                    'high' => $wardJobs->where('priority', 'HIGH')->count(),
                    'medium' => $wardJobs->whereIn('priority', ['MEDIUM', 'NORMAL'])->count(),
                    'low' => $wardJobs->where('priority', 'LOW')->count(),
                    'resolved' => $wardJobs->where('status', EmergencyJob::STATUS_COMPLETED)->count(),
                    'pending' => $wardJobs->whereIn('status', [EmergencyJob::STATUS_RECEIVED, EmergencyJob::STATUS_ASSIGNED])->count(),
                    'average_response' => $this->average($times->pluck('response_minutes')),
                    'average_resolution' => $this->average($times->pluck('resolution_minutes')),
                ];
            })->values()->all();
    }

    private function wardReportsFiltered(Collection $jobs): array
    {
        return $jobs->groupBy(fn (EmergencyJob $job): string => (string) ($job->ward_id ?? 0))
            ->map(fn (Collection $items): array => [
                'ward_id' => $items->first()->ward_id,
                'incidents' => $items->map(fn (EmergencyJob $job): array => [
                    'job_id' => $job->job_id,
                    'type' => str_replace('_', ' ', $job->incident_type),
                    'status' => str_replace('_', ' ', $job->status),
                    'url' => route('app.jobs.show', $job),
                ])->values()->all(),
            ])->values()->all();
    }

    private function typeReports(Collection $jobs, Collection $metrics, int $total): array
    {
        return $jobs->groupBy('incident_type')->map(function (Collection $typeJobs, string $type) use ($metrics, $total): array {
            return [
                'type' => str_replace('_', ' ', $type),
                'total' => $typeJobs->count(),
                'resolved' => $typeJobs->where('status', EmergencyJob::STATUS_COMPLETED)->count(),
                'pending' => $typeJobs->whereIn('status', $this->pendingStatuses())->count(),
                'average_response' => $this->average($typeJobs->map(fn (EmergencyJob $job) => $metrics[$job->id]['response_minutes'])),
                'percentage' => $total === 0 ? 0 : round($typeJobs->count() / $total * 100, 1),
            ];
        })->values()->all();
    }

    private function priorityReports(Collection $jobs): array
    {
        return $jobs->groupBy('priority')->map(fn (Collection $priorityJobs, string $priority): array => [
            'priority' => $priority,
            'total' => $priorityJobs->count(),
            'resolved' => $priorityJobs->where('status', EmergencyJob::STATUS_COMPLETED)->count(),
            'pending' => $priorityJobs->whereIn('status', $this->pendingStatuses())->count(),
        ])->values()->all();
    }

    private function vehicleReports(Collection $jobs): array
    {
        return $jobs->flatMap(fn (EmergencyJob $job) => $job->assignments->map(fn (JobAssignment $assignment): array => [
            'vehicle' => $assignment->vehicle?->vehicle_number ?? 'Unknown',
            'vehicle_id' => $assignment->vehicle_id,
            'job' => $job,
            'assignment' => $assignment,
        ]))->groupBy('vehicle_id')->map(function (Collection $deployments): array {
            $jobIds = $deployments->pluck('job.id')->unique();
            $jobsForVehicle = $deployments->pluck('job')->unique('id');
            $times = $jobsForVehicle->map(fn (EmergencyJob $job): array => $this->recordMetrics($job));
            $workingSeconds = $deployments->sum(function (array $deployment): int {
                $assignment = $deployment['assignment'];
                $history = $assignment->statusHistory->sortBy('changed_at');
                $start = $history->firstWhere('new_status', JobAssignment::STATUS_ARRIVED)?->changed_at
                    ?? $history->firstWhere('new_status', JobAssignment::STATUS_IN_PROGRESS)?->changed_at;
                $end = $history->where('new_status', JobAssignment::STATUS_COMPLETED)->last()?->changed_at;

                return $start && $end ? max(0, $start->diffInSeconds($end)) : 0;
            });

            return [
                'vehicle_id' => $deployments->first()['vehicle_id'],
                'vehicle' => $deployments->first()['vehicle'],
                'total' => $jobIds->count(),
                'completed' => $deployments->where('assignment.status', JobAssignment::STATUS_COMPLETED)->pluck('job.id')->unique()->count(),
                'pending' => $deployments->whereIn('assignment.status', [JobAssignment::STATUS_ASSIGNED, JobAssignment::STATUS_EN_ROUTE, JobAssignment::STATUS_ARRIVED, JobAssignment::STATUS_IN_PROGRESS])->pluck('job.id')->unique()->count(),
                'average_pickup' => $this->average($times->pluck('pickup_minutes')),
                'average_response' => $this->average($times->pluck('response_minutes')),
                'average_resolution' => $this->average($times->pluck('resolution_minutes')),
                'working_hours' => round($workingSeconds / 3600, 1),
            ];
        })->values()->all();
    }

    private function average(Collection $values): ?float
    {
        $valid = $values->filter(fn ($value): bool => $value !== null);

        return $valid->isEmpty() ? null : round((float) $valid->avg(), 1);
    }

    private function pendingStatuses(): array
    {
        return [
            EmergencyJob::STATUS_RECEIVED,
            EmergencyJob::STATUS_ASSIGNED,
            EmergencyJob::STATUS_EN_ROUTE,
            EmergencyJob::STATUS_ARRIVED,
            EmergencyJob::STATUS_IN_PROGRESS,
        ];
    }

    private function responseStats(Builder $jobs, bool $preserveApiResponseMetrics): array
    {
        $responseStatus = $preserveApiResponseMetrics
            ? JobAssignment::STATUS_EN_ROUTE
            : JobAssignment::STATUS_ARRIVED;
        $events = JobStatusHistory::query()
            ->join('emergency_jobs', 'emergency_jobs.id', '=', 'job_status_history.emergency_job_id')
            ->whereIn('job_status_history.emergency_job_id', (clone $jobs)->select('emergency_jobs.id'))
            ->whereIn('job_status_history.new_status', [$responseStatus, EmergencyJob::STATUS_COMPLETED])
            ->get([
                'job_status_history.emergency_job_id',
                'job_status_history.job_assignment_id',
                'job_status_history.new_status',
                'job_status_history.changed_at',
                'emergency_jobs.occurred_at',
            ])
            ->groupBy('emergency_job_id');

        $responseMinutes = [];
        $resolutionMinutes = [];

        foreach ($events as $jobEvents) {
            $occurredAt = Carbon::parse($jobEvents->first()->occurred_at);
            $firstResponse = $jobEvents
                ->where('new_status', $responseStatus)
                ->map(fn ($event) => Carbon::parse($event->changed_at))
                ->filter(fn (Carbon $changedAt): bool => $changedAt->greaterThanOrEqualTo($occurredAt))
                ->sort()
                ->first();

            if ($firstResponse) {
                $responseMinutes[] = $occurredAt->diffInSeconds($firstResponse) / 60;
            }

            $completedAt = $jobEvents
                ->where('new_status', EmergencyJob::STATUS_COMPLETED)
                ->whereNull('job_assignment_id')
                ->map(fn ($event) => Carbon::parse($event->changed_at))
                ->filter(fn (Carbon $changedAt): bool => $changedAt->greaterThanOrEqualTo($occurredAt))
                ->sort()
                ->first();

            if ($completedAt) {
                $resolutionMinutes[] = $occurredAt->diffInSeconds($completedAt) / 60;
            }
        }

        return [
            'average_response_minutes' => $responseMinutes === [] ? null : round(array_sum($responseMinutes) / count($responseMinutes), 1),
            'average_resolution_minutes' => $resolutionMinutes === [] ? null : round(array_sum($resolutionMinutes) / count($resolutionMinutes), 1),
            'jobs_with_response_time' => count($responseMinutes),
            'jobs_with_resolution_time' => count($resolutionMinutes),
        ];
    }

    private function stationWorkload(Builder $jobs, Collection $stations): array
    {
        $counts = JobAssignment::query()
            ->whereIn('emergency_job_id', (clone $jobs)->select('emergency_jobs.id'))
            ->selectRaw('fire_station_id, COUNT(*) AS deployments')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS completed_deployments', [JobAssignment::STATUS_COMPLETED])
            ->groupBy('fire_station_id')
            ->get()
            ->keyBy('fire_station_id');

        return $stations->map(fn (FireStation $station): array => [
            'id' => (int) $station->id,
            'name' => $station->name,
            'code' => $station->code,
            'deployments' => (int) ($counts[$station->id]->deployments ?? 0),
            'completed_deployments' => (int) ($counts[$station->id]->completed_deployments ?? 0),
        ])->all();
    }

    private function dailySeries(Carbon $from, Carbon $to, Collection $counts): array
    {
        $days = [];
        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $days[] = [
                'date' => $key,
                'label' => $day->format('M j'),
                'count' => (int) ($counts[$key] ?? 0),
            ];
        }

        return $days;
    }

    private function distribution(Collection $counts, int $total): array
    {
        return $counts->map(fn ($count, string $label): array => [
            'label' => $label,
            'count' => (int) $count,
            'percentage' => $total === 0 ? 0 : round(((int) $count) / $total * 100, 1),
        ])->values()->all();
    }

    private function visibleJobs(User $actor, array $stationIds): Builder
    {
        $jobs = EmergencyJob::query();
        if ($this->canViewAllStations($actor)) {
            return $jobs;
        }

        return $jobs->where(function (Builder $query) use ($stationIds): void {
            $query->whereHas(
                'ward.fireStations',
                fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
            )->orWhereHas(
                'assignments',
                fn (Builder $assignments) => $assignments->whereIn('fire_station_id', $stationIds),
            );
        });
    }

    private function visibleStations(User $actor): Collection
    {
        $stations = FireStation::query()->orderBy('name');

        if (! $this->canViewAllStations($actor)) {
            $stationIds = $actor->fireStations()->pluck('fire_stations.id')
                ->merge($actor->managedFireStations()->pluck('id'))
                ->unique()
                ->values();
            $stations->whereKey($stationIds);
        }

        return $stations->get(['id', 'name', 'code']);
    }

    private function canViewAllStations(User $actor): bool
    {
        return $actor->hasRole('SUPER_ADMIN') || $actor->hasRole('CONTROL_ROOM_ADMIN');
    }
}
