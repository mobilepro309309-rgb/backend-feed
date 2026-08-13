<?php

declare(strict_types=1);

namespace App\Http\Resources\Challenges;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LiveDuelChallengeResource extends JsonResource
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
            'challenge_text' => $this->challenge_text,
            'badge_text' => $this->badge_text,
            'question_count' => $this->question_count,
            'seconds_per_question' => $this->seconds_per_question,
            'questions' => $this->questions,
            'status' => $this->status,
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'access_rules' => $quizAccessService->buildAccessRulesObject('live_duel', $request->user()),
        ];
    }
}
