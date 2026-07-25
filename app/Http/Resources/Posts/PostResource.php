<?php

declare(strict_types=1);

namespace App\Http\Resources\Posts;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'subject' => $this->subject,
            'content' => $this->content,
            'image_url' => $this->image_url,
            'attachments' => $this->attachments,
            'status' => $this->status,
            'published_at' => $this->published_at,
            'likes' => $this->likes,
            'comments' => $this->comments,
            'shares' => $this->shares,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            // include the user when the relation was eager-loaded
            'user' => $this->whenLoaded('user', function () {
                $user = $this->user;
                if (! $user) return null;

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'avatar' => $user->avatar ?? $user->avatar_url ?? null,
                ];
            }),
        ];
    }
}
