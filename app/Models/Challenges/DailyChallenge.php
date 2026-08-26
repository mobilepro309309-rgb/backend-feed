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

class DailyChallenge extends Model
{
    use HasFactory;
    use SyncsToFeed;

    protected $table = 'daily_challenges';

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
        'prompt',
        'explanation',
        'file_url',
        'options',
        'correct_answer_index',
        'badge_text',
        'reward_text',
        'expires_in_hours',
        'difficulty',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'subject_id' => 'integer',
            'correct_answer_index' => 'integer',
            'unit_number' => 'integer',
            'expires_in_hours' => 'integer',
            'published_at' => 'datetime',
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
