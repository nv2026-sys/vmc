<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DriverLoginSessionManager
{
    public function replaceExistingSessions(User $driver): void
    {
        $driver->tokens()->delete();
        $this->releaseWeb($driver);
    }

    public function claimWeb(User $driver): string
    {
        $sessionId = (string) Str::uuid();
        DB::table('driver_login_sessions')->updateOrInsert([
            'user_id' => $driver->id,
        ], [
            'session_id' => $sessionId,
            'last_activity' => now()->timestamp,
        ]);

        return $sessionId;
    }

    public function claimApi(User $driver): void
    {
        DB::table('driver_login_sessions')->updateOrInsert([
            'user_id' => $driver->id,
        ], [
            'session_id' => 'api:'.Str::uuid(),
            'last_activity' => now()->timestamp,
        ]);
    }

    public function releaseWeb(User $driver): void
    {
        DB::table('driver_login_sessions')
            ->where('user_id', $driver->id)
            ->delete();
    }
}
