@extends('layouts.app')

@section('title', 'Incident Dashboard')

@section('content')
@php
    $jobStats = [
        ['label' => 'Total incidents', 'value' => $summary['total_jobs'], 'icon' => '!', 'tone' => 'blue'],
        ['label' => 'Total vehicles', 'value' => $summary['vehicles'], 'icon' => '▰', 'tone' => 'cyan'],
        ['label' => 'On Road', 'value' => $summary['on_road_vehicles'], 'icon' => '➤', 'tone' => 'green'],
        ['label' => 'Off Road', 'value' => $summary['off_road_vehicles'], 'icon' => '▣', 'tone' => 'orange'],
        ['label' => 'On Site', 'value' => $summary['on_site_vehicles'], 'icon' => '⌖', 'tone' => 'purple'],
        ['label' => 'Idle / Free', 'value' => $summary['available_vehicles'], 'icon' => '◷', 'tone' => 'blue'],
        ['label' => 'Transfer to 112', 'value' => $summary['transferred_jobs'], 'icon' => '⇄', 'tone' => 'cyan'],
    ];
@endphp
<section class="ops-dashboard">
    @if ($preview)
        <div class="notice"><span class="notice-icon">i</span><div><strong>Sign in to view operations</strong><p>Live dashboard data is restricted to authorized accounts.</p></div><a class="button button-primary button-small" href="{{ route('login') }}">Sign in</a></div>
    @endif

    @if (auth()->user()?->hasPermission('jobs.view'))
        <section class="ops-panel ops-help-alerts" aria-labelledby="help-alerts-heading"
            data-help-requests data-help-requests-endpoint="{{ route('dashboard.help-requests') }}"
            aria-live="polite" @if ($helpRequests->isEmpty()) hidden @endif>
            <div class="ops-panel-heading">
                <div><h2 id="help-alerts-heading">Driver help requests</h2><p>Urgent requests from assigned response units</p></div>
                <span class="ops-help-alert-count" data-help-request-count>{{ $helpRequests->count() }} recent</span>
            </div>
            <div class="ops-help-alert-list" data-help-alert-list>
                @foreach ($helpRequests as $helpRequest)
                    @php($alert = $helpRequest->data)
                    <article class="ops-help-alert">
                        <span class="ops-help-alert-icon" aria-hidden="true">!</span>
                        <div class="ops-help-alert-details">
                            <strong>{{ \Illuminate\Support\Str::headline($alert['help_type'] ?? $alert['reason'] ?? 'Help requested') }}</strong>
                            <span>
                                {{ $alert['driver_name'] ?? 'Driver' }}
                                · {{ $alert['team_name'] ?? 'No team assigned' }}
                                · {{ $alert['vehicle_number'] ?? 'Vehicle unavailable' }}
                                · {{ $alert['job_id'] ?? 'Incident unavailable' }}
                            </span>
                            @if (! empty($alert['comments']))
                                <small>{{ $alert['comments'] }}</small>
                            @endif
                        </div>
                        <div class="ops-help-alert-meta">
                            <time datetime="{{ $helpRequest->created_at?->toIso8601String() }}">{{ $helpRequest->created_at?->diffForHumans() }}</time>
                            @if (! empty($alert['incident_id']))
                                <a href="{{ route('app.jobs.show', $alert['incident_id']) }}">View incident</a>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endif

    <div class="ops-stat-grid">
        @foreach ($jobStats as $stat)
            <article class="ops-stat-card ops-stat-{{ $stat['tone'] }}">
                <span class="ops-stat-icon">{{ $stat['icon'] }}</span>
                <div><span>{{ $stat['label'] }}</span><strong>{{ $preview || $stat['value'] === null ? '—' : number_format($stat['value']) }}</strong></div>
            </article>
        @endforeach
    </div>

    <section class="ops-panel ops-incidents-panel">
        <div class="ops-panel-heading ops-incidents-titlebar">
            <div class="ops-incidents-title-main">
                <div><h2>Recent incidents</h2><p>Latest incidents in your authorized scope</p></div>
                <nav class="ops-incident-filters" aria-label="Filter recent incidents">
                    @foreach (['open' => 'Open', 'closed' => 'Closed', 'pending' => 'Pending', 'on_site' => 'On Site', 'en_route' => 'En Route'] as $filter => $label)
                        <a href="{{ route('dashboard', array_merge(request()->except(['status_filter', 'incidents_page']), ['status_filter' => $filter])) }}" class="ops-incident-filter {{ $incidentFilter === $filter ? 'is-active' : '' }}" @if ($incidentFilter === $filter) aria-current="page" @endif>
                            {{ $label }} <span>{{ number_format($incidentFilterCounts[$filter] ?? 0) }}</span>
                        </a>
                    @endforeach
                </nav>
                <form class="ops-table-search ops-incident-search" method="GET" action="{{ route('dashboard') }}">
                    <input type="hidden" name="status_filter" value="{{ $incidentFilter }}">
                    <input type="hidden" name="vehicle_filter" value="{{ $vehicleFilter }}">
                    @if ($mapWardId)<input type="hidden" name="map_ward_id" value="{{ $mapWardId }}">@endif
                    @if (request()->filled('vehicle_search'))<input type="hidden" name="vehicle_search" value="{{ $vehicleSearch }}">@endif
                    <label class="ops-visually-hidden" for="incident-search">Search incidents</label>
                    <input id="incident-search" type="search" name="incident_search" value="{{ $incidentSearch }}" placeholder="Search incident…">
                    <button type="submit" aria-label="Search incidents">⌕</button>
                </form>
            </div>
            <a class="ops-incidents-view-all" href="{{ route('app.jobs') }}">View all incidents <span aria-hidden="true">→</span></a>
        </div>
        <div class="ops-incidents-toolbar">
            <div class="ops-incidents-toolbar-tools">
                @if ($canViewIncidentMap)
                    <div class="ops-view-toggle" role="group" aria-label="Recent incidents view">
                        <button type="button" class="is-active" data-incident-view="table" aria-pressed="true">Table View</button>
                        <button type="button" data-incident-view="map" aria-pressed="false">Map View</button>
                    </div>
                @endif
            </div>
        </div>
        <div class="ops-table-wrap ops-incidents-table-wrap" data-incident-view-panel="table">
            <table class="ops-table ops-incidents-table">
                <thead>
                    <tr><th>#</th><th>Priority</th><th>Incident details</th><th>Caller name / phone</th><th>Ward</th><th>Assigned vehicle</th><th>Current status</th><th>Elapsed time</th><th>Action</th></tr>
                </thead>
                <tbody>
                    @forelse ($recentJobs as $job)
                        @php($assignment = $job->assignments->first())
                        <tr data-incident-row="{{ $job->job_id }}" data-map-incident="{{ $job->job_id }}" data-map-ward-id="{{ $job->ward_id }}">
                            <td>{{ $loop->iteration }}</td>
                            <td><span class="ops-incident-priority is-{{ strtolower($job->priority) }}">{{ str_replace('_', ' ', $job->priority) }}</span></td>
                            <td>
                                <a class="ops-incident-link" href="{{ route('app.jobs.show', $job) }}">{{ str_replace('_', ' ', $job->incident_type) }}</a>
                                <span class="ops-incident-location">
                                    @if (auth()->user()?->hasPermission('gps.view') && auth()->user()?->hasPermission('jobs.view') && $job->latitude !== null && $job->longitude !== null)
                                        <a class="ops-incident-map-link" href="{{ route('app.tracking', ['incident_id' => $job->id]) }}" aria-label="Show {{ $job->job_id }} location on map" title="Show location on map">
                                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12Z"/><circle cx="12" cy="9" r="2.4"/></svg>
                                        </a>
                                    @endif
                                    <small>{{ $job->incident_address }}</small>
                                </span>
                            </td>
                            <td><strong>{{ $job->caller_name }}</strong><small>{{ $job->caller_phone }}</small></td>
                            <td><span class="ops-ward-badge {{ $job->ward_id ? 'is-tone-'.($job->ward_id % 5) : 'is-unassigned' }}">{{ $job->ward?->name ?? '—' }}</span></td>
                            <td>
                                @if ($assignment)
                                    <strong>{{ $assignment->vehicle?->vehicle_number ?? '—' }}</strong>
                                    <small>{{ $assignment->fireStation?->name ?? 'Station not set' }}</small>
                                @else
                                    <span class="ops-muted-value">Unassigned</span>
                                @endif
                            </td>
                            <td><span class="ops-incident-status is-{{ strtolower($job->status) }}">{{ str_replace('_', ' ', $job->status) }}</span></td>
                            <td><time datetime="{{ $job->occurred_at->toIso8601String() }}">{{ $job->occurred_at->diffForHumans(now(), true) }}</time></td>
                            <td><a class="ops-incident-action" href="{{ route('app.jobs.show', $job) }}">View <span aria-hidden="true">→</span></a></td>
                        </tr>
                    @empty
                        <tr><td class="ops-incidents-empty" colspan="9">{{ $preview ? 'Sign in to view recent incidents.' : 'No incidents match this filter.' }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($canViewIncidentMap)
        <div class="ops-incidents-map-view" data-incident-view-panel="map" hidden>
            <link rel="stylesheet" href="{{ asset('css/dashboard-live-map.css') }}?v={{ filemtime(public_path('css/dashboard-live-map.css')) }}">
            <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
            <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
            <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
            <div class="ops-live-map-toolbar">
                <label class="ops-map-ward-filter">Ward
                    <select data-map-ward-filter>
                        <option value="">All wards</option>
                        @foreach ($mapWards as $ward)
                            <option value="{{ $ward->id }}" @selected((string) $mapWardId === (string) $ward->id)>{{ $ward->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="ops-map-search">Vehicle
                    <input type="search" data-map-search-vehicle placeholder="Vehicle no.">
                </label>
                <label class="ops-map-search">Incident
                    <input type="search" data-map-search-incident placeholder="Incident ID">
                </label>
                <div class="ops-map-control-group" role="group" aria-label="Map layers and display">
                    <button type="button" data-map-layer="vehicles" aria-pressed="true">Vehicles</button>
                    <button type="button" data-map-layer="incidents" aria-pressed="true">Incidents</button>
                    <button type="button" data-map-layer="density" aria-pressed="true">Density</button>
                    <button type="button" data-map-fullscreen>Fullscreen</button>
                    <button type="button" data-map-satellite disabled title="Satellite imagery provider is not configured">Satellite</button>
                    <button type="button" data-map-boundaries disabled title="Ward boundary data is not available">Ward boundaries</button>
                    <button type="button" data-map-refresh>Refresh map</button>
                </div>
            </div>
            <div class="ops-live-map-shell">
                <div class="ops-live-map" id="ops-live-map" role="application" aria-label="Live vehicle and incident map"
                    data-map-endpoint="{{ route('dashboard.map-data') }}"
                    data-map-payload="{{ json_encode($liveMapData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}">
                </div>
                <p class="ops-live-map-message" data-map-message role="status" hidden></p>
            </div>
            <div class="ops-live-map-legend" aria-label="Map legend">
                <span><i class="is-incident-critical"></i> Critical incident</span>
                <span><i class="is-incident-high"></i> High incident</span>
                <span><i class="is-incident-medium"></i> Medium incident</span>
                <span><i class="is-incident-low"></i> Low incident</span>
                <span><i class="is-vehicle-on-road"></i> On Road / Moving</span>
                <span><i class="is-vehicle-off-road"></i> Off Road</span>
                <span><i class="is-vehicle-assigned"></i> Assigned</span>
                <span><i class="is-vehicle-idle"></i> Idle / Free</span>
                <span><i class="is-vehicle-at-incident"></i> At Incident / On Site</span>
                <span><i class="is-density"></i> Ward density from geocoded incidents</span>
            </div>
            <p class="ops-live-map-attribution">Map data © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>. Density circles use the geocoded incidents shown here, not ward boundaries.</p>
            <script defer src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
            <script defer src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
            <script defer src="{{ asset('dashboard-live-map.js') }}?v={{ filemtime(public_path('dashboard-live-map.js')) }}"></script>
        </div>
        @endif
        <div class="ops-table-pagination">
            @if ($recentJobs instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                <span>Showing {{ $recentJobs->firstItem() ?? 0 }}–{{ $recentJobs->lastItem() ?? 0 }} of {{ number_format($recentJobs->total()) }} incidents</span>
                {{ $recentJobs->links() }}
            @else
                <span>{{ $recentJobs->count() }} incidents</span>
            @endif
        </div>
    </section>

    @if (auth()->user()?->hasPermission('vehicles.view'))
        <section class="ops-panel ops-vehicles-panel">
            <div class="ops-panel-heading">
                <div><h2>Vehicles</h2><p>Fleet readiness and latest known location</p></div>
                <div class="ops-vehicle-header-tools">
                    <nav class="ops-vehicle-tabs" aria-label="Filter vehicles">
                        @foreach (['all' => 'All', 'on_road' => 'On Road', 'off_road' => 'Off Road', 'assigned' => 'Assigned', 'idle' => 'Idle', 'at_incident' => 'At Incident'] as $filter => $label)
                            <a href="{{ route('dashboard', array_merge(request()->except(['vehicle_filter', 'vehicles_page']), ['vehicle_filter' => $filter])) }}" class="ops-vehicle-tab {{ $vehicleFilter === $filter ? 'is-active' : '' }}" @if ($vehicleFilter === $filter) aria-current="page" @endif>
                                {{ $label }} <span>{{ number_format($vehicleFilterCounts[$filter] ?? 0) }}</span>
                            </a>
                        @endforeach
                    </nav>
                    <form class="ops-table-search" method="GET" action="{{ route('dashboard') }}">
                        <input type="hidden" name="status_filter" value="{{ $incidentFilter }}">
                        <input type="hidden" name="vehicle_filter" value="{{ $vehicleFilter }}">
                        @if ($mapWardId)<input type="hidden" name="map_ward_id" value="{{ $mapWardId }}">@endif
                        @if (request()->filled('incident_search'))<input type="hidden" name="incident_search" value="{{ $incidentSearch }}">@endif
                        <label class="ops-visually-hidden" for="vehicle-search">Search vehicles</label>
                        <input id="vehicle-search" type="search" name="vehicle_search" value="{{ $vehicleSearch }}" placeholder="Search vehicle…">
                        <button type="submit" aria-label="Search vehicles">⌕</button>
                    </form>
                </div>
                <a href="{{ route('app.vehicles') }}">View all vehicles →</a>
            </div>
            <div class="ops-table-wrap">
                <table class="ops-table ops-vehicles-table">
                    <thead><tr><th>#</th><th>Vehicle no.</th><th>Status</th><th>Open tickets</th><th>Station</th><th>Last known location</th><th>Last update</th><th>Action</th></tr></thead>
                    <tbody>
                        @forelse ($dashboardVehicles as $vehicle)
                            @php($gpsLog = $vehicle->latestGpsLog)
                            @php($mapAssignment = $vehicle->assignments->first())
                            @php($displayStatus = \App\Services\VehicleDisplayStatus::describe($vehicle, $mapAssignment))
                            <tr data-vehicle-row="{{ $vehicle->vehicle_number }}" data-map-vehicle="{{ $vehicle->vehicle_number }}" data-map-ward-id="{{ $mapAssignment?->emergencyJob?->ward_id }}">
                                <td>{{ $loop->iteration }}</td>
                                <td><strong>{{ $vehicle->vehicle_number }}</strong><small>{{ str_replace('_', ' ', $vehicle->vehicle_type) }}</small></td>
                                <td><span class="vehicle-status-badge is-{{ $displayStatus['class'] }}">{{ $displayStatus['label'] }}</span></td>
                                <td>
                                    @if (auth()->user()?->hasPermission('jobs.view'))
                                        <a class="vehicle-ticket-count" href="{{ url('/vehicles/'.$vehicle->id.'#open-assignments') }}" aria-label="View {{ $vehicle->open_tickets_count }} open tickets for {{ $vehicle->vehicle_number }}">{{ number_format($vehicle->open_tickets_count) }}</a>
                                    @else
                                        <span class="ops-muted-value">Not permitted</span>
                                    @endif
                                </td>
                                <td>{{ $vehicle->fireStation?->name ?? '—' }}</td>
                                <td>
                                    @if ($gpsLog)
                                        {{ number_format((float) $gpsLog->latitude, 5) }}, {{ number_format((float) $gpsLog->longitude, 5) }}
                                    @elseif ($vehicle->latitude !== null && $vehicle->longitude !== null)
                                        {{ number_format((float) $vehicle->latitude, 5) }}, {{ number_format((float) $vehicle->longitude, 5) }}
                                    @else
                                        <span class="ops-muted-value">Location unavailable</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($gpsLog)
                                        <time datetime="{{ $gpsLog->recorded_at->toIso8601String() }}">{{ $gpsLog->recorded_at->format('M j, H:i') }}</time>
                                    @else
                                        <span class="ops-muted-value">No GPS update</span>
                                    @endif
                                </td>
                                <td><a class="ops-incident-action" href="{{ url('/vehicles/'.$vehicle->id) }}">View Details <span aria-hidden="true">→</span></a></td>
                            </tr>
                        @empty
                            <tr><td class="ops-incidents-empty" colspan="8">No vehicles are available in your access scope.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="ops-table-pagination">
                @if ($dashboardVehicles instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                    <span>Showing {{ $dashboardVehicles->firstItem() ?? 0 }}–{{ $dashboardVehicles->lastItem() ?? 0 }} of {{ number_format($dashboardVehicles->total()) }} vehicles</span>
                    <nav class="ops-vehicle-pagination" aria-label="Vehicle pagination">
                        @if ($dashboardVehicles->onFirstPage())
                            <span aria-disabled="true" aria-label="Previous page"><span aria-hidden="true">&lsaquo;</span></span>
                        @else
                            <a href="{{ $dashboardVehicles->previousPageUrl() }}" rel="prev" aria-label="Previous page"><span aria-hidden="true">&lsaquo;</span></a>
                        @endif
                        @if ($dashboardVehicles->currentPage() > 2)
                            <a href="{{ $dashboardVehicles->url(1) }}">1</a>
                            @if ($dashboardVehicles->currentPage() > 3)<span aria-hidden="true"><span>&hellip;</span></span>@endif
                        @endif
                        @for ($page = max(1, $dashboardVehicles->currentPage() - 1); $page <= min($dashboardVehicles->lastPage(), $dashboardVehicles->currentPage() + 1); $page++)
                            @if ($page === $dashboardVehicles->currentPage())
                                <span aria-current="page"><span>{{ $page }}</span></span>
                            @else
                                <a href="{{ $dashboardVehicles->url($page) }}" aria-label="Go to page {{ $page }}">{{ $page }}</a>
                            @endif
                        @endfor
                        @if ($dashboardVehicles->currentPage() < $dashboardVehicles->lastPage() - 1)
                            @if ($dashboardVehicles->currentPage() < $dashboardVehicles->lastPage() - 2)<span aria-hidden="true"><span>&hellip;</span></span>@endif
                            <a href="{{ $dashboardVehicles->url($dashboardVehicles->lastPage()) }}">{{ $dashboardVehicles->lastPage() }}</a>
                        @endif
                        @if ($dashboardVehicles->hasMorePages())
                            <a href="{{ $dashboardVehicles->nextPageUrl() }}" rel="next" aria-label="Next page"><span aria-hidden="true">&rsaquo;</span></a>
                        @else
                            <span aria-disabled="true" aria-label="Next page"><span aria-hidden="true">&rsaquo;</span></span>
                        @endif
                    </nav>
                @else
                    <span>{{ $dashboardVehicles->count() }} vehicles</span>
                @endif
            </div>
        </section>
    @endif
</section>
<script>
(() => {
    const panel = document.querySelector('.ops-incidents-panel');
    if (!panel) return;

    const buttons = panel.querySelectorAll('[data-incident-view]');
    const views = panel.querySelectorAll('[data-incident-view-panel]');
    if (!buttons.length || !views.length) return;

    buttons.forEach((button) => {
        button.addEventListener('click', () => {
            const selectedView = button.dataset.incidentView;

            buttons.forEach((viewButton) => {
                const isSelected = viewButton === button;
                viewButton.classList.toggle('is-active', isSelected);
                viewButton.setAttribute('aria-pressed', String(isSelected));
            });
            views.forEach((view) => {
                view.hidden = view.dataset.incidentViewPanel !== selectedView;
            });
            try {
                localStorage.setItem('vmc-dashboard-map-view', selectedView);
            } catch (error) {
                console.warn('Dashboard map view preference could not be saved.', error);
            }
            if (selectedView === 'map') {
                window.dispatchEvent(new Event('vmc-map-visible'));
            }
        });
    });

    try {
        if (localStorage.getItem('vmc-dashboard-map-view') === 'map') {
            panel.querySelector('[data-incident-view="map"]')?.click();
        }
    } catch (error) {
        console.warn('Dashboard map view preference could not be read.', error);
    }
})();
</script>
@if (auth()->user()?->hasPermission('jobs.view'))
<script>
(() => {
    const panel = document.querySelector('[data-help-requests]');
    if (!panel) return;

    const list = panel.querySelector('[data-help-alert-list]');
    const count = panel.querySelector('[data-help-request-count]');
    const renderAlert = (alert) => {
        const item = document.createElement('article');
        item.className = 'ops-help-alert';
        const icon = document.createElement('span');
        icon.className = 'ops-help-alert-icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = '!';
        item.append(icon);

        const details = document.createElement('div');
        details.className = 'ops-help-alert-details';
        const title = document.createElement('strong');
        title.textContent = (alert.help_type || alert.reason || 'Help requested')
            .replace(/_/g, ' ')
            .toLowerCase()
            .replace(/\b\w/g, (letter) => letter.toUpperCase());
        const summary = document.createElement('span');
        summary.textContent = [
            alert.driver_name || 'Driver',
            alert.team_name || 'No team assigned',
            alert.vehicle_number || 'Vehicle unavailable',
            alert.job_id || 'Incident unavailable',
        ].join(' · ');
        details.append(title, summary);
        if (alert.comments) {
            const comments = document.createElement('small');
            comments.textContent = alert.comments;
            details.append(comments);
        }
        item.append(details);

        const meta = document.createElement('div');
        meta.className = 'ops-help-alert-meta';
        const time = document.createElement('time');
        time.dateTime = alert.requested_at || alert.created_at || '';
        time.textContent = alert.created_at ? new Date(alert.created_at).toLocaleString() : 'Just now';
        meta.append(time);
        if (alert.incident_url) {
            const link = document.createElement('a');
            link.href = alert.incident_url;
            link.textContent = 'View incident';
            meta.append(link);
        }
        item.append(meta);

        return item;
    };

    const refreshHelpRequests = async () => {
        try {
            const response = await fetch(panel.dataset.helpRequestsEndpoint, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) {
                throw new Error(`Help request refresh failed with HTTP ${response.status}`);
            }
            const result = await response.json();
            const requests = result.data || [];
            list.replaceChildren(...requests.map(renderAlert));
            count.textContent = `${requests.length} recent`;
            panel.hidden = requests.length === 0;
        } catch (error) {
            console.error('Dashboard help requests could not be refreshed.', error);
        }
    };

    refreshHelpRequests();
    window.setInterval(refreshHelpRequests, 10000);
})();
</script>
@endif
@endsection
