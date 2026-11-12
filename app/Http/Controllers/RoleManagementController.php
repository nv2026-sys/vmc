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
}
