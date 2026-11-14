@extends('layouts.app')

@section('title', 'Notifications')

@section('content')
<section class="page-stack">
    <div class="page-heading">
        <div>
            <span class="eyebrow">PERSONAL UPDATES</span>
            <h1>Notifications</h1>
            <p class="muted">{{ $unreadCount }} unread notification{{ $unreadCount === 1 ? '' : 's' }}</p>
        </div>
        @if ($unreadCount > 0)
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <button class="button button-light" type="submit">Mark all as read</button>
            </form>
        @endif
    </div>
    @if (session('status'))
        <div class="success-notice" role="status">{{ session('status') }}</div>
    @endif
    <section class="panel">
        <div class="table-scroll">
            <table>
                <thead><tr><th>Update</th><th>Incident</th><th>Time</th><th>Status</th><th></th></tr></thead>
                <tbody>
                @forelse ($notifications as $notification)
                    @php
                        $data = $notification->data;
                        $event = $data['event'] ?? '';
                        $title = match ($event) {
                            'dispatch.assigned', 'assignment.created' => 'New assignment',
                            'assignment.status_changed' => 'Assignment status updated',
                            'assignment.remark_reply' => 'Reply to your remark',
                            'driver_assignment.escalated' => 'Driver escalation',
                            'driver_assignment.need_help' => 'Driver help request',
                            'vehicle.operational_status_changed' => 'Vehicle status updated',
                            default => str($event)->replace(['driver_assignment.', '_'], ['', ' '])->title()->toString() ?: 'Notification',
                        };
                        $summary = $data['message'] ?? $data['remarks'] ?? $data['reason'] ?? null;
                        $detail = $data['incident_type'] ?? null;
                        if (!$detail && isset($data['previous_status'], $data['new_status'])) {
                            $detail = $data['previous_status'].' → '.$data['new_status'];
                        }
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $title }}</strong>
                            @if ($summary)<small>{{ $summary }}</small>@endif
                            @if (!empty($data['vehicle_number']))<small>Vehicle: {{ $data['vehicle_number'] }}</small>@endif
                            @if (!empty($data['team_name']))<small>Team: {{ $data['team_name'] }}</small>@endif
                        </td>
                        <td>{{ $data['job_id'] ?? $data['vehicle_number'] ?? '—' }}<small>{{ str_replace('_', ' ', $detail ?? '') }}</small></td>
                        <td>{{ $notification->created_at?->format('M j, Y g:i A') }}</td>
                        <td><span class="status {{ $notification->read_at ? 'status-inactive' : 'status-active' }}">{{ $notification->read_at ? 'Read' : 'Unread' }}</span></td>
                        <td>
                            @if (!$notification->read_at)
                                <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                                    @csrf
                                    <button class="text-link" type="submit">Mark read</button>
                                </form>
                            @endif
                            @if (!empty($data['incident_id']))
                                <a class="text-link" href="{{ route('app.jobs.show', $data['incident_id']) }}">View incident</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty-cell">You have no notifications yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $notifications->links() }}</div>
    </section>
</section>
@endsection
