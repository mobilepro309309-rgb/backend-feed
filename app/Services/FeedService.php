<?php

namespace App\Services;

use Illuminate\Pagination\LengthAwarePaginator;

use App\Models\Feed;

class FeedService
{
    public function getPaginatedFeed(int $perPage = 10): LengthAwarePaginator
    {
        return Feed::query()
            // ->where('status', 'active')
            // eager-load the polymorphic `feedable` and, where applicable,
            // load the post author to ensure frontend receives `user` on posts
            ->with(['feedable', 'feedable.user'])
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }
}
