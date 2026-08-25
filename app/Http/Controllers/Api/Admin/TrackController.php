<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Api\Admin\StoreTrackRequest;
use App\Http\Requests\Api\Admin\UpdateTrackRequest;
use App\Models\Track;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrackController extends CurriculumController
{
    public function index(Request $request): JsonResponse
    {
        return $this->indexResponse($request, Track::class, ['grade.stage', 'subjects'], ['created_at', 'name_ar', 'name_en', 'code']);
    }

    public function show(Track $track): JsonResponse
    {
        return $this->showResponse($track->load(['grade', 'subjects']));
    }

    public function store(StoreTrackRequest $request): JsonResponse
    {
        $data = $this->prepareCode($request->validated(), Track::class, 'track');

        return $this->storeResponse(Track::create($data)->load('grade'));
    }

    public function update(UpdateTrackRequest $request, Track $track): JsonResponse
    {
        $track->update($this->prepareCode($request->validated(), Track::class, 'track'));

        return $this->updateResponse($track->refresh()->load('grade'));
    }

    public function destroy(Track $track): JsonResponse
    {
        if ($track->subjects()->exists()) {
            return response()->json(['message' => 'لا يمكن حذف المسار لارتباطه بمواد دراسية'], 422);
        }

        $track->delete();

        return $this->destroyResponse();
    }
}