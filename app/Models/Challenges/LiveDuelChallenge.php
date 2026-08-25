<?php

declare(strict_types=1);

namespace App\Models\Challenges;

use App\Models\Feed;
use App\Models\QuestionExplanation;
use App\Models\Subject;
use App\Models\User;
use App\Traits\SyncsToFeed;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class LiveDuelChallenge extends Model
{
    use HasFactory;
    use SyncsToFeed;

    protected $table = 'live_duel_challenges';

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
        'subject_id',
        'stage_id',
        'grade_id',
        'track_id',
        'title',
        'subject',
        'school_grade',
        'term',
        'unit_number',
        'challenge_text',
        'file_url',
        'badge_text',
        'question_count',
        'seconds_per_question',
        'questions',
        'difficulty',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'subject_id' => 'integer',
            'published_at' => 'datetime',
            'question_count' => 'integer',
            'seconds_per_question' => 'integer',
            'unit_number' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
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
