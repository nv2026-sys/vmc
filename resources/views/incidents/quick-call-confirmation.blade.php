@extends('layouts.app')

@section('title', 'Incident registered')

@section('content')
<section class="page-stack" style="max-width: 760px; margin: 0 auto;">
    <div class="success-notice" role="status">
        Incident <strong>{{ $job->job_id }}</strong> registered successfully.
    </div>
    <section class="panel">
        <span class="eyebrow">INCIDENT RECEIVED</span>
        <h1>{{ $job->incident_type === 'OTHER' ? 'Other emergency' : str_replace('_', ' ', $job->incident_type) }}</h1>
        <p class="muted">{{ $job->society_name }} · {{ $job->ward?->name }} · {{ $job->incident_address }}</p>
        <p><strong>Caller:</strong> {{ $job->caller_name }} · <a href="tel:{{ $job->caller_phone }}">{{ $job->caller_phone }}</a></p>
        <div class="form-actions">
            @if ($canAssign)
                <a class="button button-primary" href="{{ route('app.jobs.show', $job).'#assignment-rows' }}">Assign one or more vehicles</a>
            @endif
            @if ($canViewDetails)
                <a class="button button-light" href="{{ route('app.jobs.show', $job) }}">Open incident details</a>
            @endif
            <a class="button button-light" href="{{ route('app.jobs.quick-call') }}">Register another call</a>
        </div>
    </section>
</section>
@endsection
