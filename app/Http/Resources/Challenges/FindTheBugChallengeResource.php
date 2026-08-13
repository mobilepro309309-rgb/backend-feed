<?php

declare(strict_types=1);

namespace App\Http\Resources\Challenges;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FindTheBugChallengeResource extends JsonResource
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
            'bug_description' => $this->prompt,
            'options' => $this->options,
            'correct_answer_index' => $this->correct_answer_index,
            'correct_index_answer' => $this->correct_answer_index,
            'badge_text' => $this->badge_text,
            'status' => $this->status,
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'access_rules' => $quizAccessService->buildAccessRulesObject('find_the_bug', $request->user()),
        ];
    }
}
