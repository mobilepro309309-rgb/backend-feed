<?php

declare(strict_types=1);

namespace App\Models\Challenges;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

use App\Models\Feed;
use App\Models\QuestionExplanation;
use App\Models\User;
use App\Traits\SyncsToFeed;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class CloudCapsuleChallenge extends Model
{
    use HasFactory;
    use SyncsToFeed;

    protected $table = 'cloud_capsule_challenges';

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
        'school_grade',
        'term',
        'intro_text',
        'file_url',
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

    public function explanation(): MorphOne
    {
        return $this->morphOne(QuestionExplanation::class, 'question');
    }

    public function feeds(): MorphMany
    {
        return $this->morphMany(Feed::class, 'feedable');
    }
}
