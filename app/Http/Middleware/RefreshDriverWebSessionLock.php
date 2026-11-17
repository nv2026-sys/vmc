<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class RefreshDriverWebSessionLock
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User
            && $user->hasRole('VEHICLE_DRIVER')) {
            DB::table('driver_login_sessions')
                ->where('user_id', $user->id)
                ->update(['last_activity' => now()->timestamp]);
        }

        return $next($request);
    }
}
