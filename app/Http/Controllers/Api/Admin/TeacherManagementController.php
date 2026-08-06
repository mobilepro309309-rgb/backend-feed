<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\FiltersQuestionListings;
use App\Http\Controllers\Controller;
use App\Models\Challenges\CloudCapsuleChallenge;
use App\Models\Challenges\ComparisonChallenge;
use App\Models\Challenges\DailyChallenge;
use App\Models\Challenges\FindTheBugChallenge;
use App\Models\Challenges\LiveDuelChallenge;
use App\Models\Questions\MultipleChoiceQuestion;
use App\Models\Questions\TrueFalseQuestion;
use App\Models\TeacherScope;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TeacherManagementController extends Controller
{
    use FiltersQuestionListings;
    public function lookupUser($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'عذراً، هذا الرقم التعريفي (ID) غير مسجل بالنظام',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = User::query()
            ->where(function ($q) use ($search) {
                $q->where('role', 'teacher')
                    ->orWhereHas('teacherScopes');

                if ($search !== '') {
                    $q->where(function ($inner) use ($search) {
                        $inner->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%" );
                    });
                }
            })
            ->with('teacherScopes');

        $teachers = $query->orderBy('name')->get();

        return response()->json([
            'status' => 'success',
            'data' => $teachers->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role,
                    'teacher_scopes' => $user->teacherScopes->map(function (TeacherScope $scope) {
                        return [
                            'id' => $scope->id,
                            'school_grade' => $scope->school_grade,
                            'subject' => $scope->subject,
                            'can_answer' => (bool) $scope->can_answer,
                            'can_create_questions' => (bool) $scope->can_create_questions,
                        ];
                    }),
                ];
            }),
        ]);
    }

    public function myScopes(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized',
            ], 401);
        }

        $scopes = TeacherScope::where('user_id', $user->id)
            ->orderBy('school_grade')
            ->orderBy('subject')
            ->get()
            ->map(function (TeacherScope $scope) {
                return [
                    'id' => $scope->id,
                    'grade' => $scope->school_grade,
                    'subject' => $scope->subject,
                    'can_answer' => (bool) $scope->can_answer,
                    'can_create_questions' => (bool) $scope->can_create_questions,
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => $scopes,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
            ],
        ]);
    }

    public function getMyQuestionsByCategory(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            Log::info('🔥🔥🔥 [BACKEND DEBUG START] 🔥🔥🔥');
            Log::info('📥 [REQUEST PARAMS]:', $request->all());
            Log::info('👤 [AUTH USER ID]: ' . auth()->id());
            Log::info('⚠️ [NO AUTH USER]');
            Log::info('🔥🔥🔥 [BACKEND DEBUG END] 🔥🔥🔥');

            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $type = trim((string) $request->query('type', ''));
        $allowedTypes = [
            'true_false' => 'true_false_questions',
            'multiple_choice' => 'multiple_choice_questions',
            'cloud_capsule' => 'cloud_capsule_challenges',
            'daily_challenge' => 'daily_challenges',
            'comparison' => 'comparison_challenges',
            'find_the_bug' => 'find_the_bug_challenges',
            'live_duel' => 'live_duel_challenges',
        ];

        Log::info('🔥🔥🔥 [BACKEND DEBUG START] 🔥🔥🔥');
        Log::info('📥 [REQUEST PARAMS]:', $request->all());
        Log::info('👤 [AUTH USER ID]: ' . auth()->id());
        Log::info('🧩 [REQUEST TYPE]: ' . $type);

        if ($type === '' || ! array_key_exists($type, $allowedTypes)) {
            Log::info('❌ [INVALID TYPE]: ' . $type);
            Log::info('🔥🔥🔥 [BACKEND DEBUG END] 🔥🔥🔥');

            return response()->json([
                'success' => false,
                'message' => 'Invalid or missing type parameter.',
            ], 422);
        }

        $table = $allowedTypes[$type];
        Log::info('📊 [TARGET TABLE]: ' . $table);

        $query = DB::table($table)->where('user_id', $user->id);

        $resolvedSubject = $this->resolveListingSubject($request);
        $resolvedGrade = $this->resolveListingSchoolGrade($request);
        $subjectVariants = $this->buildSubjectVariants($resolvedSubject);
        $gradeVariants = $this->buildSchoolGradeVariants($resolvedGrade);

        Log::info('📌 [RESOLVED subject]: ' . ($resolvedSubject ?? 'NULL'));
        Log::info('📌 [RESOLVED school_grade]: ' . ($resolvedGrade ?? 'NULL'));
        Log::info('📌 [SUBJECT VARIANTS]: ' . json_encode($subjectVariants, JSON_UNESCAPED_UNICODE));
        Log::info('📌 [GRADE VARIANTS]: ' . json_encode($gradeVariants, JSON_UNESCAPED_UNICODE));

        if ($subjectVariants !== []) {
            $query->where(function ($subjectQuery) use ($subjectVariants) {
                foreach ($subjectVariants as $index => $variant) {
                    $variant = trim((string) $variant);
                    if ($variant === '') {
                        continue;
                    }

                    $normalizedVariant = $this->normalizeComparableToken($variant);
                    $subjectCondition = 'LOWER(TRIM(subject)) = ?';

                    if ($index === 0) {
                        $subjectQuery->whereRaw($subjectCondition, [$normalizedVariant]);
                    } else {
                        $subjectQuery->orWhereRaw($subjectCondition, [$normalizedVariant]);
                    }
                }
            });
        }

        if ($gradeVariants !== []) {
            $query->where(function ($gradeQuery) use ($gradeVariants) {
                foreach ($gradeVariants as $index => $variant) {
                    $variant = trim((string) $variant);
                    if ($variant === '') {
                        continue;
                    }

                    $normalizedVariant = $this->normalizeComparableToken($variant);
                    $gradeCondition = 'LOWER(TRIM(school_grade)) = ?';

                    if ($index === 0) {
                        $gradeQuery->whereRaw($gradeCondition, [$normalizedVariant]);
                    } else {
                        $gradeQuery->orWhereRaw($gradeCondition, [$normalizedVariant]);
                    }
                }
            });
        }

        $items = $query->get();

        Log::info('🔢 [RAW TABLE COUNT (user_id only)]:', ['count' => $items->count()]);
        Log::info('📦 [RAW ITEMS RETURNED FROM DB]:', $items->toArray());
        Log::info('🔥🔥🔥 [BACKEND DEBUG END] 🔥🔥🔥');

        return response()->json([
            'success' => true,
            'items' => $items,
        ]);
    }

    public function deleteMyQuestion(Request $request, string $type, int $id)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $modelClass = $this->getModelClassForQuestionType($type);

        if (! $modelClass) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid question type.',
            ], 422);
        }

        $item = $modelClass::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Question not found or unauthorized.',
            ], 404);
        }

        try {
            $item->delete();
        } catch (\Throwable $e) {
            Log::error('[TeacherManagementController] Failed to delete question', [
                'type' => $type,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete the question.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم حذف السؤال بنجاح',
        ]);
    }

    public function updateMyQuestion(Request $request, string $type, int $id)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $modelClass = $this->getModelClassForQuestionType($type);

        if (! $modelClass) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid question type.',
            ], 422);
        }

        $item = $modelClass::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $item) {
            return response()->json([
                'success' => false,
                'message' => 'Question not found or unauthorized.',
            ], 404);
        }

        $rules = $this->getValidationRulesForQuestionType($type);
        if (! $rules) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to validate this question type.',
            ], 422);
        }

        $validated = $request->validate($rules);

        if (isset($validated['status']) && $validated['status'] === 'published' && ! $item->published_at) {
            $validated['published_at'] = now();
        }

        try {
            $item->fill($validated);
            $item->save();
        } catch (\Throwable $e) {
            Log::error('[TeacherManagementController] Failed to update question', [
                'type' => $type,
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update the question.',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم تعديل السؤال بنجاح',
            'item' => $item,
        ]);
    }

    protected function getValidationRulesForQuestionType(string $type): ?array
    {
        return [
            'multiple_choice' => [
                'title' => ['required', 'string', 'max:180'],
                'subject' => ['required', 'string', 'max:120'],
                'school_grade' => ['nullable', 'string'],
                'term' => ['nullable', 'in:1,2'],
                'question' => ['nullable', 'string'],
                'options' => ['required', 'array', 'min:2'],
                'options.*' => ['nullable', 'string'],
                'correct_index' => ['required', 'integer', 'min:0', 'max:3'],
                'badge_text' => ['nullable', 'string', 'max:120'],
                'file_url' => ['nullable', 'string', 'max:2048'],
                'status' => ['nullable', 'in:draft,published'],
            ],
            'true_false' => [
                'title' => ['required', 'string', 'max:180'],
                'subject' => ['required', 'string', 'max:120'],
                'school_grade' => ['nullable', 'string'],
                'term' => ['nullable', 'in:1,2'],
                'prompt' => ['nullable', 'string'],
                'correct_answer' => ['required', 'boolean'],
                'explanation' => ['nullable', 'string'],
                'badge_text' => ['nullable', 'string', 'max:120'],
                'file_url' => ['nullable', 'string', 'max:2048'],
                'status' => ['nullable', 'in:draft,published'],
            ],
            'daily_challenge' => [
                'title' => ['required', 'string', 'max:180'],
                'subject' => ['required', 'string', 'max:120'],
                'school_grade' => ['nullable', 'string'],
                'term' => ['nullable', 'in:1,2'],
                'prompt' => ['nullable', 'string'],
                'options' => ['required', 'array', 'min:2'],
                'options.*' => ['nullable', 'string'],
                'correct_answer_index' => ['required', 'integer', 'min:0', 'max:3'],
                'badge_text' => ['nullable', 'string', 'max:120'],
                'reward_text' => ['nullable', 'string', 'max:180'],
                'expires_in_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
                'file_url' => ['nullable', 'string', 'max:2048'],
                'status' => ['nullable', 'in:draft,published'],
            ],
            'comparison' => [
                'title' => ['required', 'string', 'max:180'],
                'subject' => ['required', 'string', 'max:120'],
                'school_grade' => ['nullable', 'string'],
                'term' => ['nullable', 'in:1,2'],
                'left_label' => ['nullable', 'string', 'max:80'],
                'right_label' => ['nullable', 'string', 'max:80'],
                'left_text' => ['required', 'string'],
                'right_text' => ['required', 'string'],
                'explanation' => ['nullable', 'string'],
                'badge_text' => ['nullable', 'string', 'max:120'],
                'file_url' => ['nullable', 'string', 'max:2048'],
                'status' => ['nullable', 'in:draft,published'],
            ],
            'find_the_bug' => [
                'title' => ['required', 'string', 'max:180'],
                'subject' => ['required', 'string', 'max:120'],
                'school_grade' => ['nullable', 'string'],
                'term' => ['nullable', 'in:1,2'],
                'prompt' => ['nullable', 'string'],
                'options' => ['required', 'array', 'min:2'],
                'options.*' => ['nullable', 'string'],
                'correct_answer_index' => ['required', 'integer', 'min:0', 'max:3'],
                'badge_text' => ['nullable', 'string', 'max:120'],
                'file_url' => ['nullable', 'string', 'max:2048'],
                'status' => ['nullable', 'in:draft,published'],
            ],
            'cloud_capsule' => [
                'title' => ['required', 'string', 'max:180'],
                'subject' => ['required', 'string', 'max:120'],
                'school_grade' => ['nullable', 'string'],
                'term' => ['nullable', 'in:1,2'],
                'intro_text' => ['nullable', 'string'],
                'reveal_text' => ['required', 'string'],
                'tip_text' => ['nullable', 'string'],
                'mood_text' => ['nullable', 'string'],
                'reveal_label' => ['nullable', 'string', 'max:120'],
                'icon' => ['nullable', 'string', 'max:50'],
                'badge_text' => ['nullable', 'string', 'max:120'],
                'file_url' => ['nullable', 'string', 'max:2048'],
                'status' => ['nullable', 'in:draft,published'],
            ],
            'live_duel' => [
                'title' => ['required', 'string', 'max:180'],
                'subject' => ['required', 'string', 'max:120'],
                'school_grade' => ['nullable', 'string'],
                'term' => ['nullable', 'in:1,2'],
                'challenge_text' => ['nullable', 'string'],
                'badge_text' => ['nullable', 'string', 'max:80'],
                'file_url' => ['nullable', 'string', 'max:2048'],
                'question_count' => ['required', 'integer', 'min:1', 'max:20'],
                'seconds_per_question' => ['required', 'integer', 'min:5', 'max:120'],
                'questions' => ['required', 'array', 'min:1'],
                'questions.*.prompt' => ['nullable', 'string'],
                'questions.*.options' => ['required', 'array', 'size:4'],
                'questions.*.options.*' => ['nullable', 'string'],
                'questions.*.correctIndex' => ['required', 'integer', 'min:0', 'max:3'],
                'status' => ['nullable', 'in:draft,published'],
            ],
        ][$type] ?? null;
    }

    protected function getModelClassForQuestionType(string $type): ?string
    {
        return [
            'multiple_choice' => MultipleChoiceQuestion::class,
            'true_false' => TrueFalseQuestion::class,
            'cloud_capsule' => CloudCapsuleChallenge::class,
            'daily_challenge' => DailyChallenge::class,
            'comparison' => ComparisonChallenge::class,
            'find_the_bug' => FindTheBugChallenge::class,
            'live_duel' => LiveDuelChallenge::class,
        ][$type] ?? null;
    }

    public function assignScope(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'school_grade' => 'required|string',
            'subject' => 'required|string',
            'can_answer' => 'nullable|boolean',
            'can_create_questions' => 'nullable|boolean',
        ]);

        $user = User::findOrFail($validated['user_id']);

        if (strtolower((string) $user->role) === 'user') {
            $user->forceFill(['role' => 'teacher'])->save();
        }

        $scope = TeacherScope::updateOrCreate(
            [
                'user_id' => $user->id,
                'school_grade' => (string) $validated['school_grade'],
                'subject' => $validated['subject'],
            ],
            [
                'can_answer' => $validated['can_answer'] ?? true,
                'can_create_questions' => $validated['can_create_questions'] ?? true,
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'تم حفظ صلاحية المدرس بنجاح',
            'data' => [
                'id' => $scope->id,
                'user_id' => $scope->user_id,
                'school_grade' => $scope->school_grade,
                'subject' => $scope->subject,
                'can_answer' => (bool) $scope->can_answer,
                'can_create_questions' => (bool) $scope->can_create_questions,
            ],
        ]);
    }

    public function removeScope($scopeId)
    {
        $scope = TeacherScope::findOrFail($scopeId);
        $user = $scope->user;

        $scope->delete();

        if ($user) {
            $remainingScopes = $user->teacherScopes()->count();
            if ($remainingScopes === 0) {
                $user->forceFill(['role' => 'user'])->save();
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'تم حذف صلاحية المادة بنجاح',
        ]);
    }
}
