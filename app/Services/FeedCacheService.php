<?php

declare(strict_types=1);

namespace App\Services;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

class FeedCacheService
{
    private const TAG = 'feeds';

    public function remember(string $key, int $seconds, Closure $callback): mixed
    {
        try {
            return $this->store()->tags([self::TAG])->remember($key, $seconds, $callback);
        } catch (Throwable) {
            return $callback();
        }
    }

    public function flush(): void
    {
        try {
            $this->store()->tags([self::TAG])->flush();
        } catch (Throwable) {
            // Cache outages must never prevent post writes.
        }
    }

    private function store(): Repository
    {
        return Cache::store('redis');
    }
}
