<?php

declare(strict_types=1);

namespace App\Http\Resources\Challenges;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CloudCapsuleChallengeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            'subject' => $this->subject,
            'intro_text' => $this->intro_text,
            'badge_text' => $this->badge_text,
            'reveal_text' => $this->reveal_text,
            'tip_text' => $this->tip_text,
            'mood_text' => $this->mood_text,
            'reveal_label' => $this->reveal_label,
            'icon' => $this->icon,
            'status' => $this->status,
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
