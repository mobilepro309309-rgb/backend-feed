<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Challenges\CheatSheetController;
use App\Http\Controllers\Api\Challenges\ComparisonChallengeController;
use App\Http\Controllers\Api\Challenges\DailyChallengeController;
use App\Http\Controllers\Api\Challenges\FindTheBugChallengeController;
use App\Http\Controllers\Api\Questions\MultipleChoiceQuestionController;
use App\Http\Controllers\Api\Questions\TrueFalseQuestionController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class QuizBatchController extends Controller
{
    public function batch(Request $request)
    {
        $requests = $request->input('requests', []);
        if (! is_array($requests)) {
            return response()->json(['status' => 'success', 'data' => []]);
        }

        $results = [];

        foreach ($requests as $requestItem) {
            if (! is_array($requestItem)) {
                continue;
            }

            $id = data_get($requestItem, 'id');
            $type = (string) data_get($requestItem, 'type', '');
            $existingPayload = data_get($requestItem, 'payload');

            if ($id === null || $id === '') {
                continue;
            }

            $resolvedId = (string) $id;
            $payload = is_array($existingPayload) ? $existingPayload : null;

            if (! $payload) {
                $payload = $this->resolvePayload($resolvedId, $type);
            }

            $results[] = [
                'id' => $resolvedId,
                'type' => $type,
                'payload' => $payload,
            ];
        }

        return response()->json(['status' => 'success', 'data' => $results]);
    }

    private function resolvePayload(string $id, string $type): array
    {
        $normalizedType = strtolower($type);

        $response = match ($normalizedType) {
            'daily_challenge', 'dailychallenge', 'dailychallengequiz' => (new DailyChallengeController())->show($id),
            'find_the_bug', 'findthebug', 'findthebugquiz' => (new FindTheBugChallengeController())->show($id),
            'multiple_choice', 'multiplechoice', 'multiplechoicequiz', 'choice', 'mcq' => (new MultipleChoiceQuestionController())->show($id),
            'true_false', 'truefalse', 'truefalsequiz' => (new TrueFalseQuestionController())->show($id),
            'comparison_card', 'comparison', 'comparisoncard', 'comparisoncardquiz' => (new ComparisonChallengeController())->show($id),
            'cheat_sheet', 'cheatsheet', 'cheat_sheet_flip', 'cheatsheetflipcardquiz' => (new CheatSheetController())->show($id),
            default => null,
        };

        if (! $response) {
            return [
                'id' => (int) $id,
                'quizType' => $type,
                'questionType' => $type,
                'type' => $type,
            ];
        }

        $payload = $response->getData(true);
        if (is_array($payload) && isset($payload['data']) && is_array($payload['data'])) {
            return $payload['data'];
        }

        return is_array($payload) ? $payload : [];
    }
}
