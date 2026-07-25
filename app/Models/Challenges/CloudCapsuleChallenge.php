<?php

declare(strict_types=1);

namespace App\Models\Challenges;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

use App\Models\Feed;
use App\Models\User;
use App\Traits\SyncsToFeed;

class CloudCapsuleChallenge extends Model
{
    use HasFactory;
    use SyncsToFeed;

    protected static function booted(): void
    {
        static::deleting(function (self $item): void {
            Feed::where('feedable_type', get_class($item))
                ->where('feedable_id', $item->id)
                ->delete();
        });
    }

    protected $fillable = [
        'user_id',
        'title',
        'subject',
        'intro_text',
        'badge_text',
        'reveal_text',
        'tip_text',
        'mood_text',
        'reveal_label',
        'icon',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function feeds(): MorphMany
    {
        return $this->morphMany(Feed::class, 'feedable');
    }
}
