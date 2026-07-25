<?php

namespace App\Http\Controllers\Api\Challenges;

use App\Http\Controllers\Controller;

class CheatSheetController extends Controller
{
    public function show(string $id)
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'id' => (int) $id,
                'title' => 'بطاقة Cheat Sheet',
                'subject' => 'عام',
                'prompt' => 'محتوى البطاقة',
                'question' => 'محتوى البطاقة',
                'quizType' => 'cheat_sheet_flip',
                'questionType' => 'cheat_sheet_flip',
                'type' => 'cheat_sheet_flip',
            ],
        ]);
    }
}
