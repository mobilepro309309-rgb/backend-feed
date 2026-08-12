<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use App\Contracts\StorageServiceInterface;
use App\Services\{CloudflareR2StorageService, SupabaseStorageService};

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(StorageServiceInterface::class, function ($app) {
            $driver = config('services.storage.driver', 'r2');

            return match ($driver) {
                'cloudflare', 'r2' => $app->make(CloudflareR2StorageService::class),
                default            => SupabaseStorageService::fromEnv(),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
