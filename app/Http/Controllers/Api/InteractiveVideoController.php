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

class InteractiveVideoController extends Controller
{
    public function __construct(protected NotificationService $notificationService)
    {
    }

    public function index(Request $request)
    {
        $videos = InteractiveVideo::with(['user', 'videoQuestions'])
            ->latest('created_at')
            ->get();

        return response()->json($videos);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'youtube_url' => ['required', 'string', 'url', 'max:2048'],
            'subject' => ['nullable', 'string', 'max:120'],
            'number_of_questions' => ['required', 'integer', 'min:0'],
            'questions' => ['required', 'array', 'min:1'],
            'questions.*.question_text' => ['required', 'string'],
            'questions.*.choice_1' => ['required', 'string'],
            'questions.*.choice_2' => ['required', 'string'],
            'questions.*.choice_3' => ['required', 'string'],
            'questions.*.choice_4' => ['required', 'string'],
            'questions.*.correct_choice' => ['required', 'integer', 'in:1,2,3,4'],
            'questions.*.stop_minute' => ['required', 'integer', 'min:0'],
            'questions.*.stop_second' => ['required', 'integer', 'min:0', 'max:59'],
        ]);

        DB::beginTransaction();

        try {
            $video = InteractiveVideo::create([
                'user_id' => auth()->id(),
                'title' => $validated['title'],
                'youtube_url' => $validated['youtube_url'],
                'subject' => $validated['subject'] ?? null,
                'number_of_questions' => $validated['number_of_questions'],
            ]);

            foreach ($validated['questions'] as $questionData) {
                $video->videoQuestions()->create([
                    'question_text' => $questionData['question_text'],
                    'choice_1' => $questionData['choice_1'],
                    'choice_2' => $questionData['choice_2'],
                    'choice_3' => $questionData['choice_3'],
                    'choice_4' => $questionData['choice_4'],
                    'correct_choice' => $questionData['correct_choice'],
                    'stop_minute' => $questionData['stop_minute'],
                    'stop_second' => $questionData['stop_second'],
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
}
