<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SubjectUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurriculumUnitsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SubjectUnit::query()->with(['subject.grade.stage', 'subject.track.grade.stage'])->orderBy('subject_id');

        if ($request->filled('subject_id')) {
            $query->where('subject_id', (int) $request->input('subject_id'));
        }

        $units = $query->get();

        return response()->json([
            'success' => true,
            'data' => $units,
            'units' => $units->map(function (SubjectUnit $subjectUnit): array {
                return [
                    'subject_id' => $subjectUnit->subject_id,
                    'unit_numbers' => $subjectUnit->total_units > 0
                        ? range(1, (int) $subjectUnit->total_units)
                        : [],
                ];
            })->values(),
            'count' => $units->count(),
        ]);
    }

    public function show(SubjectUnit $subjectUnit): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $subjectUnit,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject_id' => ['required', 'integer', 'exists:subjects,id'],
            'total_units' => ['required', 'integer', 'min:0', 'max:50'],
        ]);

        $unit = SubjectUnit::updateOrCreate(
            [
                'subject_id' => (int) $validated['subject_id'],
            ],
            [
                'total_units' => (int) $validated['total_units'],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'تم حفظ عدد الوحدات بنجاح',
            'data' => $unit,
        ], 201);
    }

    public function update(Request $request, SubjectUnit $subjectUnit): JsonResponse
    {
        $validated = $request->validate([
            'subject_id' => ['sometimes', 'integer', 'exists:subjects,id'],
            'total_units' => ['sometimes', 'integer', 'min:0', 'max:50'],
        ]);

        if (array_key_exists('subject_id', $validated)) {
            $subjectUnit->subject_id = (int) $validated['subject_id'];
        }

        if (array_key_exists('total_units', $validated)) {
            $subjectUnit->total_units = (int) $validated['total_units'];
        }

        $subjectUnit->save();

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث عدد الوحدات بنجاح',
            'data' => $subjectUnit->fresh(),
        ]);
    }
}
