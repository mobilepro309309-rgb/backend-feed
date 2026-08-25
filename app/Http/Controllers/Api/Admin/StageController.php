<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Api\Admin\StoreStageRequest;
use App\Http\Requests\Api\Admin\UpdateStageRequest;
use App\Models\Stage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StageController extends CurriculumController
{
    public function index(Request $request): JsonResponse
    {
        return $this->indexResponse($request, Stage::class, ['grades']);
    }

    public function show(Stage $stage): JsonResponse
    {
        return $this->showResponse($stage->load('grades'));
    }

    public function store(StoreStageRequest $request): JsonResponse
    {
        $data = $this->prepareCode($request->validated(), Stage::class, 'stage');

        return $this->storeResponse(Stage::create($data));
    }

    public function update(UpdateStageRequest $request, Stage $stage): JsonResponse
    {
        $stage->update($this->prepareCode($request->validated(), Stage::class, 'stage'));

        return $this->updateResponse($stage->refresh());
    }

    public function destroy(Stage $stage): JsonResponse
    {
        if ($stage->grades()->exists()) {
            return response()->json(['message' => 'لا يمكن حذف المرحلة لارتباطها بصفوف دراسية'], 422);
        }

        $stage->delete();

        return $this->destroyResponse();
    }
}