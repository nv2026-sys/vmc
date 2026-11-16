<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
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

    public function claimWeb(User $driver, string $sessionId, Request $request): void
    {
        DB::table(config('session.table', 'sessions'))->insert([
            'id' => $sessionId,
            'user_id' => $driver->id,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'payload' => base64_encode(''),
            'last_activity' => now()->timestamp,
        ]);
    }

    public function releaseWeb(string $sessionId): void
    {
        DB::table(config('session.table', 'sessions'))
            ->where('id', $sessionId)
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
        if (config('session.driver') !== 'database') {
            return false;
        }

        $table = config('session.table', 'sessions');
        $lastActivityAfter = now()->subMinutes((int) config('session.lifetime'))->timestamp;
        $sessions = DB::table($table)->where('user_id', $driver->id);
        $sessions->where('last_activity', '<', $lastActivityAfter)->delete();

        return DB::table($table)
            ->where('user_id', $driver->id)
            ->where('last_activity', '>=', $lastActivityAfter)
            ->exists();
    }
}
