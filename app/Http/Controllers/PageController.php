<?php

namespace App\Http\Controllers;

use App\Models\EmergencyJob;
use App\Models\FireStation;
use App\Models\JobAssignment;
use App\Models\JobCallLog;
use App\Models\JobStatusHistory;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Ward;
use App\Notifications\DriverAssignmentActionAlert;
use App\Services\VehicleDisplayStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PageController extends Controller
{
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $user?->loadMissing('roles.permissions');
        abort_if($user && ! $user->hasPermission('dashboard.view'), 403);
        $canViewIncidentMap = ($user?->hasPermission('gps.view') ?? false)
            && ($user?->hasPermission('jobs.view') ?? false);

        if (! $user) {
            return view('pages.dashboard', [
                'summary' => [
                    'total_stations' => 0,
                    'active_stations' => 0,
                    'active_wards' => 0,
                    'active_users' => 0,
                    'system_roles' => 0,
                    'permissions' => 0,
                    'vehicles' => 0,
                    'available_vehicles' => 0,
                    'on_road_vehicles' => 0,
                    'off_road_vehicles' => 0,
                    'on_site_vehicles' => 0,
                    'teams' => 0,
                    'active_jobs' => 0,
                    'pending_jobs' => 0,
                    'completed_jobs' => 0,
                    'transferred_jobs' => 0,
                    'total_jobs' => 0,
                ],
                'dashboardVehicles' => collect(),
                'recentJobs' => collect(),
                'liveMapData' => ['incidents' => [], 'vehicles' => [], 'center' => null],
                'mapWards' => collect(),
                'mapWardId' => null,
                'canViewIncidentMap' => false,
                'incidentFilter' => 'open',
                'incidentFilterCounts' => collect(),
                'incidentSearch' => '',
                'helpRequests' => collect(),
                'vehicleFilter' => 'all',
                'vehicleFilterCounts' => collect(),
                'vehicleSearch' => '',
                'preview' => true,
            ]);
        }

        $stationIds = $this->stationIds($user);
        $allStations = $this->canViewAllStations($user);
        $canViewVehicles = $user->hasPermission('vehicles.view');
        $canViewTeams = $user->hasPermission('teams.view');
        $canViewJobs = $user->hasPermission('jobs.view');
        $stationsQuery = FireStation::query();
        $wardsQuery = Ward::query()->where('is_active', true);
        $usersQuery = User::query()->where('is_active', true);

        if (! $allStations) {
            $stationsQuery->whereKey($stationIds);
            $wardsQuery->whereHas(
                'fireStations',
                fn (Builder $query) => $query->whereIn('fire_stations.id', $stationIds),
            );
            $usersQuery->where(
                fn (Builder $query) => $query
                    ->whereHas(
                        'fireStations',
                        fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
                    )
                    ->orWhereHas(
                        'managedFireStations',
                        fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
                    ),
            );
        }

        $stations = $stationsQuery->get(['id', 'is_active', 'latitude', 'longitude']);
        $vehiclesQuery = Vehicle::query();
        $teamsQuery = Team::query();
        $jobsQuery = $this->visibleJobs($user);

        if (! $allStations) {
            $vehiclesQuery->whereIn('fire_station_id', $stationIds);
            $teamsQuery->whereIn('fire_station_id', $stationIds);
        }

        $validated = $request->validate([
            'incident_search' => ['nullable', 'string', 'max:100'],
            'vehicle_search' => ['nullable', 'string', 'max:100'],
            'vehicle_filter' => ['nullable', 'in:all,on_road,off_road,assigned,idle,at_incident'],
            'map_ward_id' => ['nullable', 'integer', 'exists:wards,id'],
        ]);
        $incidentSearch = trim($validated['incident_search'] ?? '');
        $vehicleSearch = trim($validated['vehicle_search'] ?? '');
        $vehicleFilter = $validated['vehicle_filter'] ?? 'all';
        $mapWardId = isset($validated['map_ward_id']) ? (int) $validated['map_ward_id'] : null;
        $vehicleColumns = [
            'id',
            'fire_station_id',
            'vehicle_number',
            'vehicle_type',
            'status',
            'latitude',
            'longitude',
        ];
        if (Schema::hasColumn('vehicles', 'operational_status')) {
            $vehicleColumns[] = 'operational_status';
        }

        $dashboardVehicles = $canViewVehicles
            ? (clone $vehiclesQuery)
                ->withCount([
                    'assignments as open_tickets_count' => fn (Builder $assignments) => $assignments
                        ->whereNull('released_at')
                        ->whereHas(
                            'emergencyJob',
                            fn (Builder $jobs) => $jobs->whereNotIn('status', [
                                EmergencyJob::STATUS_COMPLETED,
                                EmergencyJob::STATUS_DUPLICATE,
                                EmergencyJob::STATUS_TRANSFERRED_TO_112,
                            ]),
                        ),
                ])
                ->with([
                    'fireStation:id,name',
                    'latestGpsLog',
                    'assignments' => fn (HasMany $assignments) => $assignments
                        ->whereNull('released_at')
                        ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [JobAssignment::STATUS_QUEUED])
                        ->latest('assigned_at')
                        ->with([
                            'emergencyJob.ward:id,name',
                            'statusHistory' => fn (HasMany $history) => $history
                                ->whereIn('new_status', [
                                    JobAssignment::STATUS_ARRIVED,
                                    JobAssignment::STATUS_STARTED,
                                    JobAssignment::STATUS_IN_PROGRESS,
                                ])
                                ->orderBy('changed_at'),
                        ]),
                ])
                ->when(
                    $vehicleFilter === 'on_road',
                    fn (Builder $query) => $query->whereHas(
                        'assignments',
                        fn (Builder $assignments) => $assignments
                            ->whereNull('released_at')
                            ->where('status', JobAssignment::STATUS_EN_ROUTE),
                    ),
                )
                ->when(
                    $vehicleFilter === 'off_road',
                    fn (Builder $query) => $query->where('status', Vehicle::STATUS_MAINTENANCE),
                )
                ->when(
                    $vehicleFilter === 'assigned',
                    fn (Builder $query) => $query->whereHas(
                        'assignments',
                        fn (Builder $assignments) => $assignments
                            ->whereNull('released_at')
                            ->whereIn('status', [JobAssignment::STATUS_ASSIGNED, JobAssignment::STATUS_QUEUED]),
                    ),
                )
                ->when(
                    $vehicleFilter === 'idle',
                    fn (Builder $query) => $query->where('status', Vehicle::STATUS_AVAILABLE),
                )
                ->when(
                    $vehicleFilter === 'at_incident',
                    fn (Builder $query) => $query->whereHas(
                        'assignments',
                        fn (Builder $assignments) => $assignments
                            ->whereNull('released_at')
                            ->whereIn('status', [
                                JobAssignment::STATUS_ARRIVED,
                                JobAssignment::STATUS_STARTED,
                                JobAssignment::STATUS_IN_PROGRESS,
                            ]),
                    ),
                )
                ->when($vehicleSearch !== '', function (Builder $query) use ($vehicleSearch): void {
                    $query->where(function (Builder $searchQuery) use ($vehicleSearch): void {
                        $searchQuery->whereLike('vehicle_number', '%'.$vehicleSearch.'%')
                            ->orWhereLike('vehicle_type', '%'.$vehicleSearch.'%')
                            ->orWhereLike('status', '%'.$vehicleSearch.'%')
                            ->orWhereHas(
                                'fireStation',
                                fn (Builder $station) => $station->whereLike('name', '%'.$vehicleSearch.'%'),
                            );
                    });
                })
                ->orderBy('vehicle_number')
                ->paginate(5, $vehicleColumns, 'vehicles_page')
                ->appends($request->except('vehicles_page'))
            : collect();

        $pendingStatuses = [EmergencyJob::STATUS_RECEIVED, EmergencyJob::STATUS_ASSIGNED];
        $activeStatuses = [EmergencyJob::STATUS_EN_ROUTE, EmergencyJob::STATUS_ARRIVED, EmergencyJob::STATUS_IN_PROGRESS];
        $closedStatuses = [
            EmergencyJob::STATUS_COMPLETED,
            EmergencyJob::STATUS_DUPLICATE,
            EmergencyJob::STATUS_TRANSFERRED_TO_112,
        ];
        $incidentFilter = $request->query('status_filter', 'open');
        $incidentFilterStatuses = [
            'open' => null,
            'closed' => $closedStatuses,
            'pending' => $pendingStatuses,
            'on_site' => [EmergencyJob::STATUS_ARRIVED, EmergencyJob::STATUS_IN_PROGRESS],
            'en_route' => [EmergencyJob::STATUS_EN_ROUTE],
        ];
        $activeWardsCount = $canViewJobs
            ? (clone $wardsQuery)->count()
            : 0;
        $jobStatusCounts = $canViewJobs
            ? (clone $jobsQuery)->selectRaw('status, COUNT(*) AS aggregate')->groupBy('status')->pluck('aggregate', 'status')
            : collect();
        $incidentFilterCounts = collect([
            'open' => max(0, (int) $jobStatusCounts->sum() - (int) $jobStatusCounts->only($closedStatuses)->sum()),
            'closed' => (int) $jobStatusCounts->only($closedStatuses)->sum(),
            'pending' => (int) $jobStatusCounts->only($pendingStatuses)->sum(),
            'on_site' => (int) $jobStatusCounts->only([
                EmergencyJob::STATUS_ARRIVED,
                EmergencyJob::STATUS_IN_PROGRESS,
            ])->sum(),
            'en_route' => (int) ($jobStatusCounts[EmergencyJob::STATUS_EN_ROUTE] ?? 0),
        ]);
        $helpRequests = $canViewJobs ? $this->recentDriverAlerts($user) : collect();

        if (! is_string($incidentFilter) || ! array_key_exists($incidentFilter, $incidentFilterStatuses)) {
            $incidentFilter = 'open';
        }

        $recentJobs = $canViewJobs
            ? (clone $jobsQuery)
                ->with([
                    'ward',
                    'assignments' => fn (HasMany $query) => $query
                        ->latest('assigned_at')
                        ->with(['fireStation:id,name', 'vehicle:id,vehicle_number']),
                ])
                ->when(
                    $incidentFilter === 'open',
                    fn (Builder $query) => $query->whereNotIn('status', $closedStatuses),
                    fn (Builder $query) => $query->whereIn('status', $incidentFilterStatuses[$incidentFilter]),
                )
                ->when($mapWardId !== null, fn (Builder $query) => $query->where('ward_id', $mapWardId))
                ->when($incidentSearch !== '', function (Builder $query) use ($incidentSearch): void {
                    $query->where(function (Builder $searchQuery) use ($incidentSearch): void {
                        $searchQuery->whereLike('job_id', '%'.$incidentSearch.'%')
                            ->orWhereLike('caller_name', '%'.$incidentSearch.'%')
                            ->orWhereLike('caller_phone', '%'.$incidentSearch.'%')
                            ->orWhereLike('incident_type', '%'.$incidentSearch.'%')
                            ->orWhereLike('incident_address', '%'.$incidentSearch.'%')
                            ->orWhereHas(
                                'ward',
                                fn (Builder $ward) => $ward->whereLike('name', '%'.$incidentSearch.'%'),
                            )
                            ->orWhereHas(
                                'assignments.vehicle',
                                fn (Builder $vehicle) => $vehicle->whereLike('vehicle_number', '%'.$incidentSearch.'%'),
                            );
                    });
                })
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->paginate(8, ['emergency_jobs.*'], 'incidents_page')
                ->appends($request->except('incidents_page'))
            : collect();
        $incidentMapJobs = $recentJobs instanceof LengthAwarePaginator
            ? $recentJobs->getCollection()
            : $recentJobs;
        $mapWards = $canViewIncidentMap
            ? (clone $wardsQuery)->orderBy('name')->get(['id', 'name'])
            : collect();
        $mapStation = $stations->first(
            fn (FireStation $station): bool => $station->latitude !== null && $station->longitude !== null,
        );
        $liveMapData = $this->liveMapData(
            $incidentMapJobs,
            $dashboardVehicles,
            $canViewVehicles,
            $mapStation ? [(float) $mapStation->latitude, (float) $mapStation->longitude] : null,
        );
        $vehicleCounts = $canViewVehicles
            ? (clone $vehiclesQuery)->selectRaw(
                'COUNT(*) AS total, COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS available',
                [Vehicle::STATUS_AVAILABLE],
            )->first()
            : null;
        $onRoadVehicleCount = $canViewVehicles
            ? (clone $vehiclesQuery)->whereHas(
                'assignments',
                fn (Builder $assignments) => $assignments
                    ->whereNull('released_at')
                    ->where('status', JobAssignment::STATUS_EN_ROUTE),
            )->count()
            : null;
        $onSiteVehicleCount = $canViewVehicles
            ? (clone $vehiclesQuery)->whereHas(
                'assignments',
                fn (Builder $assignments) => $assignments
                    ->whereNull('released_at')
                    ->whereIn('status', [
                        JobAssignment::STATUS_ARRIVED,
                        JobAssignment::STATUS_STARTED,
                        JobAssignment::STATUS_IN_PROGRESS,
                    ]),
            )->count()
            : null;
        $assignedVehicleCount = $canViewVehicles
            ? (clone $vehiclesQuery)->whereHas(
                'assignments',
                fn (Builder $assignments) => $assignments
                    ->whereNull('released_at')
                    ->where('status', JobAssignment::STATUS_ASSIGNED),
            )->count()
            : null;
        $offRoadVehicleCount = $canViewVehicles
            ? (clone $vehiclesQuery)->where('status', Vehicle::STATUS_MAINTENANCE)->count()
            : null;
        $vehicleFilterCounts = collect([
            'all' => $vehicleCounts ? (int) $vehicleCounts->total : 0,
            'on_road' => $onRoadVehicleCount ?? 0,
            'off_road' => $offRoadVehicleCount ?? 0,
            'assigned' => $assignedVehicleCount ?? 0,
            'idle' => $vehicleCounts ? (int) $vehicleCounts->available : 0,
            'at_incident' => $onSiteVehicleCount ?? 0,
        ]);

        return view('pages.dashboard', [
            'summary' => [
                'total_stations' => $stations->count(),
                'active_stations' => $stations->where('is_active', true)->count(),
                'active_wards' => $activeWardsCount,
                'active_users' => $usersQuery->count(),
                'system_roles' => Role::query()->where('is_system', true)->count(),
                'permissions' => Permission::query()->count(),
                'vehicles' => $vehicleCounts ? (int) $vehicleCounts->total : null,
                'available_vehicles' => $vehicleCounts ? (int) $vehicleCounts->available : null,
                'on_road_vehicles' => $onRoadVehicleCount,
                'off_road_vehicles' => $offRoadVehicleCount,
                'on_site_vehicles' => $onSiteVehicleCount,
                'teams' => $canViewTeams ? (clone $teamsQuery)->count() : null,
                'active_jobs' => $canViewJobs
                    ? (int) $jobStatusCounts->only($activeStatuses)->sum()
                    : null,
                'pending_jobs' => $canViewJobs
                    ? (int) $jobStatusCounts->only($pendingStatuses)->sum()
                    : null,
                'completed_jobs' => $canViewJobs ? (int) ($jobStatusCounts[EmergencyJob::STATUS_COMPLETED] ?? 0) : null,
                'transferred_jobs' => $canViewJobs
                    ? (int) ($jobStatusCounts[EmergencyJob::STATUS_TRANSFERRED_TO_112] ?? 0)
                    : null,
                'total_jobs' => $canViewJobs ? (int) $jobStatusCounts->sum() : null,
            ],
            'dashboardVehicles' => $dashboardVehicles,
            'recentJobs' => $recentJobs,
            'liveMapData' => $liveMapData,
            'mapWards' => $mapWards,
            'mapWardId' => $mapWardId,
            'canViewIncidentMap' => $canViewIncidentMap,
            'incidentFilter' => $incidentFilter,
            'incidentFilterCounts' => $incidentFilterCounts,
            'incidentSearch' => $incidentSearch,
            'helpRequests' => $helpRequests,
            'vehicleFilter' => $vehicleFilter,
            'vehicleFilterCounts' => $vehicleFilterCounts,
            'vehicleSearch' => $vehicleSearch,
            'preview' => false,
        ]);
    }

    public function dashboardMapData(Request $request): JsonResponse
    {
        $view = $this->dashboard($request);

        return response()->json(['data' => $view->getData()['liveMapData']]);
    }

    public function dashboardActivity(Request $request)
    {
        return response()->json([
            'data' => $this->dashboardActivityItems($request->user()),
        ]);
    }

    public function dashboardStatuses(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'incident_ids' => ['sometimes', 'array', 'max:20'],
            'incident_ids.*' => ['string', 'max:100', 'distinct'],
            'vehicle_ids' => ['sometimes', 'array', 'max:20'],
            'vehicle_ids.*' => ['integer', 'distinct'],
        ]);
        $user = $request->user();
        $incidentIds = $validated['incident_ids'] ?? [];
        $incidents = $this->visibleJobs($user)
            ->whereIn('job_id', $incidentIds)
            ->get(['job_id', 'status'])
            ->map(fn (EmergencyJob $job): array => [
                'job_id' => $job->job_id,
                'status' => $job->status,
            ])
            ->values();

        $vehicles = collect();
        $vehicleIds = $validated['vehicle_ids'] ?? [];
        if ($user->hasPermission('vehicles.view') && $vehicleIds !== []) {
            $vehicleColumns = ['id', 'fire_station_id', 'status'];
            if (Schema::hasColumn('vehicles', 'operational_status')) {
                $vehicleColumns[] = 'operational_status';
            }

            $vehicleQuery = Vehicle::query()
                ->whereKey($vehicleIds)
                ->with([
                    'assignments' => fn (HasMany $assignments) => $assignments
                        ->whereNull('released_at')
                        ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [JobAssignment::STATUS_QUEUED])
                        ->latest('assigned_at'),
                ]);
            if (! $this->canViewAllStations($user)) {
                $vehicleQuery->whereIn('fire_station_id', $this->stationIds($user));
            }

            $vehicles = $vehicleQuery->get($vehicleColumns)
                ->map(function (Vehicle $vehicle): array {
                    $status = VehicleDisplayStatus::describe($vehicle, $vehicle->assignments->first());

                    return [
                        'id' => $vehicle->id,
                        'label' => $status['label'],
                        'class' => $status['class'],
                    ];
                })
                ->values();
        }

        return response()->json([
            'data' => [
                'incidents' => $incidents,
                'vehicles' => $vehicles,
            ],
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function dashboardHelpRequests(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->recentDriverAlerts($request->user())->map(function ($notification): array {
                $alert = $notification->data;
                $action = $alert['action'] ?? 'need_help';

                return [
                    'id' => $notification->id,
                    'action' => $action,
                    'title' => $action === 'escalated'
                        ? 'Driver escalation'
                        : Str::headline($alert['help_type'] ?? $alert['reason'] ?? 'Help requested'),
                    'help_type' => $alert['help_type'] ?? null,
                    'reason' => $alert['reason'] ?? null,
                    'comments' => $alert['comments'] ?? null,
                    'driver_name' => $alert['driver_name'] ?? null,
                    'team_name' => $alert['team_name'] ?? null,
                    'vehicle_number' => $alert['vehicle_number'] ?? null,
                    'job_id' => $alert['job_id'] ?? null,
                    'incident_url' => ! empty($alert['incident_id'])
                        ? route('app.jobs.show', $alert['incident_id'])
                        : null,
                    'dismiss_url' => route('dashboard.help-requests.dismiss', $notification->id),
                    'requested_at' => $alert['requested_at'] ?? $notification->created_at?->toIso8601String(),
                    'created_at' => $notification->created_at?->toIso8601String(),
                ];
            })->values(),
        ]);
    }

    public function dismissDriverAlert(Request $request, string $notificationId): JsonResponse|RedirectResponse
    {
        $notification = $request->user()->notifications()
            ->whereKey($notificationId)
            ->where('type', DriverAssignmentActionAlert::class)
            ->firstOrFail();

        abort_unless(in_array($notification->data['action'] ?? null, ['need_help', 'escalated'], true), 404);

        $notification->markAsRead();

        if ($request->expectsJson()) {
            return response()->json(['dismissed' => true]);
        }

        return back()->with('status', 'Driver alert dismissed.');
    }

    public function dismissAllDriverAlerts(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        $notificationIds = $user->notifications()
            ->where('type', DriverAssignmentActionAlert::class)
            ->whereNull('read_at')
            ->get()
            ->filter(fn ($notification): bool => in_array(
                $notification->data['action'] ?? null,
                ['need_help', 'escalated'],
                true,
            ))
            ->pluck('id');

        if ($notificationIds->isNotEmpty()) {
            $user->notifications()
                ->whereKey($notificationIds)
                ->update(['read_at' => now()]);
        }

        if ($request->expectsJson()) {
            return response()->json(['dismissed' => $notificationIds->count()]);
        }

        return back()->with('status', 'Driver alerts dismissed.');
    }

    public function catalog(Request $request, string $kind)
    {
        $catalog = [
            'stations' => ['title' => 'Fire stations', 'permission' => 'stations.view'],
            'wards' => ['title' => 'Ward management', 'permission' => 'wards.view'],
            'users' => ['title' => 'User management', 'permission' => 'users.view'],
            'roles' => ['title' => 'Roles & permissions', 'permission' => 'roles.view'],
            'vehicles' => ['title' => 'Vehicles', 'permission' => 'vehicles.view'],
            'teams' => ['title' => 'Teams & members', 'permission' => 'teams.view'],
            'jobs' => ['title' => 'Emergency incidents', 'permission' => 'jobs.view'],
        ];
        abort_unless(isset($catalog[$kind]), 404);

        $user = $request->user();
        abort_if($user && ! $user->hasPermission($catalog[$kind]['permission']), 403);

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim($validated['search'] ?? '');
        $records = null;

        if ($user) {
            $records = match ($kind) {
                'stations' => $this->stationCatalog($user, $search),
                'wards' => $this->wardCatalog($user, $search),
                'users' => $this->userCatalog($user, $search),
                'roles' => $this->roleCatalog($search),
                'vehicles' => $this->vehicleCatalog($user, $search),
                'teams' => $this->teamCatalog($user, $search),
                'jobs' => $this->jobCatalog($user, $search),
            };
        }

        return view('pages.catalog', [
            'kind' => $kind,
            'title' => $catalog[$kind]['title'],
            'search' => $search,
            'records' => $records,
            'canManage' => $user?->hasPermission($kind.'.manage') ?? false,
            'canCreate' => $kind === 'jobs' && ($user?->hasPermission('jobs.create') ?? false),
            'canManageRoles' => $kind === 'roles' && ($user?->hasPermission('roles.manage') ?? false),
        ]);
    }

    private function stationCatalog(User $user, string $search)
    {
        $query = FireStation::query()->withCount([
            'wards as wards_count' => fn (Builder $wards) => $wards->where('wards.is_active', true),
            'users as active_staff_count' => fn (Builder $users) => $users->where('users.is_active', true),
        ]);

        if (! $this->canViewAllStations($user)) {
            $query->whereKey($this->stationIds($user));
        }

        return $this->search($query, $search, ['code', 'name', 'address'])
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();
    }

    private function wardCatalog(User $user, string $search)
    {
        $query = Ward::query()->with('fireStations:id,name');

        if (! $this->canViewAllStations($user)) {
            $stationIds = $this->stationIds($user);
            $query->whereHas(
                'fireStations',
                fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
            );
        }

        return $this->search($query, $search, ['code', 'name', 'description'])
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();
    }

    private function userCatalog(User $user, string $search)
    {
        $query = User::query()->with('roles');

        if (! $this->canViewAllStations($user)) {
            $stationIds = $this->stationIds($user);
            $query->where(
                fn (Builder $users) => $users
                    ->whereHas(
                        'fireStations',
                        fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
                    )
                    ->orWhereHas(
                        'managedFireStations',
                        fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
                    ),
            );
        }

        return $this->search($query, $search, ['name', 'email'])
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();
    }

    private function roleCatalog(string $search)
    {
        return $this->search(
            Role::query()->with(['permissions' => fn ($query) => $query->orderBy('name')]),
            $search,
            ['code', 'name', 'description'],
        )->orderBy('name')->paginate(10)->withQueryString();
    }

    private function vehicleCatalog(User $user, string $search)
    {
        $query = Vehicle::query()
            ->with([
                'fireStation:id,name,code',
                'assignedDriver:id,name',
                'latestGpsLog',
                'assignments' => fn (HasMany $assignments) => $assignments
                    ->whereNull('released_at')
                    ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [JobAssignment::STATUS_QUEUED])
                    ->latest('assigned_at')
                    ->with('emergencyJob:id,status'),
            ])
            ->withCount([
                'assignments as open_tickets_count' => fn (Builder $assignments) => $assignments
                    ->whereNull('released_at')
                    ->whereHas(
                        'emergencyJob',
                        fn (Builder $jobs) => $jobs->whereNotIn('status', [
                            EmergencyJob::STATUS_COMPLETED,
                            EmergencyJob::STATUS_DUPLICATE,
                            EmergencyJob::STATUS_TRANSFERRED_TO_112,
                        ]),
                    ),
            ]);

        if (! $this->canViewAllStations($user)) {
            $query->whereIn('fire_station_id', $this->stationIds($user));
        }

        return $this->search($query, $search, ['vehicle_number', 'registration_number', 'vehicle_type', 'status'])
            ->orderBy('vehicle_number')
            ->paginate(10)
            ->withQueryString();
    }

    private function teamCatalog(User $user, string $search)
    {
        $query = Team::query()
            ->with(['fireStation:id,name,code', 'leader:id,name'])
            ->withCount([
                'members as active_members_count' => fn (Builder $members) => $members
                    ->whereNull('team_members.left_at'),
            ]);

        if (! $this->canViewAllStations($user)) {
            $query->whereIn('fire_station_id', $this->stationIds($user));
        }

        return $this->search($query, $search, ['name', 'contact_number', 'status'])
            ->orderBy('name')
            ->paginate(10)
            ->withQueryString();
    }

    private function jobCatalog(User $user, string $search)
    {
        $query = EmergencyJob::query()->with('ward:id,name,code');

        if (! $this->canViewAllStations($user)) {
            $stationIds = $this->stationIds($user);
            $query->where(function (Builder $jobs) use ($stationIds): void {
                $jobs->whereHas(
                    'ward.fireStations',
                    fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
                )->orWhereHas(
                    'assignments',
                    fn (Builder $assignments) => $assignments->whereIn('fire_station_id', $stationIds),
                );
            });
        }

        return $this->search($query, $search, [
            'job_id',
            'caller_name',
            'caller_phone',
            'incident_type',
            'society_name',
            'incident_address',
        ])->orderByDesc('occurred_at')->paginate(10)->withQueryString();
    }

    /**
     * @param  list<string>  $columns
     */
    private function search(Builder $query, string $search, array $columns): Builder
    {
        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $nested) use ($columns, $search): void {
            foreach ($columns as $column) {
                $nested->orWhereLike($column, '%'.$search.'%');
            }
        });
    }

    /**
     * @param  array{0: float, 1: float}|null  $mapCenter
     * @return array{incidents: array<int, array<string, mixed>>, vehicles: array<int, array<string, mixed>>, center: array{0: float, 1: float}|null}
     */
    private function liveMapData(
        Collection $jobs,
        LengthAwarePaginator|Collection $vehicles,
        bool $canViewVehicles,
        ?array $mapCenter,
    ): array {
        $incidents = $jobs
            ->map(fn (EmergencyJob $job): array => [
                'id' => (int) $job->id,
                'job_id' => $job->job_id,
                'type' => str_replace('_', ' ', $job->incident_type),
                'description' => $job->remarks ?: str_replace('_', ' ', $job->incident_type),
                'priority' => strtoupper($job->priority),
                'status' => str_replace('_', ' ', $job->status),
                'status_code' => strtoupper($job->status),
                'latitude' => $job->latitude === null ? null : (float) $job->latitude,
                'longitude' => $job->longitude === null ? null : (float) $job->longitude,
                'location' => $job->incident_address,
                'ward_id' => $job->ward_id === null ? null : (int) $job->ward_id,
                'ward' => $job->ward?->name,
                'caller_name' => $job->caller_name,
                'caller_phone' => $job->caller_phone,
                'vehicle_number' => $job->assignments->first()?->vehicle?->vehicle_number,
                'occurred_at' => $job->occurred_at?->toIso8601String(),
                'elapsed_seconds' => $job->occurred_at ? max(0, (int) $job->occurred_at->diffInSeconds(now())) : null,
                'view_url' => route('app.jobs.show', $job),
            ])
            ->values()
            ->all();

        $vehicleRows = $canViewVehicles
            ? ($vehicles instanceof LengthAwarePaginator ? $vehicles->getCollection() : $vehicles)
            : collect();

        $vehicleData = $vehicleRows
            ->map(function (Vehicle $vehicle): array {
                $assignment = $vehicle->assignments->first();
                $job = $assignment?->emergencyJob;
                $gpsLog = $vehicle->latestGpsLog;
                $latitude = $gpsLog?->latitude ?? $vehicle->latitude;
                $longitude = $gpsLog?->longitude ?? $vehicle->longitude;
                $status = VehicleDisplayStatus::describe($vehicle, $assignment);
                $arrival = $assignment?->statusHistory->first(fn ($entry): bool => in_array(
                    $entry->new_status,
                    [JobAssignment::STATUS_ARRIVED, JobAssignment::STATUS_STARTED],
                    true,
                ));
                $workStart = $assignment?->statusHistory->firstWhere('new_status', JobAssignment::STATUS_IN_PROGRESS);

                return [
                    'id' => (int) $vehicle->id,
                    'number' => $vehicle->vehicle_number,
                    'status_code' => $status['code'],
                    'status' => $status['label'],
                    'latitude' => $latitude === null ? null : (float) $latitude,
                    'longitude' => $longitude === null ? null : (float) $longitude,
                    'ward_id' => $job?->ward_id === null ? null : (int) $job->ward_id,
                    'ward' => $job?->ward?->name,
                    'incident_id' => $job?->job_id,
                    'incident_url' => $job ? route('app.jobs.show', $job) : null,
                    'destination' => $job?->incident_address,
                    'incident_latitude' => $job?->latitude === null ? null : (float) $job->latitude,
                    'incident_longitude' => $job?->longitude === null ? null : (float) $job->longitude,
                    'current_location' => $latitude !== null && $longitude !== null
                        ? number_format((float) $latitude, 5).', '.number_format((float) $longitude, 5)
                        : null,
                    'speed_kph' => $gpsLog?->speed_kph === null ? null : (float) $gpsLog->speed_kph,
                    'last_gps_at' => $gpsLog?->recorded_at?->toIso8601String(),
                    'gps_stale' => $gpsLog?->recorded_at?->lt(now()->subMinutes(5)) ?? false,
                    'assigned_at' => $assignment?->assigned_at?->toIso8601String(),
                    'arrived_at' => $arrival?->changed_at?->toIso8601String(),
                    'working_since' => $workStart?->changed_at?->toIso8601String(),
                    'elapsed_seconds' => $status['code'] === 'AT_INCIDENT' && $workStart?->changed_at
                        ? max(0, (int) $workStart->changed_at->diffInSeconds(now()))
                        : null,
                    'view_url' => url('/vehicles/'.$vehicle->id),
                ];
            })
            ->values()
            ->all();

        return ['incidents' => $incidents, 'vehicles' => $vehicleData, 'center' => $mapCenter];
    }

    /**
     * @return Collection<int, int>
     */
    private function stationIds(User $user): Collection
    {
        return $user->fireStations()->pluck('fire_stations.id')
            ->merge($user->managedFireStations()->pluck('id'))
            ->unique()
            ->values();
    }

    private function canViewAllStations(User $user): bool
    {
        return $user->hasRole('SUPER_ADMIN') || $user->hasRole('CONTROL_ROOM_ADMIN');
    }

    private function recentDriverAlerts(User $user): Collection
    {
        return $user->notifications()
            ->where('type', DriverAssignmentActionAlert::class)
            ->whereNull('read_at')
            ->latest()
            ->limit(50)
            ->get()
            ->filter(fn ($notification): bool => in_array(
                $notification->data['action'] ?? null,
                ['need_help', 'escalated'],
                true,
            ))
            ->take(5)
            ->values();
    }

    private function visibleJobs(User $user): Builder
    {
        $jobs = EmergencyJob::query();

        if ($user->hasRole('VEHICLE_DRIVER')) {
            return $jobs->whereHas('assignments', fn (Builder $assignments) => $assignments
                ->whereNull('released_at')
                ->where('assigned_driver_user_id', $user->id));
        }

        if (! $this->canViewAllStations($user)) {
            $stationIds = $this->stationIds($user);
            $jobs->where(function (Builder $query) use ($stationIds): void {
                $query->whereHas(
                    'ward.fireStations',
                    fn (Builder $stations) => $stations->whereIn('fire_stations.id', $stationIds),
                )->orWhereHas(
                    'assignments',
                    fn (Builder $assignments) => $assignments->whereIn('fire_station_id', $stationIds),
                );
            });
        }

        return $jobs;
    }

    /**
     * @return Collection<int, array<string, int|string|null>>
     */
    private function dashboardActivityItems(User $user)
    {
        $since = now()->subDay();
        $jobs = $this->visibleJobs($user)
            ->with('ward:id,name')
            ->where('created_at', '>=', $since)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $calls = JobCallLog::query()
            ->with(['emergencyJob' => fn (Builder|Relation $query) => $query
                ->with('ward:id,name')
                ->whereIn('emergency_jobs.id', $this->visibleJobs($user)->select('emergency_jobs.id'))])
            ->where('received_at', '>=', $since)
            ->whereHas(
                'emergencyJob',
                fn (Builder $query) => $query->whereIn(
                    'emergency_jobs.id',
                    $this->visibleJobs($user)->select('emergency_jobs.id'),
                ),
            )
            ->orderByDesc('received_at')
            ->limit(10)
            ->get();
        $statusEvents = JobStatusHistory::query()
            ->with(['emergencyJob' => fn (Builder|Relation $query) => $query
                ->with('ward:id,name')
                ->whereIn('emergency_jobs.id', $this->visibleJobs($user)->select('emergency_jobs.id'))])
            ->where('changed_at', '>=', $since)
            ->whereHas(
                'emergencyJob',
                fn (Builder $query) => $query->whereIn(
                    'emergency_jobs.id',
                    $this->visibleJobs($user)->select('emergency_jobs.id'),
                ),
            )
            ->orderByDesc('changed_at')
            ->limit(10)
            ->get();

        $jobItems = $jobs->map(fn (EmergencyJob $job): array => [
            'key' => 'job-'.$job->id,
            'type' => 'NEW JOB',
            'job_id' => $job->job_id,
            'caller' => $job->caller_name,
            'phone' => $job->caller_phone,
            'incident' => str_replace('_', ' ', $job->incident_type),
            'society' => $job->society_name ?: ($job->ward?->name ?? 'Area not recorded'),
            'priority' => $job->priority,
            'status' => str_replace('_', ' ', $job->status),
            'time' => $job->created_at?->toIso8601String(),
            'url' => '/jobs/'.$job->id,
        ]);
        $callItems = $calls
            ->filter(fn (JobCallLog $call): bool => $call->emergencyJob !== null)
            ->map(fn (JobCallLog $call): array => [
                'key' => 'call-'.$call->id,
                'type' => 'NEW CALL',
                'job_id' => $call->emergencyJob->job_id,
                'caller' => $call->caller_name,
                'phone' => $call->caller_phone,
                'incident' => str_replace('_', ' ', $call->emergencyJob->incident_type),
                'society' => $call->emergencyJob->society_name ?: ($call->emergencyJob->ward?->name ?? 'Area not recorded'),
                'priority' => $call->emergencyJob->priority,
                'status' => str_replace('_', ' ', $call->emergencyJob->status),
                'time' => $call->received_at?->toIso8601String(),
                'url' => '/jobs/'.$call->emergency_job_id,
            ]);
        $statusItems = $statusEvents
            ->filter(fn (JobStatusHistory $event): bool => $event->emergencyJob !== null)
            ->map(fn (JobStatusHistory $event): array => [
                'key' => 'event-'.$event->id,
                'type' => $event->new_status === EmergencyJob::STATUS_RECEIVED ? 'NEW JOB' : 'STATUS UPDATE',
                'job_id' => $event->emergencyJob->job_id,
                'caller' => $event->emergencyJob->caller_name,
                'phone' => $event->emergencyJob->caller_phone,
                'incident' => str_replace('_', ' ', $event->emergencyJob->incident_type),
                'society' => $event->emergencyJob->society_name ?: ($event->emergencyJob->ward?->name ?? 'Area not recorded'),
                'priority' => $event->emergencyJob->priority,
                'status' => str_replace('_', ' ', $event->new_status),
                'remarks' => $event->remarks,
                'time' => $event->changed_at?->toIso8601String(),
                'url' => '/jobs/'.$event->emergency_job_id,
            ]);

        return $jobItems->concat($callItems)->concat($statusItems)
            ->sortByDesc('time')
            ->take(10)
            ->values();
    }
}
