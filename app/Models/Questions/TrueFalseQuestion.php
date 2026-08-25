<?php

declare(strict_types=1);

namespace App\Models\Questions;

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

class TrueFalseQuestion extends Model
{
    use HasFactory;
    use SyncsToFeed;

    protected $table = 'true_false_questions';

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
        'file_url',
        'correct_answer',
        'explanation',
        'badge_text',
        'difficulty',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'correct_answer' => 'boolean',
            'subject_id' => 'integer',
            'stage_id' => 'integer',
            'grade_id' => 'integer',
            'track_id' => 'integer',
            'unit_number' => 'integer',
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

    public function feeds(): MorphMany
    {
        return $this->morphMany(Feed::class, 'feedable');
    }

    public function explanation(): MorphOne
    {
        return $this->morphOne(QuestionExplanation::class, 'question');
    }
}
