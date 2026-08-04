<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Log, Schema, Validator};

use App\Http\Controllers\Controller;
use App\Models\{Friendship, TeacherScope, User};

class UserSelectionController extends Controller
{
    /**
     * Get all teachers assigned to reply to questions.
     */
    public function getTeachers()
    {
        $teacherFields = $this->getSelectableUserFields();

        $teachers = User::query()
            ->where('role', 'reply_questions_admin')
            ->whereNotNull('name')
            ->select($teacherFields)
            ->orderBy('name')
            ->get()
            ->map(function ($user) {
                return $this->formatUserForSelection($user);
            });

        return response()->json([
            'success' => true,
            'data' => $teachers,
        ]);
    }

    /**
     * Get teachers who can answer for a specific subject and grade.
     */
    public function getAvailableTeachersForPost(Request $request)
    {
        $requestUser = $request->user() ?? auth('sanctum')->user() ?? auth()->user();

        Log::info('[TeacherScopesDebug] API hit for available-for-post');
        Log::info('[TeacherScopesDebug] Auth user id=' . ($requestUser?->id ?? auth()->id()));
        Log::info('[TeacherScopesDebug] Auth user role=' . ($requestUser?->role ?? 'unknown'));
        Log::info('[TeacherScopesDebug] Auth user school_grade=' . ($requestUser?->school_grade ?? 'NULL'));
        Log::info('[TeacherScopesDebug] Request raw subject=' . $request->query('subject', $request->input('subject', '')));
        Log::info('[TeacherScopesDebug] Request raw post_id=' . $request->query('post_id', $request->input('post_id', '')));

        $validator = Validator::make($request->all(), [
            'subject' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $student = $requestUser;

        if (! $student) {
            Log::error('❌ [AUTH ERROR]: User is not authenticated!');
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $studentGrade = trim((string) ($student->school_grade ?? $student->grade ?? $student->grade_level ?? ''));
        $postSubject = trim((string) $request->query('subject', $request->input('subject', '')));
        $numericGrade = $this->normalizeSchoolGradeToNumber($studentGrade);
        $numericGradeForQuery = $numericGrade !== null ? (string) $numericGrade : null;
        $normalizedSubject = trim(strtolower($postSubject));

        Log::info('[TeacherScopesDebug] Parsed student grade=' . $studentGrade);
        Log::info('[TeacherScopesDebug] Parsed post subject=' . $postSubject);
        Log::info('[TeacherScopesDebug] Normalized subject for comparison=' . $normalizedSubject);
        Log::info('[TeacherScopesDebug] Mapped numeric grade=' . ($numericGradeForQuery ?? 'NULL'));

        if ($studentGrade === '' || $postSubject === '' || $numericGradeForQuery === null) {
            Log::warning('⚠️ [EMPTY FILTERS]: Student grade or post subject is empty.');
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'No matching teachers found',
            ]);
        }

        $teacherScopesQuery = TeacherScope::query()
            ->where('school_grade', $numericGradeForQuery)
            ->where(function ($query) use ($normalizedSubject, $postSubject) {
                $query->whereRaw('LOWER(TRIM(subject)) = ?', [$normalizedSubject])
                    ->orWhereRaw('LOWER(TRIM(subject)) = ?', [trim(strtolower(str_replace([' ', '_', '-', 'ـ'], '', $postSubject)))])
                    ->orWhereRaw('LOWER(TRIM(subject)) LIKE ?', [$normalizedSubject . '%'])
                    ->orWhereRaw('LOWER(TRIM(subject)) LIKE ?', ['%' . $normalizedSubject]);
            })
            ->with(['user' => function ($query) {
                $query->select(['id', 'name', 'email', 'school_grade', 'role']);
            }]);

        $bindings = $teacherScopesQuery->getBindings();
        $teacherScopesQuerySql = $teacherScopesQuery->toSql();

        if (! empty($bindings)) {
            foreach ($bindings as $binding) {
                $value = is_string($binding) ? $binding : (is_bool($binding) ? ($binding ? '1' : '0') : (string) $binding);
                $teacherScopesQuerySql = preg_replace('/\?/', $value, $teacherScopesQuerySql, 1);
            }
        }

        Log::info('[TeacherScopesDebug] teacher_scopes query=' . $teacherScopesQuerySql);
        Log::info('[TeacherScopesDebug] teacher_scopes bindings=' . json_encode($teacherScopesQuery->getBindings(), JSON_UNESCAPED_UNICODE));

        $allTeacherScopes = TeacherScope::query()
            ->select(['id', 'user_id', 'school_grade', 'subject'])
            ->orderBy('id')
            ->get();

        Log::info('[TeacherScopesDebug] all teacher_scopes rows=' . json_encode($allTeacherScopes->map(function ($scope) {
            return [
                'id' => $scope->id,
                'user_id' => $scope->user_id,
                'school_grade' => $scope->school_grade,
                'subject' => $scope->subject,
            ];
        })->toArray(), JSON_UNESCAPED_UNICODE));

        $teacherScopes = $teacherScopesQuery->get();

        $teachers = $teacherScopes
            ->map(function (TeacherScope $scope) {
                if (! $scope->user) {
                    return null;
                }

                return [
                    'id' => $scope->user->id,
                    'name' => $scope->user->name,
                    'email' => $scope->user->email,
                    'avatar' => $scope->user->avatar ?? null,
                    'school_grade' => $scope->school_grade,
                    'subject' => $scope->subject,
                    'role' => $scope->user->role,
                ];
            })
            ->filter()
            ->unique('id')
            ->values();

        Log::info('[TeacherScopesDebug] Final teacher count=' . $teachers->count());
        Log::info('[TeacherScopesDebug] Final teacher payload=' . json_encode($teachers->toArray(), JSON_UNESCAPED_UNICODE));

        $debugInfo = [
            'raw_post_id' => $request->query('post_id', $request->input('post_id', null)),
            'post_subject' => $postSubject,
            'auth_user_id' => $requestUser?->id ?? auth()->id(),
            'auth_user_role' => $requestUser?->role ?? 'unknown',
            'auth_school_grade' => $requestUser?->school_grade ?? null,
            'calculated_numeric_grade' => $numericGradeForQuery,
            'all_teacher_scopes_in_db' => $allTeacherScopes->map(function ($scope) {
                return [
                    'id' => $scope->id,
                    'user_id' => $scope->user_id,
                    'school_grade' => $scope->school_grade,
                    'subject' => $scope->subject,
                ];
            })->values()->all(),
            'filtered_teachers_count' => $teachers->count(),
            'matched_teacher_ids' => $teachers->pluck('id')->values()->all(),
        ];

        return response()->json([
            'success' => true,
            'data' => $teachers,
            'debug_info' => $debugInfo,
        ]);
    }

    /**
     * Get teachers filtered by teacher scope for the provided grade and subject.
     */
    public function getScopeFilteredTeachers(Request $request)
    {
        $request->validate([
            'school_grade' => ['nullable', 'string'],
            'subject' => ['required', 'string'],
        ]);

        $grade = $request->input('school_grade');
        $subject = trim((string) $request->input('subject'));

        if ($grade === null || trim((string) $grade) === '' || $subject === '') {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $numericGrade = TeacherScope::normalizeGradeValue($grade);

        if ($numericGrade === null) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $teachers = User::query()
            ->where('role', 'teacher')
            ->whereHas('teacherScopes', function ($query) use ($numericGrade, $subject) {
                $query->forGradeAndSubject($numericGrade, $subject, true);
            })
            ->select(['id', 'name', 'school_grade', 'role'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $teachers,
        ]);
    }

    /**
     * Get only accepted friends (classmates) for the current user.
     */
    public function getClassmates(Request $request)
    {
        $currentUser = $request->user();
        $currentUserId = $currentUser?->id ?? auth('sanctum')->id() ?? auth()->id();

        if (! $currentUserId) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $friendships = Friendship::query()
            ->where('status', 'accepted')
            ->where(function ($query) use ($currentUserId) {
                $query->where('sender_id', $currentUserId)
                    ->orWhere('receiver_id', $currentUserId);
            })
            ->get();

        $friendIds = $friendships
            ->map(function (Friendship $friendship) use ($currentUserId) {
                return $friendship->sender_id == $currentUserId
                    ? $friendship->receiver_id
                    : $friendship->sender_id;
            })
            ->filter(fn ($id) => (int) $id !== (int) $currentUserId)
            ->unique()
            ->values()
            ->toArray();

        $friendshipMeta = $friendships->mapWithKeys(function (Friendship $friendship) use ($currentUserId) {
            $friendId = $friendship->sender_id == $currentUserId
                ? $friendship->receiver_id
                : $friendship->sender_id;

            return [$friendId => [
                'chat_id' => $friendship->chat_id ?? null,
                'friendship_status' => 'accepted',
                'status' => $friendship->status,
            ]];
        });

        $classmateFields = $this->getSelectableUserFields();

        $classmates = User::query()
            ->whereIn('id', $friendIds)
            ->where('role', 'user')
            ->whereNotNull('name')
            ->select($classmateFields)
            ->orderBy('name')
            ->get()
            ->map(function ($user) use ($friendshipMeta, $currentUserId) {
                $meta = $friendshipMeta[$user->id] ?? [];
                $chatId = $meta['chat_id'] ?? null;

                if (empty($chatId)) {
                    $chatId = DB::table('chat_participants as me')
                        ->join('chat_participants as other', 'me.chat_id', '=', 'other.chat_id')
                        ->where('me.user_id', $currentUserId)
                        ->where('other.user_id', $user->id)
                        ->value('me.chat_id');
                }

                if (empty($chatId)) {
                    $chat = (new \App\Http\Controllers\ChatController())->ensurePrivateChatForFriendshipPair($currentUserId, $user->id);
                    $chatId = $chat->id;
                }

                $chatId = (int) $chatId;

                $base = $this->formatUserForSelection($user);

                return array_merge($base, [
                    'chat_id' => $chatId,
                    'chatId' => $chatId,
                    'friendship_status' => $meta['friendship_status'] ?? 'accepted',
                    'status' => $meta['status'] ?? 'accepted',
                ]);
            });

        return response()->json([
            'success' => true,
            'data' => $classmates,
        ]);
    }

    private function normalizeSchoolGradeToNumber(?string $grade): ?string
    {
        $raw = trim((string) $grade);

        if ($raw === '') {
            return null;
        }

        $normalized = strtolower(str_replace([' ', 'ـ', '-'], '', $raw));

        $map = [
            'اولي' => '1',
            'أولي' => '1',
            'اولى' => '1',
            'تانيه' => '2',
            'ثانية' => '2',
            'ثاني' => '2',
            'تالته' => '3',
            'ثالثة' => '3',
            'ثالث' => '3',
            '1' => '1',
            '2' => '2',
            '3' => '3',
        ];

        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        if (preg_match('/\d/', $raw)) {
            return (string) preg_replace('/\D+/', '', $raw);
        }

        return $raw;
    }

    private function getSelectableUserFields(): array
    {
        $fields = ['id', 'name', 'role', 'gender'];

        if (Schema::hasColumn('users', 'avatar')) {
            $fields[] = 'avatar';
        }

        if (Schema::hasColumn('users', 'school_grade')) {
            $fields[] = 'school_grade';
        }

        if (Schema::hasColumn('users', 'location')) {
            $fields[] = 'location';
        }

        return $fields;
    }

    private function formatUserForSelection(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'label' => $user->name,
            'role' => $user->role,
            'gender' => $user->gender ?? null,
            'avatar' => $user->avatar ?? null,
            'school_grade' => $user->school_grade ?? null,
            'grade' => $user->school_grade ?? $user->grade ?? $user->grade_level ?? $user->academic_year ?? $user->stage ?? null,
            'location' => $user->location ?? null,
            'statusText' => 'متاح الآن',
            'statusColor' => '#22c55e',
        ];
    }
}
