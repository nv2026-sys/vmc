<?php

namespace App\Providers;

use App\Database\SqlServerGrammar;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('database.default') === 'sqlsrv') {
            $connection = DB::connection('sqlsrv');
            $connection->setQueryGrammar(new SqlServerGrammar($connection));
        }

        Gate::before(static fn (User $user): ?bool => $user->hasRole('SUPER_ADMIN') ? true : null);

        RateLimiter::for('login', static function (Request $request): array {
            $email = $request->input('email');
            $key = is_string($email) ? Str::lower(trim($email)) : 'unknown';

            return [
                Limit::perMinute(5)->by('login-email:'.$request->ip().'|'.$key),
                Limit::perMinute(60)->by('login-ip:'.$request->ip()),
            ];
        });
    }
}
