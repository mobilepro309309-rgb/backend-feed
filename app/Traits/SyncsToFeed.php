<?php

declare(strict_types=1);

namespace App\Traits;

use App\Models\Feed;
use Illuminate\Database\Eloquent\Model;

trait SyncsToFeed
{
    public static function bootSyncsToFeed(): void
    {
        static::created(function (Model $model): void {
            $model->syncFeedCreated();
        });

        static::updated(function (Model $model): void {
            $model->syncFeedUpdated();
        });

        static::deleted(function (Model $model): void {
            $model->syncFeedDeleted();
        });
    }

    protected function syncFeedCreated(): void
    {
        Feed::updateOrCreate([
            'feedable_id' => $this->getKey(),
            'feedable_type' => $this->getMorphClass(),
        ], [
            'is_pinned' => $this->getAttribute('is_pinned') ?: false,
            'status' => $this->getFeedStatus(),
        ]);
    }

    protected function syncFeedUpdated(): void
    {
        if (! $this->isDirty('status') && ! $this->isDirty('deleted_at')) {
            return;
        }

        if ($this->isDirty('deleted_at') && $this->getAttribute('deleted_at') !== null) {
            $this->syncFeedDeleted();

            return;
        }

        Feed::where('feedable_id', $this->getKey())
            ->where('feedable_type', $this->getMorphClass())
            ->update([
                'status' => $this->getFeedStatus(),
            ]);
    }

    protected function syncFeedDeleted(): void
    {
        Feed::where('feedable_id', $this->getKey())
            ->where('feedable_type', $this->getMorphClass())
            ->delete();
    }

    protected function getFeedStatus(): string
    {
        $status = $this->getAttribute('status');

        if ($status === null || $status === '') {
            return 'active';
        }

        return (string) $status;
    }
}
