@extends('layouts.app')

@section('title', 'Quick call')

@section('content')
<style>
    .quick-call-page { max-width: 760px; margin: 0 auto; }
    .quick-call-form { gap: 14px; }
    .quick-call-form .form-grid { gap: 12px; }
    .quick-call-form label { font-size: 13px !important; }
    .quick-call-form input, .quick-call-form select, .quick-call-form textarea {
        min-height: 46px;
        padding: 10px 12px;
        font-size: 16px !important;
    }
    .quick-call-form textarea { min-height: 72px; }
    .quick-call-form .form-actions { justify-content: stretch; }
    .quick-call-form .form-actions > * { flex: 1; min-height: 46px; }
    .quick-call-error { color: #a63737; font-size: 13px; }
    @media (max-width: 650px) {
        .quick-call-page { width: 100%; }
        .quick-call-form .form-grid { grid-template-columns: minmax(0, 1fr); }
    }
</style>
<section class="page-stack quick-call-page">
    <div class="page-heading">
        <div>
            <span class="eyebrow">EMERGENCY DESK · QUICK INTAKE</span>
            <h1>Quick call</h1>
            <p class="muted">Capture the essentials now. You can assign response units or add more details next.</p>
        </div>
        <a class="button button-light" href="{{ route('app.jobs.new') }}">Full registration</a>
    </div>

    @if ($errors->any())
        <div class="alert" role="alert">{{ $errors->first() }}</div>
    @endif

    <section class="panel">
        <form method="POST" action="{{ route('app.jobs.quick-call.store') }}" class="managed-user-form incident-form quick-call-form">
            @csrf
            <div class="form-grid">
                <label>Caller name
                    <input name="caller_name" value="{{ old('caller_name') }}" required maxlength="255" autocomplete="name" autofocus>
                </label>
                <label>Caller phone
                    <input name="caller_phone" type="tel" value="{{ old('caller_phone') }}" required maxlength="32" autocomplete="tel" inputmode="tel">
                </label>
                <label>Incident type
                    <select name="incident_type" required>
                        <option value="">Select type</option>
                        @foreach (['STRUCTURE_FIRE' => 'Structure fire', 'VEHICLE_FIRE' => 'Vehicle fire', 'ROAD_ACCIDENT' => 'Road accident', 'MEDICAL_EMERGENCY' => 'Medical emergency', 'RESCUE' => 'Rescue', 'OTHER' => 'Other'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('incident_type') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Priority
                    <select name="priority" required>
                        @foreach (['LOW' => 'Low', 'NORMAL' => 'Normal', 'HIGH' => 'High', 'CRITICAL' => 'Critical'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('priority', 'HIGH') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Ward
                    <select name="ward_id" required>
                        <option value="">Select ward</option>
                        @foreach ($wards as $ward)
                            <option value="{{ $ward->id }}" @selected((string) old('ward_id') === (string) $ward->id)>{{ $ward->name }} · {{ $ward->code }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Area / society
                    <input name="society_name" value="{{ old('society_name') }}" required maxlength="255" autocomplete="address-level3">
                </label>
                <label class="field-wide">Incident address / location
                    <textarea name="incident_address" required maxlength="2000" rows="2" autocomplete="street-address">{{ old('incident_address') }}</textarea>
                </label>
                <label class="field-wide">Short remarks <span class="muted">(optional)</span>
                    <textarea name="remarks" maxlength="500" rows="2">{{ old('remarks') }}</textarea>
                </label>
            </div>
            <div class="form-actions">
                <a class="button button-light" href="{{ route('app.jobs') }}">Cancel</a>
                <button class="button button-primary" type="submit">Register incident</button>
            </div>
        </form>
    </section>
</section>
@endsection
