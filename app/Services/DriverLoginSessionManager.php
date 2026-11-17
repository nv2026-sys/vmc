<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DriverLoginSessionManager
{
    public function assertAvailable(User $driver): void
    {
        $this->removeExpiredTokens($driver);

        if ($driver->tokens()->exists() || $this->hasActiveWebSession($driver)) {
            throw ValidationException::withMessages([
                'email' => 'This driver account is already logged in on another device. Log out there before signing in here.',
            ]);
        }
    }

    public function claimWeb(User $driver, string $sessionId): void
    {
        DB::table('driver_login_sessions')->updateOrInsert([
            'user_id' => $driver->id,
        ], [
            'session_id' => $sessionId,
            'last_activity' => now()->timestamp,
        ]);
    }

    public function releaseWeb(User $driver): void
    {
        DB::table('driver_login_sessions')
            ->where('user_id', $driver->id)
            ->delete();
    }

    private function removeExpiredTokens(User $driver): void
    {
        $expiration = (int) config('sanctum.expiration');
        if ($expiration > 0) {
            $driver->tokens()
                ->where('created_at', '<=', now()->subMinutes($expiration))
                ->delete();
        }

        $driver->tokens()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();
    }

    private function hasActiveWebSession(User $driver): bool
    {
        $lastActivityAfter = now()->subMinutes((int) config('session.lifetime'))->timestamp;
        $sessions = DB::table('driver_login_sessions')->where('user_id', $driver->id);
        $sessions->where('last_activity', '<', $lastActivityAfter)->delete();

        return DB::table('driver_login_sessions')
            ->where('user_id', $driver->id)
            ->where('last_activity', '>=', $lastActivityAfter)
            ->exists();
    }
}
