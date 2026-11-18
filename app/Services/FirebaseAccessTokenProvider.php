<?php

namespace App\Services;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class FirebaseAccessTokenProvider
{
    public function getToken(): string
    {
        $credentialsPath = config('services.firebase.credentials');
        if (! is_string($credentialsPath) || $credentialsPath === '' || ! is_readable($credentialsPath)) {
            throw new RuntimeException('Firebase service-account credentials are not readable.');
        }

        $cacheKey = 'firebase.messaging.access_token.'.hash('sha256', $credentialsPath);
        $cachedToken = Cache::get($cacheKey);
        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        $credentials = new ServiceAccountCredentials(
            ['https://www.googleapis.com/auth/firebase.messaging'],
            $credentialsPath,
        );
        $token = $credentials->fetchAuthToken();
        $accessToken = $token['access_token'] ?? null;
        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('Google OAuth did not return a Firebase access token.');
        }

        $expiresIn = max(60, (int) ($token['expires_in'] ?? 3600) - 60);
        Cache::put($cacheKey, $accessToken, now()->addSeconds($expiresIn));

        return $accessToken;
    }
}
