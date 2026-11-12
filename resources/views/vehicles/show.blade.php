@extends('layouts.app')

@section('title', 'Vehicle details · '.$vehicle->vehicle_number)

@section('content')
<section class="page-stack vehicle-details-page">
    <div class="page-heading">
        <div>
            <span class="eyebrow">FIELD OPERATIONS · VEHICLE</span>
            <h1>{{ $vehicle->vehicle_number }}</h1>
            <p class="muted">{{ $vehicle->fireStation?->name ?? 'Station unavailable' }} · {{ str_replace('_', ' ', $vehicle->vehicle_type) }}</p>
        </div>
        <div class="page-heading-actions">
            <span class="vehicle-status-badge is-{{ $status['class'] }}">{{ $status['label'] }}</span>
            <a class="button button-light button-small" href="{{ url('/vehicles') }}">All vehicles</a>
            @if (auth()->user()?->hasPermission('gps.view'))
                <a class="button button-primary" href="{{ route('app.tracking', ['vehicle_id' => $vehicle->id]) }}">View on tracking map →</a>
            @endif
        </div>
    </div>

    <section class="vehicle-details-summary" aria-label="Vehicle status">
        <article class="panel vehicle-summary-card">
            <span class="eyebrow">CURRENT STATUS</span>
            <strong><span class="vehicle-status-badge is-{{ $status['class'] }}">{{ $status['label'] }}</span></strong>
            <small>Operational status is shown with the active assignment taking precedence.</small>
        </article>
        <article class="panel vehicle-summary-card">
            <span class="eyebrow">LATEST GPS UPDATE</span>
            @if ($gpsLog)
                <strong>{{ $gpsIsStale ? 'Stale' : 'Fresh' }}</strong>
                <small><time datetime="{{ $gpsLog->recorded_at->toIso8601String() }}">{{ $gpsLog->recorded_at->format('M j, Y g:i A') }} ({{ $gpsLog->recorded_at->diffForHumans() }})</time></small>
            @else
                <strong>Unavailable</strong>
                <small>No GPS update has been recorded.</small>
            @endif
        </article>
        <article class="panel vehicle-summary-card">
            <span class="eyebrow">LAST KNOWN GPS POSITION</span>
            @if ($gpsLog)
                <strong>{{ number_format((float) $gpsLog->latitude, 5) }}, {{ number_format((float) $gpsLog->longitude, 5) }}</strong>
                <small>Recorded position; no location is inferred.</small>
            @else
                <strong>Location unavailable</strong>
                <small>Waiting for a recorded GPS position.</small>
            @endif
        </article>
        <article class="panel vehicle-summary-card">
            <span class="eyebrow">OPEN ASSIGNMENTS</span>
            <strong>{{ $assignments->count() }}</strong>
            <small>{{ \Illuminate\Support\Str::plural('active assignment', $assignments->count()) }}</small>
        </article>
    </section>

    <section class="panel vehicle-detail-panel" id="open-assignments">
        <div class="vehicle-section-heading">
            <div><h2>Open assignments</h2><p>Active assignments for this vehicle</p></div>
            <span>{{ $assignments->count() }} {{ \Illuminate\Support\Str::plural('ticket', $assignments->count()) }}</span>
        </div>
        <div class="table-scroll">
            <table class="vehicle-detail-table">
                <thead><tr><th>Incident</th><th>Assignment status</th><th>Assigned</th><th>Team / driver</th><th>Action</th></tr></thead>
                <tbody>
                    @forelse ($assignments as $assignment)
                        <tr>
                            <td>
                                @if ($canViewJobs && $assignment->emergencyJob)
                                    <strong><a class="text-link" href="{{ route('app.jobs.show', $assignment->emergencyJob) }}">{{ $assignment->emergencyJob->job_id }}</a></strong>
                                    <small>{{ str_replace('_', ' ', $assignment->emergencyJob->incident_type) }} · {{ $assignment->emergencyJob->ward?->name ?? 'Ward unavailable' }}</small>
                                @else
                                    <strong>Assignment #{{ $assignment->id }}</strong>
                                @endif
                            </td>
                            <td><span class="vehicle-status-badge is-{{ \Illuminate\Support\Str::slug($assignment->status, '_') }}">{{ str_replace('_', ' ', $assignment->status) }}</span></td>
                            <td>{{ $assignment->assigned_at?->format('M j, Y g:i A') ?? 'Not recorded' }}</td>
                            <td>{{ $assignment->team?->name ?? 'Team unavailable' }}<small>{{ $assignment->assignedDriver?->name ?? 'Driver unavailable' }}</small></td>
                            <td>
                                @if ($canViewJobs && $assignment->emergencyJob)
                                    <a class="text-link" href="{{ route('app.jobs.show', $assignment->emergencyJob) }}">View Details</a>
                                @else
                                    <span class="muted">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td class="empty-cell" colspan="5">No open assignments for this vehicle.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($canViewJobs)
        <section class="panel vehicle-detail-panel" id="recently-closed-tickets">
            <div class="vehicle-section-heading">
                <div><h2>Recently closed tickets</h2><p>Latest close first · assignments previously handled by this vehicle</p></div>
                @if ($closedIncidents instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator)
                    <span>{{ number_format($closedIncidents->total()) }} tickets</span>
                @endif
            </div>
            <div class="table-scroll">
                <table class="vehicle-detail-table">
                    <thead><tr><th>Ticket</th><th>Incident</th><th>Ward</th><th>Closed at</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                        @forelse ($closedIncidents as $incident)
                            @php($closedStatus = $incident->statusHistory->first())
                            <tr>
                                <td><strong>{{ $incident->job_id }}</strong></td>
                                <td>{{ str_replace('_', ' ', $incident->incident_type) }}</td>
                                <td>{{ $incident->ward?->name ?? '—' }}</td>
                                <td>
                                    @if ($incident->last_closed_at)
                                        <time datetime="{{ \Illuminate\Support\Carbon::parse($incident->last_closed_at)->toIso8601String() }}">{{ \Illuminate\Support\Carbon::parse($incident->last_closed_at)->format('M j, Y g:i A') }}</time>
                                    @else
                                        <span class="muted">Close time unavailable</span>
                                    @endif
                                </td>
                                <td><span class="vehicle-status-badge is-closed">{{ str_replace('_', ' ', $closedStatus?->new_status ?? $incident->status) }}</span></td>
                                <td><a class="text-link" href="{{ route('app.jobs.show', $incident) }}">View Details</a></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="6">No closed tickets are associated with this vehicle.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($closedIncidents instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $closedIncidents->hasPages())
                <div class="vehicle-pagination">
                    <span>Showing {{ $closedIncidents->firstItem() }}–{{ $closedIncidents->lastItem() }} of {{ number_format($closedIncidents->total()) }}</span>
                    {{ $closedIncidents->fragment('recently-closed-tickets')->links() }}
                </div>
            @endif
        </section>
    @endif

    @if ($canViewJobs)
        <section class="panel vehicle-detail-panel">
            <div class="vehicle-section-heading"><div><h2>Assignment / incident status history</h2><p>Recorded status changes only</p></div></div>
            <div class="vehicle-history-list">
                @forelse ($assignmentHistory as $history)
                    <article class="vehicle-history-entry">
                        <span class="vehicle-history-marker"></span>
                        <div>
                            <strong>{{ str_replace('_', ' ', $history->previous_status ?: 'Created') }} → {{ str_replace('_', ' ', $history->new_status) }}</strong>
                            <p>
                                @if ($history->assignment?->emergencyJob)
                                    <a class="text-link" href="{{ route('app.jobs.show', $history->assignment->emergencyJob) }}">{{ $history->assignment->emergencyJob->job_id }}</a> ·
                                @endif
                                {{ $history->actor?->name ?? 'System' }}
                            </p>
                            <small>{{ $history->changed_at?->format('M j, Y g:i A') ?? 'Time unavailable' }}{{ $history->remarks ? ' · '.$history->remarks : '' }}</small>
                        </div>
                    </article>
                @empty
                    <p class="empty-cell">No assignment status history is available for this vehicle.</p>
                @endforelse
            </div>
            @if ($assignmentHistory instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $assignmentHistory->hasPages())
                <div class="vehicle-pagination">
                    <span>Showing {{ $assignmentHistory->firstItem() }}–{{ $assignmentHistory->lastItem() }} of {{ number_format($assignmentHistory->total()) }}</span>
                    {{ $assignmentHistory->links() }}
                </div>
            @endif
        </section>
    @endif

    @if ($vehicleHistory->isNotEmpty())
        <section class="panel vehicle-detail-panel">
            <div class="vehicle-section-heading"><div><h2>Vehicle status history</h2><p>Recorded vehicle-level operational status changes</p></div></div>
            <div class="table-scroll">
                <table class="vehicle-detail-table">
                    <thead><tr><th>Previous status</th><th>New status</th><th>Changed at</th><th>Remarks</th></tr></thead>
                    <tbody>
                        @foreach ($vehicleHistory as $history)
                            <tr>
                                <td>{{ str_replace('_', ' ', data_get($history, 'previous_status', '—')) }}</td>
                                <td><span class="vehicle-status-badge is-{{ \Illuminate\Support\Str::slug(data_get($history, 'new_status', 'unknown'), '_') }}">{{ str_replace('_', ' ', data_get($history, 'new_status', 'Unknown')) }}</span></td>
                                <td>{{ data_get($history, 'changed_at') ? \Illuminate\Support\Carbon::parse(data_get($history, 'changed_at'))->format('M j, Y g:i A') : 'Time unavailable' }}</td>
                                <td>{{ data_get($history, 'remarks') ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</section>
@endsection
