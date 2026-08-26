<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\FiltersQuestionListings;
use App\Http\Controllers\Controller;
use App\Models\Challenges\CloudCapsuleChallenge;
use App\Models\Challenges\ComparisonChallenge;
use App\Models\Challenges\DailyChallenge;
use App\Models\Challenges\FindTheBugChallenge;
use App\Models\Challenges\LiveDuelChallenge;
use App\Models\QuestionExplanation;
use App\Models\Questions\MultipleChoiceQuestion;
use App\Models\Questions\TrueFalseQuestion;
use App\Models\TeacherScope;
use App\Models\User;
use App\Services\NotificationService;
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
        $subject = trim((string) $request->query('subject', ''));
        $schoolGrade = trim((string) $request->query('school_grade', ''));
        $userId = $request->query('user_id');

        $query = User::query();

        // Filter by user_id if provided
        if ($userId !== null && $userId !== '') {
            $query->where('id', (int) $userId);
        } else {
            // Default filtering for teachers if user_id not provided
            $query->where(function ($q) use ($search) {
                $q->where('role', 'teacher')
                    ->orWhereHas('teacherScopes');

                if ($search !== '') {
                    $q->where(function ($inner) use ($search) {
                        $inner->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%" );
                    });
                }
            });
        }

        // Filter by subject if provided
        if ($subject !== '') {
            $query->whereHas('teacherScopes', function ($q) use ($subject) {
                $q->where('subject', $subject);
            });
        }

        // Filter by school_grade if provided
        if ($schoolGrade !== '') {
            $query->whereHas('teacherScopes', function ($q) use ($schoolGrade) {
                $q->where('school_grade', $schoolGrade);
            });
        }

        // Load teacherScopes with optional filtering
        $query->with([
            'teacherScopes' => function ($q) use ($subject, $schoolGrade) {
                if ($subject !== '') {
                    $q->where('subject', $subject);
                }
                if ($schoolGrade !== '') {
                    $q->where('school_grade', $schoolGrade);
                }
            },
            'address'
        ]);

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
                    'school_grade' => $user->school_grade,
                    'gender' => $user->gender,
                    'address' => $user->address ? [
                        'governorate' => $user->address->governorate,
                        'city_or_center' => $user->address->city_or_center,
                        'village_name' => $user->address->village_name,
                        'latitude' => $user->address->latitude,
                        'longitude' => $user->address->longitude,
                    ] : null,
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
        Log::info('[QuestionsDebug] base query scope', [
            'user_id' => $user->id,
            'sql' => $query->toSql(),
            'bindings' => $query->getBindings(),
        ]);

        $resolvedSubject = $this->resolveListingSubject($request);
        $resolvedSubjectId = $request->query('subject_id', $request->input('subject_id'));
        $resolvedGrade = $this->resolveListingSchoolGrade($request);
        $resolvedUnit = $request->query('unit_number', $request->input('unit_number'));
        $subjectVariants = $this->buildSubjectVariants($resolvedSubject);
        $gradeVariants = $this->buildSchoolGradeVariants($resolvedGrade);

        Log::info('[QuestionsDebug] incoming filters', [
            'user_id' => $user->id,
            'type' => $type,
            'table' => $table,
            'subject_id_raw' => $resolvedSubjectId,
            'subject_id_valid' => is_numeric($resolvedSubjectId) && (int) $resolvedSubjectId > 0 ? (int) $resolvedSubjectId : null,
            'unit_number_raw' => $resolvedUnit,
            'unit_number_valid' => is_numeric($resolvedUnit) && (int) $resolvedUnit > 0 ? (int) $resolvedUnit : null,
        ]);

        Log::info('📌 [RESOLVED subject]: ' . ($resolvedSubject ?? 'NULL'));
        Log::info('📌 [RESOLVED subject_id]: ' . ($resolvedSubjectId ?? 'NULL'));
        Log::info('📌 [RESOLVED school_grade]: ' . ($resolvedGrade ?? 'NULL'));
        Log::info('📌 [SUBJECT VARIANTS]: ' . json_encode($subjectVariants, JSON_UNESCAPED_UNICODE));
        Log::info('📌 [GRADE VARIANTS]: ' . json_encode($gradeVariants, JSON_UNESCAPED_UNICODE));

        if (is_numeric($resolvedSubjectId) && (int) $resolvedSubjectId > 0) {
            $query->where('subject_id', (int) $resolvedSubjectId);
            Log::info('[QuestionsDebug] subject_id filter appended', [
                'subject_id' => (int) $resolvedSubjectId,
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings(),
            ]);
        } elseif ($subjectVariants !== []) {
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

        if (! (is_numeric($resolvedSubjectId) && (int) $resolvedSubjectId > 0) && $gradeVariants !== []) {
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

        if ($resolvedUnit !== null && $resolvedUnit !== '') {
            $query->where('unit_number', (int) $resolvedUnit);
            Log::info('[QuestionsDebug] unit_number filter appended', [
                'unit_number' => (int) $resolvedUnit,
                'sql' => $query->toSql(),
                'bindings' => $query->getBindings(),
            ]);
        }

        Log::info('[QuestionsDebug] final query before execution', [
            'uses_subject_id_filter' => is_numeric($resolvedSubjectId) && (int) $resolvedSubjectId > 0,
            'uses_legacy_subject_filter' => ! (is_numeric($resolvedSubjectId) && (int) $resolvedSubjectId > 0) && $subjectVariants !== [],
            'uses_legacy_grade_filter' => ! (is_numeric($resolvedSubjectId) && (int) $resolvedSubjectId > 0) && $gradeVariants !== [],
            'sql' => $query->toSql(),
            'bindings' => $query->getBindings(),
        ]);

        $items = $query->get();

        Log::info('[QuestionsDebug] query result', [
            'count' => $items->count(),
            'ids' => $items->pluck('id')->values()->all(),
            'subject_ids' => $items->pluck('subject_id')->unique()->values()->all(),
            'unit_numbers' => $items->pluck('unit_number')->unique()->values()->all(),
        ]);
        Log::info('📦 [RAW ITEMS RETURNED FROM DB]:', $items->toArray());
        Log::info('🔥🔥🔥 [BACKEND DEBUG END] 🔥🔥🔥');

        return response()->json([
            'success' => true,
            'items' => $items,
        ]);
    }

    public function toggleMyQuestionStatus(Request $request, string $type, int $id)
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

        $validated = $request->validate([
            'status' => ['nullable', 'in:draft,published'],
            'stage_id' => ['nullable', 'integer'],
            'grade_id' => ['nullable', 'integer'],
            'track_id' => ['nullable', 'integer'],
        ]);

        $requestedStatus = $validated['status'] ?? null;
        $currentStatus = $item->status ?? 'draft';
        $nextStatus = in_array($requestedStatus, ['draft', 'published'], true)
            ? $requestedStatus
            : ($currentStatus === 'published' ? 'draft' : 'published');

        if (! in_array($nextStatus, ['draft', 'published'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid status value.',
            ], 422);
        }

        try {
            foreach (['stage_id', 'grade_id', 'track_id'] as $scopeColumn) {
                if (array_key_exists($scopeColumn, $validated)) {
                    $item->{$scopeColumn} = $validated[$scopeColumn];
                }
            }

            $item->status = $nextStatus;
            $item->published_at = $nextStatus === 'published' ? ($item->published_at ?? now()) : null;
            $item->save();
        } catch (\Throwable $e) {
            Log::error('[TeacherManagementController] Failed to toggle question status', [
                'type' => $type,
                'id' => $id,
                'status' => $nextStatus,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update the question status.',
            ], 500);
        }

        $notifiedStudents = 0;
        if ($nextStatus === 'published' && $currentStatus !== 'published') {
            $targetStudents = User::query()
                ->where('role', 'user')
                ->where('stage_id', $item->stage_id)
                ->where('grade_id', $item->grade_id)
                ->when($item->track_id, fn ($query) => $query->where('track_id', $item->track_id))
                ->when(! $item->track_id, fn ($query) => $query->whereNull('track_id'))
                ->whereHas('devices')
                ->get();

            $notificationService = app(NotificationService::class);
            $title = 'سؤال جديد في مادتك';
            $body = 'تم نشر سؤال جديد في مادتك الدراسية.';
            $notificationData = [
                'type' => 'new_question_published',
                'question_id' => (string) $item->id,
                'question_type' => $type,
                'stage_id' => (string) ($item->stage_id ?? ''),
                'grade_id' => (string) ($item->grade_id ?? ''),
                'track_id' => (string) ($item->track_id ?? ''),
            ];

            foreach ($targetStudents as $student) {
                try {
                    $pushResult = $notificationService->sendPushOnlyToUser($student, $title, $body, $notificationData);
                    if ($pushResult['success'] === true) {
                        $notifiedStudents++;
                    }
                } catch (\Throwable $notificationError) {
                    Log::warning('[TeacherManagementController] Failed to notify student about published question', [
                        'student_id' => $student->id,
                        'question_id' => $item->id,
                        'error' => $notificationError->getMessage(),
                    ]);
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => $nextStatus === 'published'
                ? 'تم نشر السؤال وإرسال الإشعار للطلاب المستهدفين بنجاح'
                : 'تم إلغاء نشر السؤال بنجاح',
            'status' => $item->status,
            'notified_students' => $notifiedStudents,
            'item' => $item,
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

            $videoUrl = $request->input('explanation_video_url', data_get($item->explanation, 'video_url'));
            QuestionExplanation::upsertForQuestion($item, $videoUrl, $user->id);
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
            'item' => array_merge($item->toArray(), [
                'explanation' => $item->getRawOriginal('explanation'),
                'explanation_video_url' => $videoUrl,
            ]),
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
                'unit_number' => ['nullable', 'integer', 'min:1', 'max:50'],
                'question' => ['nullable', 'string'],
                'explanation' => ['nullable', 'string'],
                'options' => ['required', 'array', 'min:2'],
                'options.*' => ['nullable', 'string'],
                'correct_index' => ['required', 'integer', 'min:0', 'max:3'],
                'badge_text' => ['nullable', 'string', 'max:120'],
                'file_url' => ['nullable', 'string', 'max:2048'],
                'explanation_video_url' => ['nullable', 'string', 'max:2048'],
                'difficulty' => ['sometimes', 'string', 'in:easy,medium,hard'],
                'status' => ['nullable', 'in:draft,published'],
            ],
            'true_false' => [
                'title' => ['required', 'string', 'max:180'],
                'subject' => ['required', 'string', 'max:120'],
                'school_grade' => ['nullable', 'string'],
                'term' => ['nullable', 'in:1,2'],
                'unit_number' => ['nullable', 'integer', 'min:1', 'max:50'],
                'prompt' => ['nullable', 'string'],
                'correct_answer' => ['required', 'boolean'],
                'explanation' => ['nullable', 'string'],
                'badge_text' => ['nullable', 'string', 'max:120'],
                'file_url' => ['nullable', 'string', 'max:2048'],
                'explanation_video_url' => ['nullable', 'string', 'max:2048'],
                'difficulty' => ['sometimes', 'string', 'in:easy,medium,hard'],
                'status' => ['nullable', 'in:draft,published'],
            ],
            'daily_challenge' => [
                'title' => ['required', 'string', 'max:180'],
                'subject' => ['required', 'string', 'max:120'],
                'school_grade' => ['nullable', 'string'],
                'term' => ['nullable', 'in:1,2'],
                'unit_number' => ['nullable', 'integer', 'min:1', 'max:50'],
                'prompt' => ['nullable', 'string'],
                'explanation' => ['nullable', 'string'],
                'options' => ['required', 'array', 'min:2'],
                'options.*' => ['nullable', 'string'],
                'correct_answer_index' => ['required', 'integer', 'min:0', 'max:3'],
                'badge_text' => ['nullable', 'string', 'max:120'],
                'reward_text' => ['nullable', 'string', 'max:180'],
                'expires_in_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
                'file_url' => ['nullable', 'string', 'max:2048'],
                'explanation_video_url' => ['nullable', 'string', 'max:2048'],
                'difficulty' => ['sometimes', 'string', 'in:easy,medium,hard'],
                'status' => ['nullable', 'in:draft,published'],
            ],
            'comparison' => [
                'title' => ['required', 'string', 'max:180'],
                'subject' => ['required', 'string', 'max:120'],
                'school_grade' => ['nullable', 'string'],
                'term' => ['nullable', 'in:1,2'],
                'unit_number' => ['nullable', 'integer', 'min:1', 'max:50'],
                'left_label' => ['nullable', 'string', 'max:80'],
                'right_label' => ['nullable', 'string', 'max:80'],
                'left_text' => ['required', 'string'],
                'right_text' => ['required', 'string'],
                'explanation' => ['nullable', 'string'],
                'badge_text' => ['nullable', 'string', 'max:120'],
                'file_url' => ['nullable', 'string', 'max:2048'],
                'explanation_video_url' => ['nullable', 'string', 'max:2048'],
                'difficulty' => ['sometimes', 'string', 'in:easy,medium,hard'],
                'status' => ['nullable', 'in:draft,published'],
            ],
            'find_the_bug' => [
                'title' => ['required', 'string', 'max:180'],
                'subject' => ['required', 'string', 'max:120'],
                'school_grade' => ['nullable', 'string'],
                'term' => ['nullable', 'in:1,2'],
                'unit_number' => ['nullable', 'integer', 'min:1', 'max:50'],
                'prompt' => ['nullable', 'string'],
                'explanation' => ['nullable', 'string'],
                'options' => ['required', 'array', 'min:2'],
                'options.*' => ['nullable', 'string'],
                'correct_answer_index' => ['required', 'integer', 'min:0', 'max:3'],
                'badge_text' => ['nullable', 'string', 'max:120'],
                'file_url' => ['nullable', 'string', 'max:2048'],
                'explanation_video_url' => ['nullable', 'string', 'max:2048'],
                'difficulty' => ['sometimes', 'string', 'in:easy,medium,hard'],
                'status' => ['nullable', 'in:draft,published'],
            ],
            'cloud_capsule' => [
                'title' => ['required', 'string', 'max:180'],
                'subject' => ['required', 'string', 'max:120'],
                'school_grade' => ['nullable', 'string'],
                'term' => ['nullable', 'in:1,2'],
                'unit_number' => ['nullable', 'integer', 'min:1', 'max:50'],
                'intro_text' => ['nullable', 'string'],
                'explanation' => ['nullable', 'string'],
                'reveal_text' => ['required', 'string'],
                'tip_text' => ['nullable', 'string'],
                'mood_text' => ['nullable', 'string'],
                'reveal_label' => ['nullable', 'string', 'max:120'],
                'icon' => ['nullable', 'string', 'max:50'],
                'badge_text' => ['nullable', 'string', 'max:120'],
                'file_url' => ['nullable', 'string', 'max:2048'],
                'explanation_video_url' => ['nullable', 'string', 'max:2048'],
                'difficulty' => ['sometimes', 'string', 'in:easy,medium,hard'],
                'status' => ['nullable', 'in:draft,published'],
            ],
            'live_duel' => [
                'title' => ['required', 'string', 'max:180'],
                'subject' => ['required', 'string', 'max:120'],
                'school_grade' => ['nullable', 'string'],
                'term' => ['nullable', 'in:1,2'],
                'unit_number' => ['nullable', 'integer', 'min:1', 'max:50'],
                'challenge_text' => ['nullable', 'string'],
                'explanation' => ['nullable', 'string'],
                'badge_text' => ['nullable', 'string', 'max:80'],
                'file_url' => ['nullable', 'string', 'max:2048'],
                'explanation_video_url' => ['nullable', 'string', 'max:2048'],
                'question_count' => ['required', 'integer', 'min:1', 'max:20'],
                'seconds_per_question' => ['required', 'integer', 'min:5', 'max:120'],
                'questions' => ['required', 'array', 'min:1'],
                'questions.*.prompt' => ['nullable', 'string'],
                'questions.*.options' => ['required', 'array', 'size:4'],
                'questions.*.options.*' => ['nullable', 'string'],
                'questions.*.correctIndex' => ['required', 'integer', 'min:0', 'max:3'],
                'difficulty' => ['sometimes', 'string', 'in:easy,medium,hard'],
                'status' => ['nullable', 'in:draft,published'],
            ],
        ][$type] ?? null;
    }

    protected function getModelClassForQuestionType(string $type): ?string
    {
        $normalizedType = strtolower(trim((string) $type));

        return [
            'multiple_choice' => MultipleChoiceQuestion::class,
            'multiple-choice' => MultipleChoiceQuestion::class,
            'true_false' => TrueFalseQuestion::class,
            'true-false' => TrueFalseQuestion::class,
            'cloud_capsule' => CloudCapsuleChallenge::class,
            'cloud-capsule' => CloudCapsuleChallenge::class,
            'daily_challenge' => DailyChallenge::class,
            'daily-challenge' => DailyChallenge::class,
            'comparison' => ComparisonChallenge::class,
            'comparison_card' => ComparisonChallenge::class,
            'comparison-card' => ComparisonChallenge::class,
            'find_the_bug' => FindTheBugChallenge::class,
            'find-the-bug' => FindTheBugChallenge::class,
            'live_duel' => LiveDuelChallenge::class,
            'live-duel' => LiveDuelChallenge::class,
        ][$normalizedType] ?? null;
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
        $normalizedRole = strtolower(trim((string) $user->role));

        if (! in_array($normalizedRole, ['teacher', 'main-admin'], true)) {
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

        Log::info('👨‍🏫 [TeacherScope] Removing scope', [
            'scope_id' => $scope->id,
            'user_id' => $user?->id,
            'subject' => $scope->subject,
            'grade' => $scope->school_grade,
        ]);

        $scope->delete();

        if ($user) {
            $remainingScopes = $user->teacherScopes()->count();
            if ($remainingScopes === 0) {
                $previousRole = $user->role;
                $user->forceFill(['role' => 'user'])->save();
                Log::info('👨‍🏫 [TeacherScope] Downgraded user role', [
                    'user_id' => $user->id,
                    'previous_role' => $previousRole,
                    'new_role' => 'user',
                    'reason' => 'no_remaining_scopes',
                ]);
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'تم حذف صلاحية المادة بنجاح',
        ]);
    }

    public function deleteTeacher(Request $request, User $user)
    {
        Log::info('👨‍🏫 [TeacherManagement] Deleting teacher', [
            'user_id' => $user->id,
            'name' => $user->name,
            'current_role' => $user->role,
        ]);

        try {
            // Delete all teacher scopes
            $scopeCount = $user->teacherScopes()->count();
            $user->teacherScopes()->delete();

            // Downgrade user role to 'user'
            $previousRole = $user->role;
            $user->forceFill(['role' => 'user'])->save();

            Log::info('👨‍🏫 [TeacherManagement] Teacher deleted successfully', [
                'user_id' => $user->id,
                'scopes_deleted' => $scopeCount,
                'previous_role' => $previousRole,
                'new_role' => 'user',
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'تم حذف المدرس وصلاحياته بنجاح',
                'data' => [
                    'user_id' => $user->id,
                    'scopes_deleted' => $scopeCount,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('👨‍🏫 [TeacherManagement] Failed to delete teacher', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'فشل حذف المدرس. يرجى المحاولة لاحقاً',
            ], 500);
        }
    }
}
