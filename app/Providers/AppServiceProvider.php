<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

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
        RateLimiter::for('auth', function (Request $request) {
            $identifier = Str::lower(trim((string) ($request->input('phone') ?: $request->input('email') ?: 'unknown')));

            return Limit::perMinute(5)
                ->by($request->ip().'|'.$identifier)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'محاولات كثيرة. يرجى الانتظار دقيقة ثم المحاولة مرة أخرى.',
                    'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                ], 429, $headers));
        });

        RateLimiter::for('post-create', function (Request $request) {
            return Limit::perMinute(10)
                ->by((string) ($request->user()?->id ?: $request->ip()))
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'تم تجاوز حد إنشاء المنشورات. حاول مرة أخرى بعد قليل.',
                    'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                ], 429, $headers));
        });

        RateLimiter::for('moderation', function (Request $request) {
            return Limit::perMinute(30)
                ->by((string) ($request->user()?->id ?: $request->ip()))
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'تم تجاوز حد عمليات المراجعة. حاول مرة أخرى بعد قليل.',
                    'retry_after' => (int) ($headers['Retry-After'] ?? 60),
                ], 429, $headers));
        });
    }
}
