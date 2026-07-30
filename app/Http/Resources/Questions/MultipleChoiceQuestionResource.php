<?php

declare(strict_types=1);

namespace App\Http\Resources\Questions;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MultipleChoiceQuestionResource extends JsonResource
{
    public function toArray($request): array
    {
        $options = is_array($this->options) ? array_values($this->options) : [];
        $correctIndex = (int) ($this->correct_index ?? 0);
        $correctAnswerValue = isset($options[$correctIndex]) ? (string) $options[$correctIndex] : null;

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'file_url' => $this->file_url ?? null,
            'title' => $this->title,
            'subject' => $this->subject,
            'question_text' => $this->question,
            'options' => $options,
            'correct_index' => $correctIndex,
            'correct_answer_index' => $correctIndex,
            'correct_answer' => $correctAnswerValue,
            'correctAnswer' => $correctAnswerValue,
            'badge_text' => $this->badge_text,
            'status' => $this->status,
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
