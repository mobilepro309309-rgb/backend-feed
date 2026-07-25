<?php

declare(strict_types=1);

namespace App\Models\Challenges;

use App\Models\Feed;
use App\Models\User;
use App\Traits\SyncsToFeed;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class LiveDuelChallenge extends Model
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
        'challenge_text',
        'badge_text',
        'question_count',
        'seconds_per_question',
        'questions',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'published_at' => 'datetime',
            'question_count' => 'integer',
            'seconds_per_question' => 'integer',
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
