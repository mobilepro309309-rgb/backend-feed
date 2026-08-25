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
use Illuminate\Support\Facades\Log;

class FeedController extends Controller
{
    public function __construct(private readonly FeedService $feedService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $rawUnitNumber = $request->query('unit_number', $request->input('unit_number', 'all'));
        $unitNumber = (is_string($rawUnitNumber) && strtolower(trim($rawUnitNumber)) === 'all') ? null : ($rawUnitNumber !== null && $rawUnitNumber !== '' ? (int) $rawUnitNumber : null);
        $subject = $request->query('subject', $request->input('subject', null));
        $subjectId = is_numeric($request->query('subject_id', $request->input('subject_id')))
            ? (int) $request->query('subject_id', $request->input('subject_id'))
            : null;
        $difficulty = strtolower(trim((string) $request->input('difficulty', '')));
        $difficulty = in_array($difficulty, ['easy', 'medium', 'hard'], true) ? $difficulty : null;

        Log::info('[FeedScopeDebug] request scope', [
            'user_id' => $user?->id,
            'stage_id' => $request->input('stage_id'),
            'grade_id' => $request->input('grade_id'),
            'track_id' => $request->input('track_id'),
            'subject_id' => $subjectId,
            'unit_number' => $unitNumber,
            'difficulty' => $difficulty,
        ]);

        $feedData = $this->feedService->getPaginatedFeed(15, $unitNumber, $subject, $subjectId, $difficulty);

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
