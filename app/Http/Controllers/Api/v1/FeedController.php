<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

use App\Http\Controllers\Controller;
use App\Http\Resources\FeedResource;
use App\Models\PostVote;
use App\Models\Posts\Post;
use App\Services\FeedService;

class FeedController extends Controller
{
    public function __construct(private readonly FeedService $feedService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $feedData = $this->feedService->getPaginatedFeed(15);

        if ($user) {
            $postIds = $feedData->pluck('feedable')
                ->filter(fn ($item) => $item instanceof Post)
                ->pluck('id')
                ->all();

            if (! empty($postIds)) {
                $userVotes = PostVote::whereIn('post_id', $postIds)
                    ->where('user_id', $user->id)
                    ->get()
                    ->keyBy('post_id');

                foreach ($feedData as $feedItem) {
                    $feedable = $feedItem->feedable;
                    if (! $feedable instanceof Post) {
                        continue;
                    }

                    $vote = $userVotes->get($feedable->id);
                    $feedable->setRelation('votes', empty($vote) ? collect() : collect([$vote]));
                }
            }
        }

        return FeedResource::collection($feedData);
    }
}
