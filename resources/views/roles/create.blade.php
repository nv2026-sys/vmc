@extends('layouts.app')

@section('title', $role ? 'Edit role' : 'Add role')

@section('content')
<section class="page-stack">
    <div class="page-heading">
        <div>
            <span class="eyebrow">ACCESS CONTROL</span>
            <h1>{{ $role ? 'Edit role' : 'Add role' }}</h1>
            <p class="muted">{{ $role ? 'Update role details and assigned permissions.' : 'Create a custom role and choose its permissions.' }}</p>
        </div>
    </div>
    <section class="form-panel panel">
        <form method="POST" action="{{ $role ? route('app.roles.update', $role) : route('app.roles.store') }}" class="managed-user-form">
            @csrf
            @if ($role) @method('PUT') @endif
            @if ($errors->any())
                <div class="alert" role="alert"><strong>Check the form:</strong> {{ $errors->first() }}</div>
            @endif
            @if ($role?->is_system)
                <div class="notice"><span class="notice-icon">!</span><div><strong>System role</strong><p>Permission changes affect every account assigned this role. The role code cannot be changed.</p></div></div>
            @endif
            <div class="form-grid">
                <div class="field">
                    <label for="name">Role name</label>
                    <input id="name" name="name" value="{{ old('name', $role?->name) }}" maxlength="255" required>
                </div>
                <div class="field">
                    <label for="code">Role code</label>
                    @if ($role)
                        <input id="code" value="{{ $role->code }}" readonly>
                        <small>Role codes are fixed to protect account assignments.</small>
                    @else
                        <input id="code" name="code" value="{{ old('code') }}" maxlength="64" pattern="[A-Z][A-Z0-9_]*" placeholder="EXAMPLE_ROLE" required>
                        <small>Use uppercase letters, numbers, and underscores.</small>
                    @endif
                </div>
                <div class="field field-wide">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" maxlength="2000" rows="3">{{ old('description', $role?->description) }}</textarea>
                </div>
            </div>

            <fieldset class="station-fieldset">
                <legend>Permissions <span>Select only the access this role needs.</span></legend>
                @error('permissions')<p class="field-error">{{ $message }}</p>@enderror
                @error('permissions.*')<p class="field-error">{{ $message }}</p>@enderror
                <div class="station-choice-grid">
                    @foreach ($permissions as $permission)
                        <label class="station-choice">
                            <input type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked(in_array((int) $permission->id, array_map('intval', old('permissions', $role?->permissions->modelKeys() ?? [])), true))>
                            <span><strong>{{ $permission->name }}</strong><small>{{ $permission->code }}</small></span>
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div class="form-actions">
                <a class="button button-light" href="{{ route('app.roles') }}">Cancel</a>
                <button class="button button-primary" type="submit">{{ $role ? 'Save role' : 'Create role' }}</button>
            </div>
        </form>
    </section>
</section>
@endsection
