<?php

declare(strict_types=1);

namespace App\Http\Resources\Questions;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TrueFalseQuestionResource extends JsonResource
{
    public function toArray($request): array
    {
        $normalizedCorrectAnswer = $this->correct_answer === 0 ? true : false;

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'file_url' => $this->file_url ?? null,
            'title' => $this->title,
            'subject' => $this->subject,
            'statement' => $this->prompt,
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
