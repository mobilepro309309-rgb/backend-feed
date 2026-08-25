<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Api\Admin\StoreGradeRequest;
use App\Http\Requests\Api\Admin\UpdateGradeRequest;
use App\Models\Grade;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GradeController extends CurriculumController
{
    public function index(Request $request): JsonResponse
    {
        return $this->indexResponse($request, Grade::class, ['stage', 'tracks', 'subjects']);
    }

    public function show(Grade $grade): JsonResponse
    {
        return $this->showResponse($grade->load(['stage', 'tracks', 'subjects']));
    }

    public function store(StoreGradeRequest $request): JsonResponse
    {
        $data = $this->prepareCode($request->validated(), Grade::class, 'grade');

        return $this->storeResponse(Grade::create($data)->load('stage'));
    }

    public function update(UpdateGradeRequest $request, Grade $grade): JsonResponse
    {
        $grade->update($this->prepareCode($request->validated(), Grade::class, 'grade'));

        return $this->updateResponse($grade->refresh()->load('stage'));
    }

    public function destroy(Grade $grade): JsonResponse
    {
        if ($grade->tracks()->exists() || $grade->subjects()->exists()) {
            return response()->json(['message' => 'لا يمكن حذف الصف لارتباطه بمسارات أو مواد دراسية'], 422);
        }

        $grade->delete();

        return $this->destroyResponse();
    }
}