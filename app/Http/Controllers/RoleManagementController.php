<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RoleManagementController extends Controller
{
    public function create(): View
    {
        return view('roles.create', [
            'role' => null,
            'permissions' => Permission::query()->orderBy('name')->get(),
        ]);
    }

    public function edit(Role $role): View
    {
        return view('roles.create', [
            'role' => $role->load('permissions'),
            'permissions' => Permission::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:64', 'regex:/\A[A-Z][A-Z0-9_]*\z/', Rule::unique('roles', 'code')],
            'description' => ['nullable', 'string', 'max:2000'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['required', 'integer', 'distinct', Rule::exists('permissions', 'id')],
        ]);

        $role = DB::transaction(function () use ($validated, $request, $auditLogger): Role {
            $role = Role::query()->create([
                'name' => trim($validated['name']),
                'code' => $validated['code'],
                'description' => $validated['description'] ?? null,
                'is_system' => false,
            ]);
            $role->permissions()->sync($validated['permissions'] ?? []);

            $auditLogger->record($request->user(), 'roles.created', $role, [
                'permission_ids' => $validated['permissions'] ?? [],
            ]);

            return $role;
        });

        return redirect()->route('app.roles')->with('status', $role->name.' role created.');
    }

    public function update(Request $request, Role $role, AuditLogger $auditLogger): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['required', 'integer', 'distinct', Rule::exists('permissions', 'id')],
        ]);

        DB::transaction(function () use ($validated, $request, $role, $auditLogger): void {
            $role->update([
                'name' => trim($validated['name']),
                'description' => $validated['description'] ?? null,
            ]);
            $role->permissions()->sync($validated['permissions'] ?? []);

            $auditLogger->record($request->user(), 'roles.updated', $role, [
                'permission_ids' => $validated['permissions'] ?? [],
                'is_system' => $role->is_system,
            ]);
        });

        return redirect()->route('app.roles')->with('status', $role->name.' role updated.');
    }
}
