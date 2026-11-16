<?php

namespace App\Http\Middleware;

use App\Services\DriverLoginSessionManager;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RefreshDriverLoginSession
{
    public function __construct(private readonly DriverLoginSessionManager $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $driver = Auth::guard('web')->user();

        if ($driver?->hasRole('VEHICLE_DRIVER')) {
            $this->sessions->refreshWeb(
                $driver,
                $request->session()->get('driver_login_session_id'),
            );
        }

        return $response;
    }
}
