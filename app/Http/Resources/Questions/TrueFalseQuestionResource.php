<?php

declare(strict_types=1);

namespace App\Http\Resources\Questions;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Concerns\ResolvesSubjectNameAr;

class TrueFalseQuestionResource extends JsonResource
{
    use ResolvesSubjectNameAr;

    public function toArray($request): array
    {
        $rawCorrectAnswer = $this->getRawOriginal('correct_answer');
        $normalizedCorrectAnswer = trim((string) $rawCorrectAnswer) === '0';

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'file_url' => $this->file_url ?? null,
            'explanation' => $this->getRawOriginal('explanation'),
            'explanation_video_url' => $this->explanation?->video_url ?? null,
            'title' => $this->title,
            'subject' => $this->subject,
            'subject_name_ar' => $this->resolveSubjectNameAr(),
            'statement' => $this->prompt,
            'correct_answer_index' => (int) $rawCorrectAnswer,
            'correct_answer' => $normalizedCorrectAnswer,
            'correctAnswer' => $normalizedCorrectAnswer,
            'badge_text' => $this->badge_text,
            'status' => $this->status,
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
