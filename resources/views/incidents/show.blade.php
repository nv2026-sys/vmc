@extends('layouts.app')

@section('title', $job->job_id)

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/incident-detail.css') }}?v={{ filemtime(public_path('css/incident-detail.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/incident-chat.css') }}?v={{ filemtime(public_path('css/incident-chat.css')) }}">
@endpush

@section('content')
<section class="page-stack incident-detail-page">
    <div class="page-heading">
        <div><span class="eyebrow">EMERGENCY DESK · INCIDENT DETAILS</span><h1>{{ $job->job_id }}</h1><p class="muted">{{ $job->occurred_at->format('M j, Y g:i A') }} · {{ $job->ward?->name ?? 'Ward not assigned' }}</p></div>
        <div>
            <span class="status">{{ str_replace('_', ' ', $job->status) }}</span>
            @if ($canArchive)
                <form method="POST" action="{{ route('app.jobs.archive', $job->id) }}" class="inline-form" onsubmit="return confirm('Archive this closed incident? It can be restored later.');">
                    @csrf
                    <button class="button button-light" type="submit">Archive incident</button>
                </form>
            @endif
            <a class="button button-light" href="{{ route('app.jobs') }}">Back to incidents</a>
        </div>
    </div>
    @if (session('status')) <div class="success-notice" role="status">{{ session('status') }}</div> @endif
    @if ($errors->any()) <div class="alert" role="alert">{{ $errors->first() }}</div> @endif

    <section class="panel incident-details-panel">
        <h2>Caller and incident</h2>
        <div class="form-grid incident-facts">
            <div><span>Caller</span><strong>{{ $job->caller_name }}</strong></div>
            <div><span>Phone</span><strong>{{ $job->caller_phone }}</strong></div>
            <div><span>Call source</span><strong>{{ str_replace('_', ' ', $job->call_source) }}</strong></div>
            <div><span>Type · priority</span><strong>{{ str_replace('_', ' ', $job->incident_type) }} · {{ $job->priority }}</strong></div>
            <div class="field-wide"><span>Address</span><strong>{{ $job->incident_address }}</strong></div>
            <div><span>Society / area</span><strong>{{ $job->society_name ?: 'Not recorded' }}</strong></div>
            <div><span>Coordinates</span><strong>{{ $job->latitude && $job->longitude ? $job->latitude.', '.$job->longitude : 'Not recorded' }}</strong></div>
            <div class="field-wide"><span>Intake remarks</span><strong>{{ $job->remarks ?: '—' }}</strong></div>
        </div>
    </section>

    @if ($job->assignments->isNotEmpty() || $canAssign)
        <section class="panel incident-resources-panel">
            <div class="page-heading">
                <div><h2>Response units</h2><p class="muted">Each vehicle is assigned and tracked independently. Busy vehicle/driver pairs and response teams can be queued; the next assignment activates automatically when current work is released.</p></div>
            </div>
            @if ($job->assignments->isNotEmpty())
                <div class="table-scroll"><table><thead><tr><th>Vehicle</th><th>Station</th><th>Team</th><th>Assigned by</th><th>Status</th><th>Update status</th></tr></thead><tbody>
                    @foreach ($job->assignments as $assignment)
                        <tr>
                            <td data-label="Vehicle"><strong>{{ $assignment->vehicle?->vehicle_number ?? 'Vehicle unavailable' }}</strong></td>
                            <td data-label="Station">{{ $assignment->fireStation?->name ?? '—' }}</td>
                            <td data-label="Team / driver">
                                Driver: {{ $assignment->assignedDriver?->name ?? 'Unassigned' }}
                                @if ($assignment->team)
                                    <br><small class="muted">Team: {{ $assignment->team->name }}@if ($assignment->teamLeader) · {{ $assignment->teamLeader->name }}@endif</small>
                                @endif
                            </td>
                            <td data-label="Assigned by">{{ $assignment->assigner?->name ?? 'System' }}</td>
                            <td data-label="Status"><span class="status">{{ str_replace('_', ' ', $assignment->status) }}</span></td>
                            <td data-label="Update status">
                                @if (in_array((string) $assignment->getKey(), $updatableAssignmentIds, true))
                                    <form method="POST" action="{{ route('app.jobs.assignments.status', [$job->id, $assignment->id]) }}" class="managed-user-form incident-form">
                                        @csrf @method('PATCH')
                                        <label>Status
                                            <select name="status">
                                                @foreach ([
                                                    \App\Models\JobAssignment::STATUS_ASSIGNED => 'Assigned',
                                                    \App\Models\JobAssignment::STATUS_EN_ROUTE => 'En route',
                                                    \App\Models\JobAssignment::STATUS_ARRIVED => 'Arrived',
                                                    \App\Models\JobAssignment::STATUS_STARTED => 'Started',
                                                    \App\Models\JobAssignment::STATUS_IN_PROGRESS => 'In progress',
                                                    \App\Models\JobAssignment::STATUS_COMPLETED => 'Completed',
                                                    \App\Models\JobAssignment::STATUS_WORK_DONE => 'Work done',
                                                ] as $statusValue => $statusLabel)
                                                    <option value="{{ $statusValue }}" @selected($assignment->status === $statusValue)>{{ $statusLabel }}</option>
                                                @endforeach
                                            </select>
                                        </label>
                                        <label>Status update note<input name="remarks" maxlength="2000" placeholder="Optional status note"></label>
                                        <button class="button button-light" type="submit">Update</button>
                                    </form>
                                @else
                                    <span class="muted">Status update unavailable</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody></table></div>
            @else
                <p class="muted">No response units have been assigned yet.</p>
            @endif

            @if ($canAssign)
                @if ($assignableStations->isNotEmpty())
                    <form method="POST" action="{{ route('app.jobs.assignments.store', $job->id) }}" class="managed-user-form incident-form assignment-form">
                        @csrf
                        <h3>Assign one or more units</h3>
                        <p class="muted">A busy vehicle and driver can only be queued together as the same pair. Busy response teams can also be queued. Queued work activates automatically when the current assignment finishes. The station must cover this incident's ward.</p>
                        <div id="assignment-rows">
                            <div class="assignment-row form-grid">
                                <label>Fire station
                                    <select name="assignments[0][fire_station_id]" data-field="fire_station_id" class="assignment-station" required>
                                        <option value="">Select station</option>
                                        @foreach ($assignableStations as $station)
                                            <option value="{{ $station->id }}">{{ $station->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>Vehicle
                                    <select name="assignments[0][vehicle_id]" data-field="vehicle_id" class="assignment-vehicle" required>
                                        <option value="">Select vehicle</option>
                                        @foreach ($assignableStations as $station)
                                            @foreach ($station->vehicles as $vehicle)
                                                <option value="{{ $vehicle->id }}" data-station="{{ $station->id }}">{{ $vehicle->vehicle_number }} · {{ str_replace('_', ' ', $vehicle->vehicle_type) }}@if ($vehicle->status === \App\Models\Vehicle::STATUS_DEPLOYED) · Busy — may queue @endif</option>
                                            @endforeach
                                        @endforeach
                                    </select>
                                </label>
                                <label>Vehicle driver
                                    <select name="assignments[0][driver_user_id]" data-field="driver_user_id" class="assignment-driver" required>
                                        <option value="">Select driver</option>
                                        @foreach ($assignableStations as $station)
                                            @foreach ($station->users as $driver)
                                                <option value="{{ $driver->id }}" data-station="{{ $station->id }}">{{ $driver->name }}</option>
                                            @endforeach
                                        @endforeach
                                    </select>
                                </label>
                                <label>Response team (optional; busy teams queue)
                                    <select name="assignments[0][team_id]" data-field="team_id" class="assignment-team">
                                        <option value="">No team selected</option>
                                        @foreach ($assignableStations as $station)
                                            @foreach ($station->teams as $team)
                                                <option value="{{ $team->id }}" data-station="{{ $station->id }}">{{ $team->name }}@if ($team->status === \App\Models\Team::STATUS_DEPLOYED) · Busy — will queue @endif</option>
                                            @endforeach
                                        @endforeach
                                    </select>
                                </label>
                            </div>
                        </div>
                        <button class="button button-light" type="button" id="add-assignment-row">Add another vehicle</button>
                        <label>Dispatch notes<textarea name="remarks" rows="2" maxlength="2000"></textarea></label>
                        <button class="button button-primary" type="submit">Assign response units</button>
                    </form>
                    <template id="assignment-row-template">
                        <div class="assignment-row form-grid">
                            <label>Fire station
                                <select data-field="fire_station_id" class="assignment-station" required>
                                    <option value="">Select station</option>
                                    @foreach ($assignableStations as $station)
                                        <option value="{{ $station->id }}">{{ $station->name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Vehicle
                                <select data-field="vehicle_id" class="assignment-vehicle" required>
                                    <option value="">Select vehicle</option>
                                    @foreach ($assignableStations as $station)
                                        @foreach ($station->vehicles as $vehicle)
                                            <option value="{{ $vehicle->id }}" data-station="{{ $station->id }}">{{ $vehicle->vehicle_number }} · {{ str_replace('_', ' ', $vehicle->vehicle_type) }}@if ($vehicle->status === \App\Models\Vehicle::STATUS_DEPLOYED) · Busy — may queue @endif</option>
                                        @endforeach
                                    @endforeach
                                </select>
                            </label>
                            <label>Vehicle driver
                                <select data-field="driver_user_id" class="assignment-driver" required>
                                    <option value="">Select driver</option>
                                    @foreach ($assignableStations as $station)
                                        @foreach ($station->users as $driver)
                                            <option value="{{ $driver->id }}" data-station="{{ $station->id }}">{{ $driver->name }}</option>
                                        @endforeach
                                    @endforeach
                                </select>
                            </label>
                            <label>Response team (optional; busy teams queue)
                                <select data-field="team_id" class="assignment-team">
                                    <option value="">No team selected</option>
                                    @foreach ($assignableStations as $station)
                                        @foreach ($station->teams as $team)
                                            <option value="{{ $team->id }}" data-station="{{ $station->id }}">{{ $team->name }}@if ($team->status === \App\Models\Team::STATUS_DEPLOYED) · Busy — will queue @endif</option>
                                        @endforeach
                                    @endforeach
                                </select>
                            </label>
                            <button class="button button-light remove-assignment-row" type="button">Remove vehicle</button>
                        </div>
                    </template>
                @else
                    <p class="muted">No station covering this incident ward has an assignable vehicle and an active vehicle driver.</p>
                @endif
            @endif
        </section>
    @endif

    @if ($job->status === \App\Models\EmergencyJob::STATUS_RECEIVED && ($canCloseDuplicate || $canTransferTo112))
        <section class="form-grid">
            @if ($canCloseDuplicate)
                <div class="panel">
                    <h2>Close as duplicate</h2>
                    @if ($possibleDuplicates->isNotEmpty())
                        <p class="muted">Possible matches use the same caller number from the last 48 hours. Confirm the original before closing.</p>
                        <form method="POST" action="{{ route('app.jobs.duplicate', $job->id) }}" class="managed-user-form incident-form">
                            @csrf
                            <label>Original incident
                                <select name="duplicate_of" required>
                                    <option value="">Select original incident</option>
                                    @foreach ($possibleDuplicates as $candidate)
                                        <option value="{{ $candidate->id }}">{{ $candidate->job_id }} · {{ $candidate->incident_type }} · {{ $candidate->occurred_at->format('M j, g:i A') }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Closure note<textarea name="remarks" rows="2" maxlength="2000"></textarea></label>
                            <button class="button button-light" type="submit">Confirm duplicate</button>
                        </form>
                    @else
                        <p class="muted">No matching recent caller incidents were found.</p>
                    @endif
                </div>
            @endif
            @if ($canTransferTo112)
                <div class="panel">
                    <h2>Transfer to 112</h2>
                    <p class="muted">Record the 112 reference number to keep the handoff auditable.</p>
                    <form method="POST" action="{{ route('app.jobs.transfer-112', $job->id) }}" class="managed-user-form incident-form">
                        @csrf
                        <label>112 reference number<input name="reference_number" required maxlength="100"></label>
                        <label>Handoff details<textarea name="details" rows="2" maxlength="5000"></textarea></label>
                        <button class="button button-primary" type="submit">Record transfer</button>
                    </form>
                </div>
            @endif
        </section>
    @endif

    @if ($job->externalReferences->isNotEmpty())
        <section class="panel">
            <h2>External references</h2>
            <div class="table-scroll"><table><thead><tr><th>Type</th><th>Reference</th><th>Details</th><th>Recorded</th></tr></thead><tbody>
                @foreach ($job->externalReferences as $reference)
                    <tr><td>{{ $reference->reference_type }}</td><td><strong>{{ $reference->reference_number }}</strong></td><td>{{ $reference->details ?: '—' }}</td><td>{{ ($reference->transferred_at ?? $reference->confirmed_at ?? $reference->created_at)->format('M j, Y g:i A') }}</td></tr>
                @endforeach
            </tbody></table></div>
        </section>
    @endif

    <section class="panel" id="incident-conversation">
        <div class="incident-chat-heading">
            <div>
                <h2>Incident conversation</h2>
                <p class="muted">Driver requests, remarks, and control room replies.</p>
            </div>
        </div>
        <div class="incident-chat-list" aria-live="polite">
            @forelse ($communicationActivity as $activity)
                <article class="incident-chat-message {{ $activity['type'] === 'reply' ? 'is-reply' : 'is-driver' }}">
                    <div class="incident-chat-avatar" aria-hidden="true">{{ collect(explode(' ', $activity['actor']))->take(2)->map(fn ($part) => mb_substr($part, 0, 1))->implode('') }}</div>
                    <div class="incident-chat-bubble">
                        <div class="incident-chat-meta">
                            <div class="incident-chat-sender">
                                <strong>{{ $activity['actor'] }}</strong>
                                <span class="incident-chat-kind">
                                    @if ($activity['type'] === 'need_help')
                                        Help request
                                    @elseif ($activity['type'] === 'escalated')
                                        Escalation
                                    @elseif ($activity['type'] === 'driver_remark')
                                        Driver remark
                                    @else
                                        Control room reply
                                    @endif
                                </span>
                            </div>
                            <time datetime="{{ $activity['occurred_at']->toIso8601String() }}">{{ $activity['occurred_at']->format('M j, g:i A') }}</time>
                        </div>
                        <div class="incident-chat-body">
                        @if ($activity['type'] === 'reply')
                            {{ $activity['details']['reply'] }}
                        @elseif ($activity['type'] === 'driver_remark')
                            {{ $activity['details']['message'] }}
                        @else
                            @if (empty($activity['details']['help_type']) || strcasecmp(str_replace('_', ' ', $activity['details']['help_type']), (string) ($activity['details']['reason'] ?? '')) !== 0)
                                {{ $activity['details']['reason'] ?? '—' }}
                            @endif
                            @if (!empty($activity['details']['help_type']))
                                <span class="incident-chat-tag">{{ str_replace('_', ' ', $activity['details']['help_type']) }}</span>
                            @endif
                            @if (!empty($activity['details']['comments']))
                                <p>{{ $activity['details']['comments'] }}</p>
                            @endif
                        @endif
                        </div>
                        @if ($activity['vehicle_number'] || $activity['assignment_id'])
                            <div class="incident-chat-context">{{ $activity['vehicle_number'] ?? 'Unit unavailable' }}@if ($activity['assignment_id']) · Assignment #{{ $activity['assignment_id'] }}@endif</div>
                        @endif
                    </div>
                </article>
            @empty
                <p class="incident-chat-empty">No conversation messages have been recorded.</p>
            @endforelse
        </div>
        @if ($replyableAssignments->isNotEmpty())
            <form method="POST" action="{{ route('app.jobs.reply', $job) }}" class="incident-chat-composer {{ $replyableAssignments->count() === 1 ? 'has-single-recipient' : 'has-multiple-recipients' }}">
                @csrf
                @if ($replyableAssignments->count() === 1)
                    @php($replyAssignment = $replyableAssignments->first())
                    <input type="hidden" name="assignment_id" value="{{ $replyAssignment->id }}">
                    <div class="incident-chat-recipient">Replying to <strong>{{ $replyAssignment->assignedDriver?->name }}</strong><span>{{ $replyAssignment->vehicle?->vehicle_number ?? 'Unit' }} · Assignment #{{ $replyAssignment->id }}</span></div>
                @else
                    <label class="incident-chat-recipient">Reply to
                        <select name="assignment_id" required>
                            <option value="">Select a driver</option>
                            @foreach ($replyableAssignments as $assignment)
                                <option value="{{ $assignment->id }}" @selected((string) old('assignment_id') === (string) $assignment->id)>
                                    {{ $assignment->vehicle?->vehicle_number ?? 'Unit' }} · {{ $assignment->assignedDriver?->name }} · Assignment #{{ $assignment->id }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <label class="incident-chat-reply-field">Message
                    <textarea name="reply" rows="2" maxlength="2000" required placeholder="Write a reply to the driver...">{{ old('reply') }}</textarea>
                </label>
                <button class="button button-primary" type="submit">Send reply</button>
            </form>
        @endif
    </section>

    <section class="panel">
        <h2>Incident history</h2>
        <div class="table-scroll"><table class="incident-history-table"><thead><tr><th>Time</th><th>Change</th><th>Unit</th><th>By</th><th>Notes</th></tr></thead><tbody>
            @forelse ($job->statusHistory as $history)
                @php($isConversationReply = trim((string) $history->remarks) !== '' && $history->assignment?->assignedDriver !== null && $history->actor !== null && (int) $history->actor->id !== (int) $history->assignment->assigned_driver_user_id)
                <tr><td data-label="Time">{{ $history->changed_at->format('M j, Y g:i A') }}</td><td data-label="Change">@if ($isConversationReply)<strong>Reply sent</strong>@else{{ $history->previous_status ? str_replace('_', ' ', $history->previous_status).' → ' : '' }}<strong>{{ str_replace('_', ' ', $history->new_status) }}</strong>@endif</td><td data-label="Unit">{{ $history->assignment?->vehicle?->vehicle_number ?? 'Incident' }}</td><td data-label="By">{{ $history->actor?->name ?? 'System' }}</td><td data-label="Notes">@if ($isConversationReply)<a class="text-link" href="#incident-conversation">See conversation</a>@else{{ $history->remarks ?: '—' }}@endif</td></tr>
            @empty
                <tr><td colspan="5" class="empty-cell">No incident history recorded.</td></tr>
            @endforelse
        </tbody></table></div>
    </section>
</section>
<script>
    (() => {
        const rows = document.getElementById('assignment-rows');
        const template = document.getElementById('assignment-row-template');
        const addButton = document.getElementById('add-assignment-row');
        if (!rows || !template || !addButton) return;

        const filterResources = (row) => {
            const stationId = row.querySelector('.assignment-station').value;
            row.querySelectorAll('.assignment-vehicle option[data-station], .assignment-driver option[data-station], .assignment-team option[data-station]').forEach((option) => {
                const available = !stationId || option.dataset.station === stationId;
                option.hidden = !available;
                option.disabled = !available;
                if (!available && option.selected) option.selected = false;
            });
        };

        const renumberRows = () => {
            rows.querySelectorAll('.assignment-row').forEach((row, index) => {
                row.querySelectorAll('[data-field]').forEach((field) => {
                    field.name = `assignments[${index}][${field.dataset.field}]`;
                });
            });
        };

        const addRow = () => {
            if (rows.children.length >= 10) return;
            const row = template.content.firstElementChild.cloneNode(true);
            row.querySelector('.assignment-station').addEventListener('change', () => filterResources(row));
            row.querySelector('.remove-assignment-row').addEventListener('click', () => {
                row.remove();
                renumberRows();
            });
            rows.appendChild(row);
            renumberRows();
        };

        rows.querySelector('.assignment-station').addEventListener('change', () => filterResources(rows.firstElementChild));
        addButton.addEventListener('click', addRow);
    })();
</script>
@endsection
