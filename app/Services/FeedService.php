<?php

namespace App\Services;

use App\Models\Feed;
use App\Models\Posts\Post;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FeedService
{
    public function getPaginatedFeed(int $perPage = 10, ?int $unitNumber = null, ?string $subject = null): LengthAwarePaginator
    {
        $user = auth('sanctum')->user() ?? auth()->user();

        if (!$user) {
            Log::warning('FEED USER CHECK', [
                'user_id' => null,
                'grade' => null,
                'message' => 'Unauthenticated feed request blocked.',
            ]);

            throw new AuthenticationException('Unauthenticated.');
        }

        $userGradeRaw = $user->school_grade ?? $user->grade ?? null;
        $normalizedUserGrade = preg_replace('/[^0-9]/', '', (string) $userGradeRaw);

        if ($normalizedUserGrade === '') {
            $gradeMap = [
                'اول' => '1',
                'اولى' => '1',
                'ثاني' => '2',
                'ثانية' => '2',
                'ثالث' => '3',
                'ثالثة' => '3',
                'رابع' => '4',
                'رابعة' => '4',
                'خامس' => '5',
                'خامسة' => '5',
                'سادس' => '6',
                'سادسة' => '6',
                'سابع' => '7',
                'سابعة' => '7',
                'ثامن' => '8',
                'ثامنة' => '8',
                'تاسع' => '9',
                'تاسعة' => '9',
                'عاشر' => '10',
                'عاشرة' => '10',
                'حادي عشر' => '11',
                'الحادية عشرة' => '11',
                'ثاني عشر' => '12',
                'الثانية عشرة' => '12',
            ];

            $normalizedUserGrade = $gradeMap[trim((string) $userGradeRaw)] ?? (string) $userGradeRaw;
        }

        Log::info('FEED USER CHECK', [
            'user_id' => $user->id,
            'grade' => $userGradeRaw,
            'normalized_grade' => $normalizedUserGrade,
        ]);

        $cacheKey = 'feed:v3:' . sha1(implode('|', [
            $user->id,
            $user->stage_id ?? 'none',
            $user->grade_id ?? 'none',
            $user->track_id ?? 'none',
            $user->specialized_subject_id ?? 'none',
            $normalizedUserGrade,
            $perPage,
            $unitNumber ?? 'all',
            strtolower(trim((string) $subject)),
        ]));

        return app(FeedCacheService::class)->remember($cacheKey, 30, function () use ($user, $userGradeRaw, $normalizedUserGrade, $unitNumber, $subject, $perPage): LengthAwarePaginator {
        $query = Feed::query()
            ->where(function ($visibilityQuery) use ($user): void {
                $visibilityQuery->where('status', 'published')
                    ->orWhere(function ($ownerQuery) use ($user): void {
                        $ownerQuery->where('feedable_type', (new Post())->getMorphClass())
                            ->whereHasMorph('feedable', [Post::class], function ($postQuery) use ($user): void {
                                $postQuery->where('user_id', $user->id);
                            });
                    });
            })
            ->with(['feedable' => function (MorphTo $morphTo): void {
                $morphTo->morphWith([
                    Post::class => ['user.stage', 'user.grade', 'user.track', 'user.specializedSubject'],
                ])->morphWithCount([
                    Post::class => ['reactions', 'allComments'],
                ]);
            }]);

        if (!$user->isAdmin()) {
            $hasNewEducationData = collect(['stage_id', 'grade_id', 'track_id', 'specialized_subject_id'])
                ->contains(fn (string $column): bool => $user->{$column} !== null);

            if ($hasNewEducationData) {
                $query->where(function ($visibilityQuery) use ($user): void {
                    $visibilityQuery->whereHasMorph('feedable', [Post::class], function ($feedableQuery) use ($user): void {
                        $feedableQuery->where('user_id', $user->id)
                            ->orWhereHas('user', function ($authorQuery) use ($user): void {
                        foreach (['stage_id', 'grade_id', 'track_id', 'specialized_subject_id'] as $column) {
                            $value = $user->{$column};
                            $value === null ? $authorQuery->whereNull($column) : $authorQuery->where($column, $value);
                        }
                            });
                    });
                });
            } elseif ($normalizedUserGrade) {
                $query->whereHasMorph('feedable', [Post::class], function ($feedableQuery) use ($user, $normalizedUserGrade, $userGradeRaw): void {
                    $feedableQuery->where('user_id', $user->id)
                        ->orWhereHas('user', function ($authorQuery) use ($normalizedUserGrade, $userGradeRaw): void {
                        $authorQuery->where('school_grade', $normalizedUserGrade)
                            ->orWhere('school_grade', $userGradeRaw)
                            ->orWhere('school_grade', (int) $normalizedUserGrade);
                        });
                });
            } else {
                return $query->whereRaw('0 = 1')->paginate($perPage);
            }
        }

        if ($unitNumber !== null && $unitNumber !== 'all' && $unitNumber !== '') {
            $normalizedUnit = (int) $unitNumber;
            if ($normalizedUnit > 0) {
                $query->whereHasMorph('feedable', '*', function ($feedableQuery, $type) use ($normalizedUnit, $subject) {
                    $instance = new $type();
                    $table = $instance->getTable();

                    if ($subject !== null && $subject !== '' && $subject !== 'all') {
                        $normalizedSubject = strtolower(trim((string) $subject));
                        if ($normalizedSubject !== '') {
                            $feedableQuery->whereRaw('LOWER(TRIM(subject)) = ?', [$normalizedSubject]);
                        }
                    }

                    if (Schema::hasColumn($table, 'unit_number')) {
                        $feedableQuery->where('unit_number', $normalizedUnit);
                    }
                });
            }
        }

        $feed = $query
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->paginate($perPage);

        foreach ($feed as $feedItem) {
            $feedable = $feedItem->feedable;

            if (! $feedable) {
                continue;
            }

            if (method_exists($feedable, 'user')) {
                $feedable->loadMissing('user');
            }

            if (method_exists($feedable, 'explanation')) {
                $feedable->loadMissing('explanation');
            }

            if (in_array($feedable::class, [
                \App\Models\Questions\MultipleChoiceQuestion::class,
                \App\Models\Questions\TrueFalseQuestion::class,
            ], true)) {
                $feedable->loadMissing('explanation');
            }
        }

        return $feed;
        });
    }

    protected function normalizeGrade(?string $grade): ?string
    {
        if ($grade === null || trim((string) $grade) === '') {
            return null;
        }

        $value = trim((string) $grade);

        if (preg_match('/\d/', $value)) {
            return (string) preg_replace('/\D+/', '', $value);
        }

        $clean = preg_replace('/^ال(?:ـ)?/u', '', $value);
        $clean = preg_replace('/\s+/u', '', $clean);
        $clean = str_replace(['أ', 'إ', 'آ'], 'ا', $clean);
        $clean = strtolower($clean);

        $map = [
            'اول' => '1',
            'اولى' => '1',
            'ثاني' => '2',
            'ثانية' => '2',
            'ثالث' => '3',
            'ثالثة' => '3',
            'رابع' => '4',
            'رابعة' => '4',
            'خامس' => '5',
            'خامسة' => '5',
            'سادس' => '6',
            'سادسة' => '6',
            'سابع' => '7',
            'سابعة' => '7',
            'ثامن' => '8',
            'ثامنة' => '8',
            'تاسع' => '9',
            'تاسعة' => '9',
            'عاشر' => '10',
            'عاشرة' => '10',
            'حادي عشر' => '11',
            'الحادية عشرة' => '11',
            'ثاني عشر' => '12',
            'الثانية عشرة' => '12',
        ];

        foreach ($map as $label => $numeric) {
            if (str_contains($clean, $label)) {
                return $numeric;
            }
        }

        return null;
    }

}
