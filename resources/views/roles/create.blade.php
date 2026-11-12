@extends('layouts.app')

@section('title', 'Add role')

@section('content')
<section class="page-stack">
    <div class="page-heading">
        <div><span class="eyebrow">ACCESS CONTROL</span><h1>Add role</h1><p class="muted">Create a custom role and choose its permissions.</p></div>
    </div>
    <section class="form-panel panel">
        <form method="POST" action="{{ route('app.roles.store') }}" class="managed-user-form">
            @csrf
            @if ($errors->any())
                <div class="alert" role="alert"><strong>Check the form:</strong> {{ $errors->first() }}</div>
            @endif
            <div class="form-grid">
                <div class="field">
                    <label for="name">Role name</label>
                    <input id="name" name="name" value="{{ old('name') }}" maxlength="255" required>
                </div>
                <div class="field">
                    <label for="code">Role code</label>
                    <input id="code" name="code" value="{{ old('code') }}" maxlength="64" pattern="[A-Z][A-Z0-9_]*" placeholder="EXAMPLE_ROLE" required>
                    <small>Use uppercase letters, numbers, and underscores.</small>
                </div>
                <div class="field field-wide">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" maxlength="2000" rows="3">{{ old('description') }}</textarea>
                </div>
            </div>

            <fieldset class="station-fieldset">
                <legend>Permissions <span>Select only the access this role needs.</span></legend>
                @error('permissions')<p class="field-error">{{ $message }}</p>@enderror
                @error('permissions.*')<p class="field-error">{{ $message }}</p>@enderror
                <div class="station-choice-grid">
                    @foreach ($permissions as $permission)
                        <label class="station-choice">
                            <input type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked(in_array((string) $permission->id, old('permissions', []), true))>
                            <span><strong>{{ $permission->name }}</strong><small>{{ $permission->code }}</small></span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="form-actions">
                <a class="button button-light" href="{{ route('app.roles') }}">Cancel</a>
                <button class="button button-primary" type="submit">Create role</button>
            </div>
        </form>
    </section>
</section>
@endsection
