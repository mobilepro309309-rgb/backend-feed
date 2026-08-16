<?php

namespace App\Services;

use App\Models\Feed;
use App\Models\Posts\Post;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class FeedService
{
    public function getPaginatedFeed(int $perPage = 10): LengthAwarePaginator
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

        $query = Feed::query()
            ->where(function ($q) {
                $q->where('status', 'published')
                    ->orWhereNull('status');
            })
            ->with(['feedable' => function ($query) {
                $query->where('status', 'published')
                    ->orWhereNull('status')
                    ->with(['user']);
            }]);

        if (!$user->isAdmin()) {
            if ($normalizedUserGrade) {
                $query->whereHasMorph('feedable', '*', function ($feedableQuery, $type) use ($normalizedUserGrade, $userGradeRaw) {
                    $instance = new $type();
                    $table = $instance->getTable();
                    $hasGradeColumn = Schema::hasColumn($table, 'school_grade');

                    $feedableQuery->where(function ($subQuery) use ($normalizedUserGrade, $userGradeRaw, $hasGradeColumn) {
                        if ($hasGradeColumn) {
                            $subQuery->where('school_grade', $normalizedUserGrade)
                                ->orWhere('school_grade', $userGradeRaw)
                                ->orWhere('school_grade', (int) $normalizedUserGrade);
                        } else {
                            $subQuery->whereHas('user', function ($uq) use ($normalizedUserGrade, $userGradeRaw) {
                                $uq->where('school_grade', $normalizedUserGrade)
                                    ->orWhere('school_grade', $userGradeRaw);
                            });
                        }
                    });
                });
            } else {
                return $query->whereRaw('0 = 1')->paginate($perPage);
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
