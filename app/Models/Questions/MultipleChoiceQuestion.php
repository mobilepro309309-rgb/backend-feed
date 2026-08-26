<?php

declare(strict_types=1);

namespace App\Models\Questions;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\{BelongsTo, MorphMany, MorphOne};

use App\Models\{Feed, QuestionExplanation, Subject, User};
use App\Traits\SyncsToFeed;

class MultipleChoiceQuestion extends Model
{
    use HasFactory;
    use SyncsToFeed;

    protected $table = 'multiple_choice_questions';

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
        'question',
        'explanation',
        'file_url',
        'options',
        'correct_index',
        'badge_text',
        'difficulty',
        'status',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'subject_id' => 'integer',
            'stage_id' => 'integer',
            'grade_id' => 'integer',
            'track_id' => 'integer',
            'correct_index' => 'integer',
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
