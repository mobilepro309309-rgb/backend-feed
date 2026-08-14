<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Model as EloquentModel;

class QuestionExplanation extends Model
{
    use HasFactory;

    protected $table = 'question_explanations';

    protected $fillable = [
        'question_type',
        'question_id',
        'video_url',
        'teacher_id',
    ];

    public static function upsertForQuestion(EloquentModel $question, ?string $videoUrl, ?int $teacherId = null): ?self
    {
        $normalizedUrl = trim((string) ($videoUrl ?? ''));

        if ($normalizedUrl === '') {
            static::query()
                ->where('question_type', $question::class)
                ->where('question_id', $question->getKey())
                ->delete();

            return null;
        }

        return static::updateOrCreate(
            [
                'question_type' => $question::class,
                'question_id' => $question->getKey(),
            ],
            [
                'video_url' => $normalizedUrl,
                'teacher_id' => $teacherId ?? $question->user_id ?? null,
            ]
        );
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function question(): MorphTo
    {
        return $this->morphTo('question', 'question_type', 'question_id');
    }
}
