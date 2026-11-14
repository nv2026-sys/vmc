@extends('layouts.app')

@section('title', 'Incident Reports')

@section('content')
@php
    $summary = $report['summary'];
    $filters = $report['filters'];
    $metrics = [
        ['Total Incidents', $summary['total_jobs'], 'tone-blue'],
        ['Critical Incidents', $summary['critical_jobs'], 'tone-red'],
        ['High Priority Incidents', $summary['high_jobs'], 'tone-orange'],
        ['Resolved Incidents', $summary['resolved_jobs'], 'tone-green'],
        ['Pending Incidents', $summary['pending_jobs'], 'tone-purple'],
        ['Average Response Time', $summary['average_response_minutes'] === null ? '—' : number_format($summary['average_response_minutes'], 1).' min', 'tone-cyan'],
        ['Average Resolution Time', $summary['average_resolution_minutes'] === null ? '—' : number_format($summary['average_resolution_minutes'], 1).' min', 'tone-blue'],
    ];
    $statusLabels = [
        'RECEIVED' => 'Received',
        'ASSIGNED' => 'Assigned',
        'EN_ROUTE' => 'En Route',
        'ARRIVED' => 'Arrived',
        'STARTED' => 'Started',
        'IN_PROGRESS' => 'In Progress',
        'COMPLETED' => 'Completed',
        'WORK_DONE' => 'Work Done',
        'DUPLICATE' => 'Duplicate',
        'TRANSFERRED_TO_112' => 'Transferred to 112',
    ];
    $priorityLabels = [
        'CRITICAL' => 'Critical',
        'HIGH' => 'High',
        'MEDIUM' => 'Medium',
        'LOW' => 'Low',
        'NORMAL' => 'Normal',
        'URGENT' => 'Urgent',
    ];
    $query = request()->query();
@endphp
<section class="page-stack operations-reports" data-data-url="{{ route('app.reports.data') }}">
    <div class="page-heading">
        <div>
            <span class="eyebrow">VADODARA MUNICIPAL CORPORATION · REPORTING</span>
            <h1>Incident Reports</h1>
            <p class="muted">View, Filter and Analyze Incident Records.</p>
        </div>
        <div class="report-heading-actions">
            @if (auth()->user()->hasPermission('reports.export'))
                <div class="report-export-actions" aria-label="Export report">
                    <a class="button button-light" href="{{ route('app.reports.export', ['format' => 'xlsx'] + $query) }}">Export Excel</a>
                    <a class="button button-light" href="{{ route('app.reports.export', ['format' => 'pdf'] + $query) }}">Export PDF</a>
                    <a class="button button-light" href="{{ route('app.reports.export', ['format' => 'csv'] + $query) }}">Export CSV</a>
                </div>
            @endif
            <button class="button button-light" type="button" data-report-print>Print Report</button>
            <span class="report-period">{{ \Illuminate\Support\Carbon::parse($filters['from'])->format('M j') }} – {{ \Illuminate\Support\Carbon::parse($filters['to'])->format('M j, Y') }}</span>
        </div>
    </div>
    @if ($errors->any()) <div class="alert" role="alert">{{ $errors->first() }}</div> @endif

    <form method="GET" action="{{ route('app.reports') }}" class="panel report-filter-form" data-report-filters>
        <label>From Date<input type="date" name="from" value="{{ $filters['from'] }}" required></label>
        <label>To Date<input type="date" name="to" value="{{ $filters['to'] }}" required></label>
        <label>Ward
            <select name="ward_id">
                <option value="">All Wards</option>
                @foreach ($report['wards_available'] as $ward)
                    <option value="{{ $ward['id'] }}" @selected($filters['ward_id'] === $ward['id'])>{{ $ward['name'] }}</option>
                @endforeach
            </select>
        </label>
        <label>Area / Location<input type="search" name="area" value="{{ $filters['area'] }}" maxlength="150" placeholder="Search address or area"></label>
        <label>Incident Type
            <select name="incident_type">
                <option value="">All Types</option>
                @foreach ($report['incident_types_available'] as $type)
                    <option value="{{ $type }}" @selected($filters['incident_type'] === $type)>{{ str_replace('_', ' ', $type) }}</option>
                @endforeach
            </select>
        </label>
        <label>Priority
            <select name="priority">
                <option value="">All Priorities</option>
                @foreach ($report['priorities_available'] as $priority)
                    <option value="{{ $priority }}" @selected($filters['priority'] === $priority)>{{ $priorityLabels[$priority] ?? str_replace('_', ' ', $priority) }}</option>
                @endforeach
            </select>
        </label>
        <label>Incident Status
            <select name="status">
                <option value="">All Statuses</option>
                @foreach ($report['statuses_available'] as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? str_replace('_', ' ', $status) }}</option>
                @endforeach
            </select>
        </label>
        <label>Assigned Vehicle
            <select name="vehicle_id">
                <option value="">All Vehicles</option>
                @foreach ($report['vehicles_available'] as $vehicle)
                    <option value="{{ $vehicle['id'] }}" @selected($filters['vehicle_id'] === $vehicle['id'])>{{ $vehicle['vehicle_number'] }}</option>
                @endforeach
            </select>
        </label>
        <label>Caller Name / Phone<input type="search" name="caller" value="{{ $filters['caller'] }}" maxlength="120" placeholder="Search caller"></label>
        <label>Fire Station
            <select name="station_id">
                <option value="">All accessible stations</option>
                @foreach ($report['stations_available'] as $station)
                    <option value="{{ $station['id'] }}" @selected($filters['station_id'] === $station['id'])>{{ $station['name'] }} · {{ $station['code'] }}</option>
                @endforeach
            </select>
        </label>
        <div class="report-filter-actions">
            <button class="button button-primary" type="submit">Generate Report</button>
            <a class="button button-light" data-report-reset href="{{ route('app.reports') }}">Reset Filters</a>
        </div>
    </form>

    <div class="report-stat-grid report-stat-grid-seven">
        @foreach ($metrics as [$label, $value, $tone])
            <article class="report-stat-card"><span class="report-stat-icon {{ $tone }}">▦</span><div><small>{{ $label }}</small><strong>{{ is_numeric($value) ? number_format($value) : $value }}</strong></div></article>
        @endforeach
    </div>

    <section class="panel report-data-panel">
        <div class="report-panel-heading">
            <div><h2>Incident Summary Report</h2><p>Server-paginated incidents matching the selected filters</p></div>
            <label class="report-table-search">Search this report<input type="search" data-report-search placeholder="Search incident, caller, ward…"></label>
        </div>
        <div class="report-table-status" data-report-status role="status" aria-live="polite"></div>
        <div class="table-scroll report-table-scroll">
            <table class="report-record-table">
                <thead><tr>
                    <th>Sr. No.</th><th data-order-column="1">Incident ID</th><th data-order-column="2">Incident Date &amp; Time</th><th data-order-column="3">Incident Type</th><th data-order-column="4">Incident Details</th><th data-order-column="5">Location</th><th data-order-column="6">Ward</th><th data-order-column="7">Caller Name</th><th data-order-column="8">Caller Phone</th><th data-order-column="9">Priority</th><th data-order-column="10">Assigned Vehicle</th><th>Vehicle Status</th><th data-order-column="12">Incident Status</th><th>Response Time</th><th>Elapsed Time</th><th>Resolution Time</th><th>Action</th>
                </tr></thead>
                <tbody data-report-rows><tr><td colspan="17" class="empty-cell">Loading incident records…</td></tr></tbody>
            </table>
        </div>
        <div class="report-table-pagination">
            <span data-report-count>—</span>
            <div><button type="button" data-report-prev disabled>Previous</button><span data-report-page>Page 1</span><button type="button" data-report-next disabled>Next</button></div>
        </div>
    </section>

    <section class="panel report-data-panel">
        <div class="report-panel-heading"><div><h2>Ward-wise Incident Report</h2><p>Counts and recorded response/resolution times by ward</p></div></div>
        <div class="table-scroll"><table><thead><tr><th>Sr. No.</th><th>Ward Name</th><th>Total Incidents</th><th>Critical</th><th>High</th><th>Medium</th><th>Low</th><th>Resolved</th><th>Pending</th><th>Avg. Response</th><th>Avg. Resolution</th><th>Action</th></tr></thead>
            <tbody>
                @forelse ($report['wards'] as $index => $ward)
                    <tr><td>{{ $index + 1 }}</td><td>{{ $ward['ward'] }}</td><td>{{ $ward['total'] }}</td><td>{{ $ward['critical'] }}</td><td>{{ $ward['high'] }}</td><td>{{ $ward['medium'] }}</td><td>{{ $ward['low'] }}</td><td>{{ $ward['resolved'] }}</td><td>{{ $ward['pending'] }}</td><td>{{ $ward['average_response'] === null ? '—' : number_format($ward['average_response'], 1).' min' }}</td><td>{{ $ward['average_resolution'] === null ? '—' : number_format($ward['average_resolution'], 1).' min' }}</td><td>@if ($ward['ward_id'])<a class="button button-light button-small" href="{{ route('app.reports', array_merge($query, ['ward_id' => $ward['ward_id']])) }}">View Details</a>@else — @endif</td></tr>
                @empty
                    <tr><td colspan="12" class="empty-cell">No ward records match these filters.</td></tr>
                @endforelse
            </tbody>
        </table></div>
    </section>

    <div class="report-breakdown-grid report-breakdown-grid-two">
        <section class="panel report-data-panel">
            <div class="report-panel-heading"><div><h2>Incident Type-wise Report</h2><p>Actual incident types in the selected records</p></div></div>
            <div class="table-scroll"><table><thead><tr><th>Incident Type</th><th>Total Cases</th><th>Resolved</th><th>Pending</th><th>Avg. Response</th><th>% Total</th></tr></thead>
                <tbody>@forelse ($report['type_reports'] as $item)<tr><td>{{ $item['type'] }}</td><td>{{ $item['total'] }}</td><td>{{ $item['resolved'] }}</td><td>{{ $item['pending'] }}</td><td>{{ $item['average_response'] === null ? '—' : number_format($item['average_response'], 1).' min' }}</td><td>{{ number_format($item['percentage'], 1) }}%</td></tr>@empty<tr><td colspan="6" class="empty-cell">No incident types match these filters.</td></tr>@endforelse</tbody>
            </table></div>
        </section>
        <section class="panel report-data-panel">
            <div class="report-panel-heading"><div><h2>Priority-wise Report</h2><p>Resolved and pending counts by recorded priority</p></div></div>
            <div class="table-scroll"><table><thead><tr><th>Priority</th><th>Total Cases</th><th>Resolved</th><th>Pending</th></tr></thead>
                <tbody>@forelse ($report['priority_reports'] as $item)<tr><td><span class="report-priority-badge is-{{ \Illuminate\Support\Str::slug($item['priority']) }}">{{ $priorityLabels[$item['priority']] ?? $item['priority'] }}</span></td><td>{{ $item['total'] }}</td><td>{{ $item['resolved'] }}</td><td>{{ $item['pending'] }}</td></tr>@empty<tr><td colspan="4" class="empty-cell">No priority records match these filters.</td></tr>@endforelse</tbody>
            </table></div>
        </section>
    </div>

    <section class="panel report-data-panel">
        <div class="report-panel-heading"><div><h2>Vehicle-wise Incident Report</h2><p>Assignment and working-duration data from recorded vehicle timelines</p></div></div>
        <div class="table-scroll"><table><thead><tr><th>Vehicle Number</th><th>Assigned Incidents</th><th>Completed</th><th>Pending</th><th>Avg. Pickup</th><th>Avg. Response</th><th>Avg. Resolution</th><th>Total Working Hours</th><th>Action</th></tr></thead>
            <tbody>@forelse ($report['vehicle_reports'] as $vehicle)<tr><td><a href="{{ route('app.reports', array_merge($query, ['vehicle_id' => $vehicle['vehicle_id']])) }}">{{ $vehicle['vehicle'] }}</a></td><td>{{ $vehicle['total'] }}</td><td>{{ $vehicle['completed'] }}</td><td>{{ $vehicle['pending'] }}</td><td>{{ $vehicle['average_pickup'] === null ? '—' : number_format($vehicle['average_pickup'], 1).' min' }}</td><td>{{ $vehicle['average_response'] === null ? '—' : number_format($vehicle['average_response'], 1).' min' }}</td><td>{{ $vehicle['average_resolution'] === null ? '—' : number_format($vehicle['average_resolution'], 1).' min' }}</td><td>{{ number_format($vehicle['working_hours'], 1) }}</td><td><a class="button button-light button-small" href="{{ route('app.reports', array_merge($query, ['vehicle_id' => $vehicle['vehicle_id']])) }}">View Incidents</a></td></tr>@empty<tr><td colspan="9" class="empty-cell">No vehicle assignments match these filters.</td></tr>@endforelse</tbody>
        </table></div>
    </section>

    <section class="panel report-data-panel">
        <div class="report-panel-heading"><div><h2>Response Time Report</h2><p>Pickup: created to assigned · Response: created to vehicle arrival · Resolution: created to completed</p></div></div>
        <div class="table-scroll"><table><thead><tr><th>Incident ID</th><th>Ward</th><th>Incident Type</th><th>Created</th><th>Assigned</th><th>Vehicle Departure</th><th>Arrival</th><th>Pickup Time</th><th>Response Time</th><th>Resolution Time</th><th>Vehicle</th></tr></thead>
            <tbody>@forelse ($report['response_reports'] as $item)<tr><td><a href="{{ $item['view_url'] }}">{{ $item['job_id'] }}</a></td><td>{{ $item['ward'] ?? '—' }}</td><td>{{ $item['type'] }}</td><td>{{ $item['created_at']?->format('M j, Y H:i') ?? '—' }}</td><td>{{ $item['assigned_at']?->format('M j, Y H:i') ?? '—' }}</td><td>{{ $item['departure_at']?->format('M j, Y H:i') ?? '—' }}</td><td>{{ $item['arrival_at']?->format('M j, Y H:i') ?? '—' }}</td><td>{{ $item['pickup_minutes'] === null ? '—' : number_format($item['pickup_minutes'], 1).' min' }}</td><td>{{ $item['response_minutes'] === null ? '—' : number_format($item['response_minutes'], 1).' min' }}</td><td>{{ $item['resolution_minutes'] === null ? '—' : number_format($item['resolution_minutes'], 1).' min' }}</td><td>{{ $item['vehicle_number'] ?? '—' }}</td></tr>@empty<tr><td colspan="11" class="empty-cell">No incident timelines match these filters.</td></tr>@endforelse</tbody>
        </table></div>
    </section>

    <p class="report-footnote">All data respects your authorized station scope. Pickup, response, resolution and working-time values are shown only when supported by recorded assignment/status timestamps.</p>
</section>
<script defer src="{{ asset('reports.js') }}?v={{ filemtime(public_path('reports.js')) }}"></script>
@endsection
