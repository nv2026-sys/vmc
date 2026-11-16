<?php

namespace App\Services;

use App\Models\DriverLoginSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;

class DriverLoginSessionManager
{
    public function assertAvailable(User $driver): void
    {
        $this->removeExpiredSession($driver);
        $this->removeExpiredTokens($driver);

        if (DriverLoginSession::query()->where('user_id', $driver->id)->exists()
            || $driver->tokens()->exists()
            || $this->hasActiveLegacyWebSession($driver)) {
            throw ValidationException::withMessages([
                'email' => 'This driver account is already logged in on another device. Log out there before signing in here.',
            ]);
        }
    }

    public function claimApi(User $driver, NewAccessToken $token): void
    {
        $expiration = (int) config('sanctum.expiration');
        $expiresAt = $token->accessToken->expires_at;

        if ($expiresAt === null && $expiration > 0) {
            $expiresAt = now()->addMinutes($expiration);
        }

        DriverLoginSession::query()->create([
            'user_id' => $driver->id,
            'session_identifier' => 'api:'.$token->accessToken->getKey(),
            'auth_type' => 'api',
            'expires_at' => $expiresAt,
        ]);
    }

    public function claimWeb(User $driver, string $sessionIdentifier): void
    {
        DriverLoginSession::query()->create([
            'user_id' => $driver->id,
            'session_identifier' => $sessionIdentifier,
            'auth_type' => 'web',
            'expires_at' => now()->addMinutes((int) config('session.lifetime')),
        ]);
    }

    public function releaseApi(User $driver, int|string $tokenId): void
    {
        DriverLoginSession::query()
            ->where('user_id', $driver->id)
            ->where('session_identifier', 'api:'.$tokenId)
            ->delete();
    }

    public function releaseWeb(User $driver, ?string $sessionIdentifier): void
    {
        if ($sessionIdentifier === null) {
            return;
        }

        DriverLoginSession::query()
            ->where('user_id', $driver->id)
            ->where('session_identifier', $sessionIdentifier)
            ->where('auth_type', 'web')
            ->delete();
    }

    public function refreshWeb(User $driver, ?string $sessionIdentifier): void
    {
        if ($sessionIdentifier === null) {
            return;
        }

        DriverLoginSession::query()
            ->where('user_id', $driver->id)
            ->where('session_identifier', $sessionIdentifier)
            ->where('auth_type', 'web')
            ->update([
                'expires_at' => now()->addMinutes((int) config('session.lifetime')),
                'updated_at' => now(),
            ]);
    }

    private function removeExpiredSession(User $driver): void
    {
        DriverLoginSession::query()
            ->where('user_id', $driver->id)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
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

    private function hasActiveLegacyWebSession(User $driver): bool
    {
        $table = config('session.table', 'sessions');
        if (! Schema::hasTable($table) || config('session.driver') !== 'database') {
            return false;
        }

        $lastActivityAfter = now()->subMinutes((int) config('session.lifetime'))->timestamp;

        return DB::table($table)
            ->where('user_id', $driver->id)
            ->where('last_activity', '>=', $lastActivityAfter)
            ->exists();
    }
}
