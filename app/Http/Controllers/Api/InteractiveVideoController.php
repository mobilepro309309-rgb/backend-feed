<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\InteractiveVideo;
use App\Models\User;
use App\Models\VideoQuestion;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class InteractiveVideoController extends Controller
{
    public function __construct(protected NotificationService $notificationService)
    {
    }

    public function index(Request $request)
    {
        $user = auth('sanctum')->user() ?? auth()->user();
        $resolvedGrade = $this->resolveSchoolGrade(
            $user?->school_grade ?? $user?->grade ?? null
        ) ?? $this->resolveSchoolGrade($request->query('school_grade', $request->input('school_grade')));

        $videosQuery = InteractiveVideo::with([
            'user',
            'videoQuestions' => function ($query) {
                $query->select([
                    'id',
                    'interactive_video_id',
                    'question_text',
                    'choice_1',
                    'choice_2',
                    'choice_3',
                    'choice_4',
                    'correct_choice',
                    'stop_minute',
                    'stop_second',
                    'file_url',
                    'explanation',
                ]);
            },
        ]);

        if ($resolvedGrade !== null && $resolvedGrade !== '') {
            $gradeVariants = $this->getSchoolGradeVariants($resolvedGrade);

            $videosQuery->where(function ($query) use ($gradeVariants) {
                foreach ($gradeVariants as $variant) {
                    $query->orWhere('school_grade', $variant);
                }
            });
        }

        if ($request->filled('subject')) {
            $subject = trim((string) $request->input('subject'));
            if ($subject !== '') {
                $videosQuery->where('subject', $subject);
            }
        }

        if ($request->filled('unit_number')) {
            $unitNumber = (int) $request->input('unit_number');
            if ($unitNumber > 0) {
                $videosQuery->where('unit_number', $unitNumber);
            }
        }

        $videos = $videosQuery
            ->latest('created_at')
            ->get();

        return response()->json($videos);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'youtube_url' => ['required', 'string', 'url', 'max:2048'],
            'subject' => ['nullable', 'string', 'max:120'],
            'school_grade' => ['nullable', 'string', 'max:120'],
            'term' => ['nullable', 'string', 'max:120'],
            'unit_number' => ['nullable', 'integer', 'min:1', 'max:255'],
            'number_of_questions' => ['required', 'integer', 'min:0'],
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.question_text' => ['nullable', 'string'],
            'questions.*.choice_1' => ['required', 'string'],
            'questions.*.choice_2' => ['required', 'string'],
            'questions.*.choice_3' => ['required', 'string'],
            'questions.*.choice_4' => ['required', 'string'],
            'questions.*.correct_choice' => ['required', 'integer', 'in:1,2,3,4'],
            'questions.*.stop_minute' => ['required', 'integer', 'min:0'],
            'questions.*.stop_second' => ['required', 'integer', 'min:0', 'max:59'],
            'questions.*.file_url' => ['nullable', 'string', 'max:2048'],
            'questions.*.explanation' => ['nullable', 'string'],
        ]);

        $validator->after(function ($validator) use ($request) {
            $questions = $request->input('questions', []);
            foreach ($questions as $index => $question) {
                $questionText = trim((string) ($question['question_text'] ?? ''));
                $fileUrl = trim((string) ($question['file_url'] ?? ''));
                if ($questionText === '' && $fileUrl === '') {
                    $validator->errors()->add("questions.$index.question_text", 'يجب توفير نص السؤال أو ملف مرفق لكل سؤال.');
                }
            }
        });

        $validated = $validator->validate();

        DB::beginTransaction();

        try {
            $video = InteractiveVideo::create([
                'user_id' => auth()->id(),
                'title' => $validated['title'],
                'youtube_url' => $validated['youtube_url'],
                'subject' => $validated['subject'] ?? null,
                'school_grade' => $validated['school_grade'] ?? null,
                'term' => $validated['term'] ?? null,
                'unit_number' => $validated['unit_number'] ?? null,
                'number_of_questions' => $validated['number_of_questions'],
            ]);

            foreach ($validated['questions'] as $questionData) {
                $questionText = trim((string) ($questionData['question_text'] ?? ''));

                $video->videoQuestions()->create([
                    'question_text' => $questionText,
                    'choice_1' => $questionData['choice_1'],
                    'choice_2' => $questionData['choice_2'],
                    'choice_3' => $questionData['choice_3'],
                    'choice_4' => $questionData['choice_4'],
                    'correct_choice' => $questionData['correct_choice'],
                    'stop_minute' => $questionData['stop_minute'],
                    'stop_second' => $questionData['stop_second'],
                    'file_url' => $questionData['file_url'] ?? null,
                    'explanation' => $questionData['explanation'] ?? null,
                ]);
            }

            DB::commit();

            $creatorId = auth()->id();
            $recipients = $creatorId
                ? User::where('id', '!=', $creatorId)->get()
                : User::all();

            $notificationData = [
                'type' => 'new_video',
                'target_type' => 'videos',
                'target_id' => $video->id,
                'video_id' => $video->id,
                'video_title' => $validated['title'],
            ];

            foreach ($recipients as $recipient) {
                $this->notificationService->sendNotification(
                    $recipient,
                    'فيديو جديد',
                    'تم نشر فيديو جديد: ' . $validated['title'],
                    $notificationData
                );
            }

            return response()->json([
                'message' => 'Interactive video created successfully.',
                'data' => $video->load('videoQuestions'),
            ], 201);
        } catch (\Throwable $exception) {
            DB::rollBack();
            Log::error('Failed to store interactive video', [
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Unable to create interactive video.',
            ], 500);
        }
    }

    private function resolveSchoolGrade(mixed $grade): ?string
    {
        $raw = trim((string) $grade);

        if ($raw === '') {
            return null;
        }

        if (preg_match('/\d/', $raw)) {
            return (string) preg_replace('/\D+/', '', $raw);
        }

        $normalized = strtolower(str_replace([' ', 'ـ', '-'], '', $raw));
        $normalized = str_replace(['أ', 'إ', 'آ'], 'ا', $normalized);

        $map = [
            'اول' => '1',
            'اولي' => '1',
            'اولى' => '1',
            'الاول' => '1',
            'الاولى' => '1',
            'ثاني' => '2',
            'ثانيه' => '2',
            'ثانية' => '2',
            'التاني' => '2',
            'التانية' => '2',
            'تاني' => '2',
            'تانية' => '2',
            'ثالث' => '3',
            'ثالثه' => '3',
            'ثالثة' => '3',
            'التالت' => '3',
            'التالتة' => '3',
            'تالت' => '3',
            'تالتة' => '3',
            'رابع' => '4',
            'رابعه' => '4',
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
            'حاديعشر' => '11',
            'الحاديةعشرة' => '11',
            'ثانيعشر' => '12',
            'الثانيةعشرة' => '12',
        ];

        return $map[$normalized] ?? $raw;
    }

    private function getSchoolGradeVariants(string $grade): array
    {
        $normalized = $this->resolveSchoolGrade($grade);

        if ($normalized === null || $normalized === '') {
            return [];
        }

        $variants = [
            $grade,
            $normalized,
            (string) (int) $normalized,
        ];

        $gradeForms = [
            '1' => ['1', 'اول', 'أول', 'اولى', 'أولى', 'الاول', 'الاولى'],
            '2' => ['2', 'ثاني', 'ثانيه', 'ثانية', 'تاني', 'تانية', 'التاني', 'التانية'],
            '3' => ['3', 'ثالث', 'ثالثه', 'ثالثة', 'تالت', 'تالتة', 'التالت', 'التالتة'],
            '4' => ['4', 'رابع', 'رابعه', 'رابعة'],
            '5' => ['5', 'خامس', 'خامسة'],
            '6' => ['6', 'سادس', 'سادسة'],
            '7' => ['7', 'سابع', 'سابعة'],
            '8' => ['8', 'ثامن', 'ثامنة'],
            '9' => ['9', 'تاسع', 'تاسعة'],
            '10' => ['10', 'عاشر', 'عاشرة'],
            '11' => ['11', 'حادي عشر', 'الحادية عشرة'],
            '12' => ['12', 'ثاني عشر', 'الثانية عشرة'],
        ];

        if (isset($gradeForms[$normalized])) {
            $variants = array_merge($variants, $gradeForms[$normalized]);
        }

        return array_values(array_unique(array_filter($variants, fn ($value) => $value !== null && trim((string) $value) !== '')));
    }
}
