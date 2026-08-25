<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Api\Admin\StoreSubjectRequest;
use App\Http\Requests\Api\Admin\UpdateSubjectRequest;
use App\Models\Subject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubjectController extends CurriculumController
{
    public function index(Request $request): JsonResponse
    {
        return $this->indexResponse($request, Subject::class, ['grade.stage', 'track.grade.stage'], ['created_at', 'name_ar', 'name_en', 'code']);
    }

    public function show(Subject $subject): JsonResponse
    {
        return $this->showResponse($subject->load(['grade', 'track']));
    }

    public function store(StoreSubjectRequest $request): JsonResponse
    {
        $data = $this->prepareCode($request->validated(), Subject::class, 'subject');

        return $this->storeResponse(Subject::create($data)->load(['grade', 'track']));
    }

    public function update(UpdateSubjectRequest $request, Subject $subject): JsonResponse
    {
        $subject->update($this->prepareCode($request->validated(), Subject::class, 'subject'));

        return $this->updateResponse($subject->refresh()->load(['grade', 'track']));
    }

    public function destroy(Subject $subject): JsonResponse
    {
        $subject->delete();

        return $this->destroyResponse();
    }
}