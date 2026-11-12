@extends('layouts.app')

@section('title', 'Live GPS tracking')

@section('content')
<section class="page-stack tracking-page">
    <div class="page-heading">
        <div>
            <span class="eyebrow">FIELD OPERATIONS · LIVE FLEET</span>
            <h1>Live GPS tracking</h1>
            <p class="muted">Authenticated vehicle locations and assignment route history. Positions stay within your station access.</p>
        </div>
        <span class="tracking-live"><i></i> GPS feed</span>
    </div>

    <section class="tracking-layout">
        <div class="panel tracking-map-panel">
            <div class="tracking-panel-heading">
                <div><h2>{{ $selectedIncident ? 'Incident location map' : 'Fleet location map' }}</h2><p>{{ $selectedIncident ? $selectedIncident->job_id.' · '.$selectedIncident->incident_address : 'Vehicle GPS locations refresh automatically every 15 seconds.' }}</p></div>
                <span class="tracking-count">{{ $vehicles->count() }} tracked {{ \Illuminate\Support\Str::plural('vehicle', $vehicles->count()) }}</span>
            </div>
            <link rel="stylesheet" href="{{ asset('css/dashboard-live-map.css') }}?v={{ filemtime(public_path('css/dashboard-live-map.css')) }}">
            <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
            <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
            <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
            <div class="operations-dashboard-layout ops-tracking-map-scope">
                <div class="ops-live-map-toolbar">
                    <label class="ops-map-search">Vehicle
                        <input type="search" data-map-search-vehicle placeholder="Search vehicle no.">
                    </label>
                    <div class="ops-map-control-group" role="group" aria-label="Map layers and display">
                        <button type="button" data-map-layer="vehicles" aria-pressed="true">Vehicles</button>
                        @if (auth()->user()?->hasPermission('jobs.view'))
                            <button type="button" data-map-layer="incidents" aria-pressed="true">Incidents</button>
                            <button type="button" data-map-layer="density" aria-pressed="true">Density</button>
                        @endif
                        <button type="button" data-map-fullscreen>Fullscreen</button>
                        <button type="button" data-map-satellite disabled title="Satellite imagery provider is not configured">Satellite</button>
                        <button type="button" data-map-boundaries disabled title="Ward boundary data is not available">Ward boundaries</button>
                        <button type="button" data-map-refresh>Refresh map</button>
                    </div>
                </div>
                <div class="ops-live-map-shell">
                    <div class="ops-live-map" id="ops-live-map" role="application" aria-label="Live vehicle GPS locations"
                        data-map-endpoint="{{ $mapRefreshUrl }}"
                        data-map-auto-refresh="15000"
                        data-map-selected-vehicle="{{ $selectedVehicle?->vehicle_number }}"
                        data-map-selected-incident="{{ $selectedIncident?->job_id }}"
                        data-map-payload="{{ json_encode($mapData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) }}">
                    </div>
                    <p class="ops-live-map-message" data-map-message role="status" hidden></p>
                </div>
                <div class="ops-live-map-legend" aria-label="Map legend">
                    <span><i class="is-vehicle-on-road"></i> On Road / Moving</span>
                    <span><i class="is-vehicle-off-road"></i> Off Road</span>
                    <span><i class="is-vehicle-assigned"></i> Assigned</span>
                    <span><i class="is-vehicle-idle"></i> Idle / Free</span>
                    <span><i class="is-vehicle-at-incident"></i> At Incident / On Site</span>
                    @if (auth()->user()?->hasPermission('jobs.view'))
                        <span><i class="is-incident-critical"></i> Critical incident</span>
                        <span><i class="is-incident-high"></i> High incident</span>
                        <span><i class="is-incident-medium"></i> Medium incident</span>
                        <span><i class="is-incident-low"></i> Low incident</span>
                        <span><i class="is-density"></i> Ward incident density</span>
                    @endif
                </div>
                <p class="ops-live-map-attribution">Map data © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>. Positions show the latest authenticated GPS update.</p>
            </div>
            <script defer src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
            <script defer src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
            <script defer src="{{ asset('dashboard-live-map.js') }}?v={{ filemtime(public_path('dashboard-live-map.js')) }}"></script>
        </div>

        <aside class="panel tracking-units-panel">
            <div class="tracking-panel-heading"><div><h2>Tracked vehicles</h2><p>Most recent received location</p></div></div>
            @if ($vehicles->isNotEmpty())
                <div class="tracking-unit-list">
                    @foreach ($vehicles as $vehicle)
                        @php
                            $isStale = $vehicle->latestGpsLog->recorded_at->lt(now()->subMinutes(5));
                            $activeAssignment = $vehicle->assignments->first();
                            $displayStatus = \App\Services\VehicleDisplayStatus::describe($vehicle, $activeAssignment);
                        @endphp
                        <a class="tracking-unit {{ $selectedVehicle?->is($vehicle) ? 'is-selected' : '' }}" data-map-vehicle="{{ $vehicle->vehicle_number }}" data-map-ward-id="{{ $activeAssignment?->emergencyJob?->ward_id }}" href="{{ url('/vehicles/'.$vehicle->id) }}">
                            <span class="tracking-unit-icon">⌖</span>
                            <span class="tracking-unit-details">
                                <strong>{{ $vehicle->vehicle_number }}</strong>
                                <small>{{ $vehicle->fireStation?->name ?? 'Station unavailable' }}</small>
                                <small><span class="vehicle-status-badge is-{{ $displayStatus['class'] }}">{{ $displayStatus['label'] }}</span></small>
                                @if ($activeAssignment?->emergencyJob)
                                    <small>{{ $activeAssignment->emergencyJob->job_id }} · {{ str_replace('_', ' ', $activeAssignment->status) }}</small>
                                @endif
                            </span>
                            <span class="tracking-unit-time {{ $isStale ? 'is-stale' : '' }}">{{ $isStale ? 'STALE' : 'FRESH' }}<small>{{ $vehicle->latestGpsLog->recorded_at->diffForHumans() }}</small></span>
                        </a>
                    @endforeach
                </div>
            @else
                <p class="tracking-no-units">No vehicles in your authorized tracking scope.</p>
            @endif
        </aside>
    </section>

    @if ($selectedVehicle)
        <section class="panel tracking-history-panel">
            <div class="tracking-panel-heading">
                <div><h2>Route history · {{ $selectedVehicle->vehicle_number }}</h2><p>{{ $selectedVehicle->fireStation?->name }} · {{ $routePoints->count() }} GPS points in this range</p></div>
                <form method="GET" action="{{ route('app.tracking') }}" class="tracking-filters">
                    <input type="hidden" name="vehicle_id" value="{{ $selectedVehicle->id }}">
                    <label>Assignment
                        <select name="assignment_id">
                            <option value="">All my vehicle routes</option>
                            @foreach ($routeAssignments as $assignment)
                                <option value="{{ $assignment->id }}" @selected((string) ($filters['assignment_id'] ?? '') === (string) $assignment->id)>{{ $assignment->emergencyJob?->job_id ?? 'Job '.$assignment->emergency_job_id }} · {{ str_replace('_', ' ', $assignment->status) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>From<input type="datetime-local" name="from" value="{{ isset($filters['from']) ? \Illuminate\Support\Carbon::parse($filters['from'])->format('Y-m-d\\TH:i') : now()->subDay()->format('Y-m-d\\TH:i') }}"></label>
                    <label>To<input type="datetime-local" name="to" value="{{ isset($filters['to']) ? \Illuminate\Support\Carbon::parse($filters['to'])->format('Y-m-d\\TH:i') : now()->format('Y-m-d\\TH:i') }}"></label>
                    <button class="button button-light button-small" type="submit">Show route</button>
                </form>
            </div>
            <div class="table-scroll"><table><thead><tr><th>Recorded</th><th>Coordinates</th><th>Speed</th><th>Job</th></tr></thead><tbody>
                @forelse ($routePoints->reverse() as $point)
                    <tr>
                        <td>{{ $point['log']->recorded_at->format('M j, Y g:i:s A') }}</td>
                        <td>{{ $point['log']->latitude }}, {{ $point['log']->longitude }}</td>
                        <td>{{ $point['log']->speed_kph !== null ? $point['log']->speed_kph.' km/h' : '—' }}</td>
                        <td>{{ $point['log']->assignment?->emergencyJob?->job_id ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty-cell">No route points match this vehicle and time range.</td></tr>
                @endforelse
            </tbody></table></div>
        </section>
    @endif
</section>
@endsection
