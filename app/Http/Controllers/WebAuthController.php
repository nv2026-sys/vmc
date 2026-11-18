<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DriverLoginSessionManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WebAuthController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function store(
        Request $request,
        AuditLogger $auditLogger,
        DriverLoginSessionManager $driverSessions,
    ): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $user = User::query()->where('email', mb_strtolower(trim($credentials['email'])))->first();

        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        DB::transaction(function () use ($user, $auditLogger, $driverSessions, $request): void {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $isDriver = $user->hasRole('VEHICLE_DRIVER');
            if ($isDriver) {
                $driverSessions->replaceExistingSessions($user);
            }

            $user->forceFill(['last_login_at' => now()])->save();
            $auditLogger->record($user, 'auth.login', $user, ['method' => 'web']);
            Auth::guard('web')->login($user);
            if ($isDriver) {
                $request->session()->put('driver_login_session', $driverSessions->claimWeb($user));
            }
        });

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(
        Request $request,
        AuditLogger $auditLogger,
        DriverLoginSessionManager $driverSessions,
    ): RedirectResponse
    {
        $user = $request->user();
        DB::transaction(function () use ($user, $auditLogger, $driverSessions, $request): void {
            if ($user->hasRole('VEHICLE_DRIVER')) {
                $driverSessions->releaseWeb($user);
            }
            $auditLogger->record($user, 'auth.logout', $user, ['method' => 'web']);
        });
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('dashboard');
    }
}
