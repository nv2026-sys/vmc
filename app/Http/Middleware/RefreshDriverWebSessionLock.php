<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RefreshDriverWebSessionLock
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User
            && $user->hasRole('VEHICLE_DRIVER')) {
            $sessionId = $request->session()->get('driver_login_session');
            $lock = DB::table('driver_login_sessions')
                ->where('user_id', $user->id)
                ->first();

            if ($lock && (! is_string($sessionId) || ! hash_equals($lock->session_id, $sessionId))) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')
                    ->withErrors(['email' => 'Your driver account was signed in on another device.']);
            }

            if (! $lock) {
                $sessionId = (string) Str::uuid();
                $request->session()->put('driver_login_session', $sessionId);
                DB::table('driver_login_sessions')->insert([
                    'user_id' => $user->id,
                    'session_id' => $sessionId,
                    'last_activity' => now()->timestamp,
                ]);
            } else {
                DB::table('driver_login_sessions')
                    ->where('user_id', $user->id)
                    ->where('session_id', $sessionId)
                    ->update(['last_activity' => now()->timestamp]);
            }
        }

        return $next($request);
    }
}
