<?php

declare(strict_types=1);

namespace App\Http\Resources\Challenges;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailyChallengeResource extends JsonResource
{
    public function toArray($request): array
    {
        $quizAccessService = app(\App\Services\QuizAccessService::class);

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'file_url' => $this->file_url ?? null,
            'title' => $this->title,
            'subject' => $this->subject,
            'prompt' => $this->prompt,
            'options' => $this->options,
            'correct_answer_index' => $this->correct_answer_index,
            'correct_index_answer' => $this->correct_answer_index,
            'badge_text' => $this->badge_text,
            'reward_text' => $this->reward_text,
            'expires_in_hours' => $this->expires_in_hours,
            'day_date' => $this->published_at,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'access_rules' => $quizAccessService->buildAccessRulesObject('daily_challenge', $request->user()),
        ];
    }
}
