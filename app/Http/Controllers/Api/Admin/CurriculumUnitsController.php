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
        $query = SubjectUnit::query()->orderBy('school_grade')->orderBy('subject');

        if ($request->filled('school_grade')) {
            $query->where('school_grade', (string) $request->input('school_grade'));
        }

        if ($request->filled('subject')) {
            $query->where('subject', (string) $request->input('subject'));
        }

        $units = $query->get()->map(function ($unit) {
            $unit->school_grade = (string) $unit->school_grade;
            return $unit;
        });

        return response()->json([
            'success' => true,
            'data' => $units,
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
            'school_grade' => ['required', 'integer', 'min:1', 'max:3'],
            'subject' => ['required', 'string', 'max:120'],
            'total_units' => ['required', 'integer', 'min:0', 'max:50'],
        ]);

        $unit = SubjectUnit::updateOrCreate(
            [
                'school_grade' => (string) $validated['school_grade'],
                'subject' => trim((string) $validated['subject']),
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
            'school_grade' => ['sometimes', 'integer', 'min:1', 'max:3'],
            'subject' => ['sometimes', 'string', 'max:120'],
            'total_units' => ['sometimes', 'integer', 'min:0', 'max:50'],
        ]);

        if (array_key_exists('school_grade', $validated)) {
            $subjectUnit->school_grade = (string) $validated['school_grade'];
        }

        if (array_key_exists('subject', $validated)) {
            $subjectUnit->subject = trim((string) $validated['subject']);
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
