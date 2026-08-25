<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Api\Admin\StoreSpecializedSubjectRequest;
use App\Http\Requests\Api\Admin\UpdateSpecializedSubjectRequest;
use App\Models\SpecializedSubject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SpecializedSubjectController extends CurriculumController
{
    public function index(Request $request): JsonResponse
    {
        return $this->indexResponse(
            $request,
            SpecializedSubject::class,
            ['track.grade.stage'],
            ['created_at', 'sort_order', 'name_ar', 'name_en', 'code']
        );
    }

    public function show(SpecializedSubject $specializedSubject): JsonResponse
    {
        return $this->showResponse($specializedSubject->load('track'));
    }

    public function store(StoreSpecializedSubjectRequest $request): JsonResponse
    {
        $data = $this->prepareCode($request->validated(), SpecializedSubject::class, 'specialized-subject');

        return $this->storeResponse(SpecializedSubject::create($data)->load('track'));
    }

    public function update(UpdateSpecializedSubjectRequest $request, SpecializedSubject $specializedSubject): JsonResponse
    {
        $specializedSubject->update(
            $this->prepareCode($request->validated(), SpecializedSubject::class, 'specialized-subject')
        );

        return $this->updateResponse($specializedSubject->refresh()->load('track'));
    }

    public function destroy(SpecializedSubject $specializedSubject): JsonResponse
    {
        $specializedSubject->delete();

        return $this->destroyResponse();
    }
}