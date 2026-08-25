<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

abstract class CurriculumController extends Controller
{
    protected function indexResponse(Request $request, string $modelClass, array $with = [], array $sortable = []): JsonResponse
    {
        $query = $modelClass::query()->with($with);

        if ($request->filled('is_active')) {
            $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->input('search')) . '%';
            $query->where(function ($searchQuery) use ($search) {
                $searchQuery->where('code', 'like', $search)
                    ->orWhere('name_ar', 'like', $search)
                    ->orWhere('name_en', 'like', $search);
            });
        }

        $allowedSorts = $sortable ?: ['created_at', 'sort_order', 'name_ar', 'name_en', 'code'];
        $sort = in_array($request->query('sort'), $allowedSorts, true)
            ? $request->query('sort')
            : $allowedSorts[0];
        $direction = $request->query('direction') === 'desc' ? 'desc' : 'asc';
        $perPage = min(max((int) $request->query('per_page', 25), 1), 100);

        $items = $query->orderBy($sort, $direction)->orderBy('id', $direction)->paginate($perPage)->withQueryString();

        return response()->json([
            'message' => 'تم جلب البيانات بنجاح',
            'data' => $items->items(),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    protected function showResponse(Model $item): JsonResponse
    {
        return response()->json([
            'message' => 'تم جلب البيانات بنجاح',
            'data' => $item,
        ]);
    }

    protected function storeResponse(Model $item): JsonResponse
    {
        return response()->json([
            'message' => 'تمت الإضافة بنجاح',
            'data' => $item,
        ], 201);
    }

    protected function updateResponse(Model $item): JsonResponse
    {
        return response()->json([
            'message' => 'تم التحديث بنجاح',
            'data' => $item,
        ]);
    }

    protected function destroyResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'تم الحذف بنجاح',
        ]);
    }

    protected function prepareCode(array $data, string $modelClass, string $fallbackName): array
    {
        if (blank($data['code'] ?? null)) {
            $baseCode = Str::slug((string) ($data['name_en'] ?? $data['name_ar'] ?? ''));
            $baseCode = $baseCode !== '' ? $baseCode : Str::slug($fallbackName);
            $code = $baseCode;
            $suffix = 2;

            while ($modelClass::where('code', $code)->exists()) {
                $code = $baseCode . '_' . $suffix++;
            }

            $data['code'] = $code;
        }

        return $data;
    }
}