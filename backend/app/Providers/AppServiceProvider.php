<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsService::class);
    }

    public function boot(): void
    {
        // super_admin bypasses every Gate check implicitly
        Gate::before(function (User $user, string $ability): ?bool {
            return $user->hasRole('super_admin') ? true : null;
        });
    }
}
