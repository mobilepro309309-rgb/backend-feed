<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\v1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

use App\Http\Controllers\Controller;
use App\Http\Resources\FeedResource;
use App\Services\FeedService;

class FeedController extends Controller
{
    public function __construct(private readonly FeedService $feedService) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $feedData = $this->feedService->getPaginatedFeed(15);

        return FeedResource::collection($feedData);
    }
}
