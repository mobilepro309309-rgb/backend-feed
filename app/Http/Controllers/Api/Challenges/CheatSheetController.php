<?php

namespace App\Http\Controllers\Api\Challenges;

use App\Http\Controllers\Controller;
use App\Services\QuizAccessService;

class CheatSheetController extends Controller
{
    public function __construct(protected QuizAccessService $quizAccessService)
    {
    }

    public function show(string $id)
    {
        $user = auth()->user();
        $quizData = [
            'id' => (int) $id,
            'title' => 'بطاقة Cheat Sheet',
            'subject' => 'عام',
            'prompt' => 'محتوى البطاقة',
            'question' => 'محتوى البطاقة',
            'quizType' => 'cheat_sheet',
            'questionType' => 'cheat_sheet',
            'type' => 'cheat_sheet',
        ];

        // Append access_rules if user is authenticated
        if ($user) {
            $quizData['access_rules'] = $this->quizAccessService->buildAccessRulesObject('cheat_sheet', $user);
        }

        return response()->json([
            'status' => 'success',
            'data' => $quizData,
        ]);
    }
}
