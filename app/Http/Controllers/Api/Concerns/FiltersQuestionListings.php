<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait FiltersQuestionListings
{
    protected function resolveListingSubject(Request $request): ?string
    {
        $subject = trim((string) $request->query('subject', $request->input('subject', '')));

        return $subject !== '' ? $subject : null;
    }

    protected function resolveListingSchoolGrade(Request $request): ?string
    {
        $grade = trim((string) $request->query('school_grade', $request->input('school_grade', '')));

        if ($grade !== '') {
            return $grade;
        }

        $user = $request->user() ?? auth('sanctum')->user() ?? auth()->user();
        $fallbackGrade = trim((string) ($user?->school_grade ?? $user?->grade ?? ''));

        return $fallbackGrade !== '' ? $fallbackGrade : null;
    }

    protected function resolveListingUnitNumber(Request $request): ?int
    {
        $raw = $request->query('unit_number', $request->input('unit_number', 'all'));

        if ($raw === null || $raw === '' || strtolower(trim((string) $raw)) === 'all') {
            return null;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }

    protected function applyQuestionListingFilters(Builder $query, Request $request, string $subjectColumn = 'subject', string $gradeColumn = 'school_grade'): Builder
    {
        $query->where(function (Builder $statusQuery): void {
            $statusQuery->where('status', 'published')->orWhereNull('status');
        });

        $subjectVariants = $this->buildSubjectVariants($this->resolveListingSubject($request));
        $gradeVariants = $this->buildSchoolGradeVariants($this->resolveListingSchoolGrade($request));
        $unitNumber = $this->resolveListingUnitNumber($request);

        if ($subjectVariants !== []) {
            $query->where(function (Builder $subjectQuery) use ($subjectVariants, $subjectColumn): void {
                foreach ($subjectVariants as $index => $variant) {
                    $normalizedVariant = $this->normalizeComparableToken($variant);

                    if ($normalizedVariant === '') {
                        continue;
                    }

                    if ($index === 0) {
                        $subjectQuery->whereRaw('LOWER(TRIM(' . $subjectColumn . ')) = ?', [$normalizedVariant]);
                    } else {
                        $subjectQuery->orWhereRaw('LOWER(TRIM(' . $subjectColumn . ')) = ?', [$normalizedVariant]);
                    }
                }
            });
        }

        if ($gradeVariants !== []) {
            $query->where(function (Builder $gradeQuery) use ($gradeVariants, $gradeColumn): void {
                foreach ($gradeVariants as $index => $variant) {
                    $normalizedVariant = $this->normalizeComparableToken($variant);

                    if ($normalizedVariant === '') {
                        continue;
                    }

                    if ($index === 0) {
                        $gradeQuery->whereRaw('LOWER(TRIM(' . $gradeColumn . ')) = ?', [$normalizedVariant]);
                    } else {
                        $gradeQuery->orWhereRaw('LOWER(TRIM(' . $gradeColumn . ')) = ?', [$normalizedVariant]);
                    }
                }
            });
        }

        if ($unitNumber !== null) {
            $query->where('unit_number', $unitNumber);
        }

        return $query;
    }

    protected function buildSubjectVariants(?string $subject): array
    {
        $raw = trim((string) $subject);

        if ($raw === '') {
            return [];
        }

        $normalized = $this->normalizeComparableToken($raw);

        $map = [
            'math' => ['math', 'mathematics', 'رياضيات', 'الرياضيات'],
            'science' => ['science', 'علوم', 'العلوم'],
            'arabic' => ['arabic', 'language_arabic', 'لغة عربية', 'العربية', 'اللغة العربية'],
            'english' => ['english', 'language_english', 'لغة إنجليزية', 'الانجليزية', 'الإنجليزية'],
            'studies' => ['studies', 'social_studies', 'دراسات', 'الدراسات'],
        ];

        $variants = [$raw, $normalized];

        // First try direct key lookup
        if (isset($map[$normalized])) {
            $variants = array_merge($variants, $map[$normalized]);
        } else {
            // Reverse lookup - search in all values if normalized not found as key
            foreach ($map as $keyValues) {
                $normalizedKeyValues = array_map(
                    fn($v) => $this->normalizeComparableToken($v),
                    $keyValues
                );
                if (in_array($normalized, $normalizedKeyValues, true)) {
                    $variants = array_merge($variants, $keyValues);
                    break;
                }
            }
        }

        return array_values(array_unique(array_filter($variants, static fn ($value) => trim((string) $value) !== '')));
    }

    protected function buildSchoolGradeVariants(?string $grade): array
    {
        $raw = trim((string) $grade);

        if ($raw === '') {
            return [];
        }

        $normalized = User::normalizeSchoolGradeValue($raw);
        $variants = [$raw];

        if ($normalized !== null && $normalized !== '') {
            $variants[] = (string) $normalized;

            if (preg_match('/^\d+$/', (string) $normalized)) {
                $variants[] = (string) (int) $normalized;
            }
        }

        $gradeMap = [
            '1' => ['1', 'اول', 'أول', 'اولى', 'أولى', 'الاول', 'الاولى', 'الأول', 'الأولى'],
            '2' => ['2', 'ثاني', 'ثانية', 'ثانيه', 'تاني', 'تانية', 'التاني', 'التانية', 'الثاني', 'الثانية'],
            '3' => ['3', 'ثالث', 'ثالثة', 'ثالثه', 'تالت', 'تالتة', 'التالت', 'التالتة', 'الثالث', 'الثالثة'],
            '4' => ['4', 'رابع', 'رابعة', 'رابعه'],
            '5' => ['5', 'خامس', 'خامسة'],
            '6' => ['6', 'سادس', 'سادسة'],
            '7' => ['7', 'سابع', 'سابعة'],
            '8' => ['8', 'ثامن', 'ثامنة'],
            '9' => ['9', 'تاسع', 'تاسعة'],
            '10' => ['10', 'عاشر', 'عاشرة'],
            '11' => ['11', 'حادي عشر', 'الحادية عشرة'],
            '12' => ['12', 'ثاني عشر', 'الثانية عشرة'],
        ];

        if (isset($gradeMap[(string) $normalized])) {
            $variants = array_merge($variants, $gradeMap[(string) $normalized]);
        }

        return array_values(array_unique(array_filter($variants, static fn ($value) => trim((string) $value) !== '')));
    }

    protected function normalizeComparableToken(mixed $value): string
    {
        $token = trim((string) $value);

        if ($token === '') {
            return '';
        }

        $token = str_replace(['ـ', '-', '_'], '', $token);
        $token = preg_replace('/\s+/u', '', $token) ?? $token;
        $token = str_replace(['أ', 'إ', 'آ'], 'ا', $token);

        return function_exists('mb_strtolower') ? mb_strtolower($token) : strtolower($token);
    }
}