<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\FiltersQuestionListings;
use App\Models\Friendship;
use App\Models\Posts\Post;
use App\Models\Questions\MultipleChoiceQuestion;
use App\Models\Questions\TrueFalseQuestion;
use App\Models\Challenges\{CloudCapsuleChallenge, ComparisonChallenge, DailyChallenge, FindTheBugChallenge, LiveDuelChallenge};
use App\Models\InteractiveVideo;
use App\Models\TeacherScope;
use App\Models\User;
use Illuminate\Http\Request;

class AdminHomeController extends Controller
{
    use FiltersQuestionListings;

    public function index(Request $request)
    {
        $user = $request->user();

        $gradeValue = $this->resolveGradeFilter(
            $request->query('grade_id', $request->query('stage', $request->query('school_grade')))
        );

        $subjectValue = $this->resolveListingSubject($request);

        $activeStudentsQuery = User::query()
            ->where('role', 'user');

        if ($gradeValue !== null) {
            $activeStudentsQuery->whereRaw(
                $this->getGradeNormalizationClause('school_grade', $gradeValue)
            );
        }

        $postsQuery = Post::query();
        if ($gradeValue !== null) {
            $postsQuery = $postsQuery->forGradeFilter($gradeValue);
        }
        
        // Apply subject filter with proper variant matching
        if ($subjectValue !== null) {
            $subjectVariants = $this->buildSubjectVariants($subjectValue);
            if ($subjectVariants !== []) {
                $postsQuery->where(function ($subjectQuery) use ($subjectVariants) {
                    foreach ($subjectVariants as $index => $variant) {
                        $normalizedVariant = $this->normalizeComparableToken($variant);
                        if ($normalizedVariant === '') {
                            continue;
                        }
                        if ($index === 0) {
                            $subjectQuery->whereRaw('LOWER(TRIM(subject)) = ?', [$normalizedVariant]);
                        } else {
                            $subjectQuery->orWhereRaw('LOWER(TRIM(subject)) = ?', [$normalizedVariant]);
                        }
                    }
                });
            }
        }

        $questionQueryBuilders = [
            ['model' => MultipleChoiceQuestion::class, 'relation' => 'user'],
            ['model' => TrueFalseQuestion::class, 'relation' => 'user'],
            ['model' => DailyChallenge::class, 'relation' => 'user'],
            ['model' => CloudCapsuleChallenge::class, 'relation' => 'user'],
            ['model' => ComparisonChallenge::class, 'relation' => 'user'],
            ['model' => FindTheBugChallenge::class, 'relation' => 'user'],
            ['model' => LiveDuelChallenge::class, 'relation' => 'user'],
        ];

        $questionCount = 0;
        $questionCounts = [];
        
        foreach ($questionQueryBuilders as $builderConfig) {
            $model = $builderConfig['model'];
            $query = $model::query();

            if ($gradeValue !== null) {
                $query->whereHas('user', function ($userQuery) use ($gradeValue) {
                    $userQuery->whereRaw(
                        $this->getGradeNormalizationClause('users.school_grade', $gradeValue)
                    );
                });
            }

            // Apply subject filter with proper variant matching
            if ($subjectValue !== null) {
                $subjectVariants = $this->buildSubjectVariants($subjectValue);
                if ($subjectVariants !== []) {
                    $query->where(function ($subjectQuery) use ($subjectVariants) {
                        foreach ($subjectVariants as $index => $variant) {
                            $normalizedVariant = $this->normalizeComparableToken($variant);
                            if ($normalizedVariant === '') {
                                continue;
                            }
                            if ($index === 0) {
                                $subjectQuery->whereRaw('LOWER(TRIM(subject)) = ?', [$normalizedVariant]);
                            } else {
                                $subjectQuery->orWhereRaw('LOWER(TRIM(subject)) = ?', [$normalizedVariant]);
                            }
                        }
                    });
                }
            }

            $count = (int) $query->count();
            $questionCounts[class_basename($model)] = $count;
            $questionCount += $count;
        }

        \Log::info('📚 [AdminHome:index] تفصيل الأسئلة:', [
            'grade_filter' => $gradeValue,
            'subject_filter' => $subjectValue,
            'question_breakdown' => $questionCounts,
            'total_questions' => $questionCount,
        ]);

        $pendingRequestsCount = Friendship::query()->where('status', 'pending')->count();

        // Get teachers count from TeacherScope with filter support
        $teachersCount = $this->getTeachersCount($gradeValue, $subjectValue);

        // Get users count with filter support
        $usersCount = $this->getUsersCount($gradeValue, $subjectValue);

        // Get interactive videos count with filter support
        $interactiveVideosCount = $this->getInteractiveVideosCount($gradeValue, $subjectValue);

        $stats = [
            'active_students_count' => (int) $activeStudentsQuery->count(),
            'posts_count' => (int) $postsQuery->count(),
            'questions_count' => $questionCount,
            'total_content_count' => (int) $postsQuery->count() + $questionCount,
            'pending_requests_count' => (int) $pendingRequestsCount,
            'requests_count' => (int) $pendingRequestsCount,
            'users_count' => (int) $usersCount,
            'interactive_videos_count' => (int) $interactiveVideosCount,
            'teachers_count' => (int) $teachersCount,
            'admins_count' => (int) User::query()->whereIn('role', ['main_admin', 'main-admin', 'admin'])->count(),
        ];

        return response()->json([
            'message' => 'تم جلب إحصائيات لوحة الإدارة بنجاح',
            'filter' => [
                'grade_id' => $gradeValue,
                'stage' => $request->query('stage', $request->query('grade_id')),
                'label' => $this->formatStageLabel($gradeValue),
            ],
            'stats' => $stats,
        ]);
    }

    public function getPosts(Request $request)
    {
        $user = $request->user();

        $gradeValue = $this->resolveGradeFilter(
            $request->query('grade_id', $request->query('stage', $request->query('school_grade')))
        );

        $subjectValue = $this->resolveListingSubject($request);

        \Log::info('📝 [AdminHome:getPosts] جاري جلب البوستات:', [
            'requested_grade' => $gradeValue,
            'requested_subject' => $subjectValue,
            'request_params' => $request->query(),
        ]);

        $query = Post::query()
            ->with(['user:id,name,role,school_grade,gender'])
            ->orderByDesc('created_at');

        $allPostsCount = $query->count();
        \Log::info('📊 [getPosts] إجمالي البوستات بدون فلترة:', ['total_posts' => $allPostsCount]);

        if ($gradeValue !== null) {
            $query->forGradeFilter($gradeValue);
        }

        // Apply subject filter with proper variant matching
        if ($subjectValue !== null) {
            $subjectVariants = $this->buildSubjectVariants($subjectValue);
            if ($subjectVariants !== []) {
                $query->where(function ($subjectQuery) use ($subjectVariants) {
                    foreach ($subjectVariants as $index => $variant) {
                        $normalizedVariant = $this->normalizeComparableToken($variant);
                        if ($normalizedVariant === '') {
                            continue;
                        }
                        if ($index === 0) {
                            $subjectQuery->whereRaw('LOWER(TRIM(subject)) = ?', [$normalizedVariant]);
                        } else {
                            $subjectQuery->orWhereRaw('LOWER(TRIM(subject)) = ?', [$normalizedVariant]);
                        }
                    }
                });
            }
        }

        $filteredPostsCount = $query->count();
        \Log::info('🔍 [getPosts] البوستات بعد الفلترة:', [
            'grade_value' => $gradeValue,
            'filtered_count' => $filteredPostsCount,
            'all_users_with_grades' => User::all(['id', 'name', 'school_grade', 'role'])->toArray(),
        ]);

        $allGirlsCount = User::query()
            ->where('role', 'user')
            ->when($gradeValue !== null, function ($girlsQuery) use ($gradeValue) {
                $girlsQuery->whereRaw(
                    $this->getGradeNormalizationClause('school_grade', $gradeValue)
                );
            })
            ->where('gender', 'female')
            ->count();

        $posts = $query->limit((int) $request->query('limit', 20))->get()->map(function (Post $post) {
            \Log::info('📌 [getPosts:Post] تفاصيل بوست:', [
                'post_id' => $post->id,
                'user_id' => $post->user_id,
                'user_name' => $post->user?->name,
                'user_school_grade' => $post->user?->school_grade,
                'user_role' => $post->user?->role,
                'content' => substr($post->content, 0, 50),
            ]);
            return [
                'id' => $post->id,
                'content' => $post->content,
                'subject' => $post->subject,
                'image_url' => $post->image_url,
                'status' => $post->status,
                'created_at' => $post->created_at?->toISOString(),
                'likes' => $post->likes,
                'comments' => $post->comments,
                'shares' => $post->shares,
                'user' => $post->user ? [
                    'id' => $post->user->id,
                    'name' => $post->user->name,
                    'role' => $post->user->role,
                    'gender' => $post->user->gender,
                    'school_grade' => $post->user->school_grade,
                ] : null,
            ];
        });

        \Log::info('✅ [AdminHome:getPosts] البوستات المسترجعة:', [
            'count' => $posts->count(),
            'grade_filter' => $gradeValue,
            'posts_data' => $posts->take(2)->toArray(),
        ]);

        return response()->json([
            'message' => 'تم جلب المنشورات الخاصة بلوحة الإدارة بنجاح',
            'filter' => [
                'grade_id' => $gradeValue,
                'stage' => $request->query('stage', $request->query('grade_id')),
                'label' => $this->formatStageLabel($gradeValue),
            ],
            'girls_count' => (int) $allGirlsCount,
            'total' => $posts->count(),
            'posts' => $posts,
        ]);
    }

    protected function resolveGradeFilter(mixed $value): ?string
    {
        if ($value === null || $value === '' || strtolower((string) $value) === 'all' || trim((string) $value) === 'كل المراحل') {
            return null;
        }

        $rawValue = trim((string) $value);

        if (is_numeric($rawValue)) {
            return (string) $rawValue;
        }

        $normalized = User::normalizeSchoolGradeValue($rawValue);

        return $normalized !== null && $normalized !== '' ? (string) $normalized : null;
    }

    protected function formatStageLabel(?string $gradeValue): string
    {
        if ($gradeValue === null || $gradeValue === '') {
            return 'كل المراحل';
        }

        $map = [
            '1' => 'الأول الإعدادي',
            '2' => 'الثاني الإعدادي',
            '3' => 'الثالث الإعدادي',
            '4' => 'الأول الثانوي',
            '5' => 'الثاني الثانوي',
            '6' => 'الثالث الثانوي',
            '7' => 'السابع',
            '8' => 'الثامن',
            '9' => 'التاسع',
            '10' => 'العاشر',
            '11' => 'الحادي عشر',
            '12' => 'الثاني عشر',
        ];

        return $map[$gradeValue] ?? "المرحلة {$gradeValue}";
    }

    protected function getGradeNormalizationClause(string $column, string $normalizedGrade): string
    {
        return "
            CASE 
                WHEN $column IS NULL THEN ''
                WHEN LOWER($column) IN ('اول', 'اولى', 'اولي', 'اولى اعدادي', 'اول اعدادي', '1') THEN '1'
                WHEN LOWER($column) IN ('ثاني', 'ثانية', 'ثانى', 'ثانيه', 'ثاني اعدادي', 'ثانى اعدادي', '2') THEN '2'
                WHEN LOWER($column) IN ('ثالث', 'ثالثة', 'ثالثه', 'ثالث اعدادي', 'ثالثة اعدادي', '3') THEN '3'
                WHEN LOWER($column) IN ('رابع', 'رابعة', 'رابع ثانوي', 'اول ثانوي', '4') THEN '4'
                WHEN LOWER($column) IN ('خامس', 'خامسة', 'ثاني ثانوي', '5') THEN '5'
                WHEN LOWER($column) IN ('سادس', 'سادسة', 'ثالث ثانوي', '6') THEN '6'
                WHEN LOWER($column) IN ('سابع', 'سابعة', '7') THEN '7'
                WHEN LOWER($column) IN ('ثامن', 'ثامنة', '8') THEN '8'
                WHEN LOWER($column) IN ('تاسع', 'تاسعة', '9') THEN '9'
                WHEN LOWER($column) IN ('عاشر', 'عاشرة', '10') THEN '10'
                WHEN LOWER($column) IN ('حادي عشر', 'حادية عشرة', '11') THEN '11'
                WHEN LOWER($column) IN ('ثاني عشر', 'ثانية عشرة', '12') THEN '12'
                ELSE LOWER($column)
            END = '$normalizedGrade'
        ";
    }

    protected function getTeachersCount(?string $gradeValue = null, ?string $subjectValue = null): int
    {
        $query = TeacherScope::query()->distinct('user_id');

        // Apply grade filter
        if ($gradeValue !== null) {
            $query->where('school_grade', $gradeValue);
        }

        // Apply subject filter with proper variant matching
        if ($subjectValue !== null) {
            $subjectVariants = $this->buildSubjectVariants($subjectValue);
            if ($subjectVariants !== []) {
                $query->where(function ($subjectQuery) use ($subjectVariants) {
                    foreach ($subjectVariants as $index => $variant) {
                        $normalizedVariant = $this->normalizeComparableToken($variant);
                        if ($normalizedVariant === '') {
                            continue;
                        }
                        if ($index === 0) {
                            $subjectQuery->whereRaw('LOWER(TRIM(subject)) = ?', [$normalizedVariant]);
                        } else {
                            $subjectQuery->orWhereRaw('LOWER(TRIM(subject)) = ?', [$normalizedVariant]);
                        }
                    }
                });
            }
        }

        \Log::info('👨‍🏫 [AdminHome:getTeachersCount] عدد المعلمين:', [
            'grade_filter' => $gradeValue,
            'subject_filter' => $subjectValue,
            'teachers_count' => $query->count(),
        ]);

        return (int) $query->count();
    }

    protected function getUsersCount(?string $gradeValue = null, ?string $subjectValue = null): int
    {
        $query = User::query()->where('role', 'user');

        // Apply grade filter
        if ($gradeValue !== null) {
            $query->whereRaw(
                $this->getGradeNormalizationClause('school_grade', $gradeValue)
            );
        }

        // Apply subject filter if provided
        if ($subjectValue !== null) {
            // Users might not have subject directly, but we can filter based on TeacherScope
            // or by checking their related content. For now, we'll just count users by grade.
            // If you need to filter by subject, you'd need to join with TeacherScope or similar.
        }

        \Log::info('👥 [AdminHome:getUsersCount] عدد المستخدمين:', [
            'grade_filter' => $gradeValue,
            'subject_filter' => $subjectValue,
            'users_count' => $query->count(),
        ]);

        return (int) $query->count();
    }

    protected function getInteractiveVideosCount(?string $gradeValue = null, ?string $subjectValue = null): int
    {
        $query = InteractiveVideo::query();

        // Apply grade filter
        if ($gradeValue !== null) {
            $query->whereHas('user', function ($userQuery) use ($gradeValue) {
                $userQuery->whereRaw(
                    $this->getGradeNormalizationClause('users.school_grade', $gradeValue)
                );
            });
        }

        // Apply subject filter with proper variant matching
        if ($subjectValue !== null) {
            $subjectVariants = $this->buildSubjectVariants($subjectValue);
            if ($subjectVariants !== []) {
                $query->where(function ($subjectQuery) use ($subjectVariants) {
                    foreach ($subjectVariants as $index => $variant) {
                        $normalizedVariant = $this->normalizeComparableToken($variant);
                        if ($normalizedVariant === '') {
                            continue;
                        }
                        if ($index === 0) {
                            $subjectQuery->whereRaw('LOWER(TRIM(subject)) = ?', [$normalizedVariant]);
                        } else {
                            $subjectQuery->orWhereRaw('LOWER(TRIM(subject)) = ?', [$normalizedVariant]);
                        }
                    }
                });
            }
        }

        \Log::info('🎥 [AdminHome:getInteractiveVideosCount] عدد الفيديوهات التفاعلية:', [
            'grade_filter' => $gradeValue,
            'subject_filter' => $subjectValue,
            'interactive_videos_count' => $query->count(),
        ]);

        return (int) $query->count();
    }
}
