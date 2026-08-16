<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\QuestionTypeSetting;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class QuestionTypeSettingController extends Controller
{
    /**
     * List all question type settings.
     * Returns a structured JSON response with all settings.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        try {
            $settings = QuestionTypeSetting::orderBy('question_type')
                ->where('is_active', true)
                ->get();

            // Explicitly format the response data
            $formattedSettings = $settings->map(function ($setting) {
                return [
                    'id' => (int) $setting->id,
                    'question_type' => (string) $setting->question_type,
                    'reward_points' => (int) $setting->reward_points,
                    'entry_fee' => (int) $setting->entry_fee,
                    'is_active' => (bool) $setting->is_active,
                    'created_at' => $setting->created_at?->toIso8601String(),
                    'updated_at' => $setting->updated_at?->toIso8601String(),
                ];
            })->toArray();

            return response()->json([
                'success' => true,
                'data' => $formattedSettings,
                'count' => count($formattedSettings),
                'message' => 'Question type settings retrieved successfully',
            ], 200);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to retrieve question type settings', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Failed to retrieve settings',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a specific question type setting.
     *
     * @param  Request  $request
     * @param  QuestionTypeSetting  $questionTypeSetting
     * @return JsonResponse
     */
    public function update(Request $request, QuestionTypeSetting $questionTypeSetting): JsonResponse
    {
        try {
            // Validate incoming data
            $validated = $request->validate([
                'reward_points' => 'sometimes|integer|min:0|max:9999',
                'entry_fee' => 'sometimes|integer|min:0|max:9999',
                'is_active' => 'sometimes|boolean',
            ]);

            // Update the setting
            $questionTypeSetting->update($validated);

            return response()->json([
                'success' => true,
                'data' => $questionTypeSetting,
                'message' => "Settings updated for '{$questionTypeSetting->question_type}'",
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update setting',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update by question type name instead of ID.
     * This is an alternative endpoint for convenience.
     *
     * @param  Request  $request
     * @param  string  $questionType
     * @return JsonResponse
     */
    public function updateByType(Request $request, string $questionType): JsonResponse
    {
        try {
            // Find the setting by question type
            $setting = QuestionTypeSetting::where('question_type', $questionType)->firstOrFail();

            // Validate incoming data
            $validated = $request->validate([
                'reward_points' => 'sometimes|integer|min:0|max:9999',
                'entry_fee' => 'sometimes|integer|min:0|max:9999',
                'is_active' => 'sometimes|boolean',
            ]);

            // Update the setting
            $setting->update($validated);

            return response()->json([
                'success' => true,
                'data' => $setting,
                'message' => "Settings updated for '{$setting->question_type}'",
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => "Question type '{$questionType}' not found",
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update setting',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get a specific question type setting.
     *
     * @param  QuestionTypeSetting  $questionTypeSetting
     * @return JsonResponse
     */
    public function show(QuestionTypeSetting $questionTypeSetting): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $questionTypeSetting,
        ]);
    }

    /**
     * Restore default values for all settings.
     * This endpoint resets all settings to their original values and creates
     * any missing records so the reset is always idempotent.
     *
     * @return JsonResponse
     */
    public function resetDefaults(): JsonResponse
    {
        try {
            $defaults = [
                'true_false' => ['reward_points' => 2, 'entry_fee' => 0],
                'multiple_choice' => ['reward_points' => 2, 'entry_fee' => 0],
                'comparison_card' => ['reward_points' => 3, 'entry_fee' => 0],
                'live_duel' => ['reward_points' => 15, 'entry_fee' => 5],
                'find_the_bug' => ['reward_points' => 6, 'entry_fee' => 3],
                'cloud_capsule' => ['reward_points' => 6, 'entry_fee' => 0],
                'cheat_sheet' => ['reward_points' => 6, 'entry_fee' => 3],
                'daily_challenge' => ['reward_points' => 12, 'entry_fee' => 4],
            ];

            $updated = [];
            foreach ($defaults as $type => $values) {
                $setting = QuestionTypeSetting::updateOrCreate(
                    ['question_type' => $type],
                    [
                        'reward_points' => $values['reward_points'],
                        'entry_fee' => $values['entry_fee'],
                        'is_active' => true,
                    ]
                );

                $updated[] = [
                    'id' => (int) $setting->id,
                    'question_type' => $setting->question_type,
                    'reward_points' => (int) $setting->reward_points,
                    'entry_fee' => (int) $setting->entry_fee,
                    'is_active' => (bool) $setting->is_active,
                ];
            }

            return response()->json([
                'success' => true,
                'data' => $updated,
                'count' => count($updated),
                'message' => 'All settings reset to default values',
            ], 200);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to reset question type settings', [
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return response()->json([
                'success' => false,
                'data' => null,
                'message' => 'Failed to reset settings',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk update multiple settings at once.
     *
     * @param  Request  $request
     * @return JsonResponse
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        try {
            // Validate incoming data
            $validated = $request->validate([
                'settings' => 'required|array',
                'settings.*.question_type' => 'required|string',
                'settings.*.reward_points' => 'sometimes|integer|min:0|max:9999',
                'settings.*.entry_fee' => 'sometimes|integer|min:0|max:9999',
                'settings.*.is_active' => 'sometimes|boolean',
            ]);

            $updated = [];
            foreach ($validated['settings'] as $settingData) {
                $setting = QuestionTypeSetting::where('question_type', $settingData['question_type'])->first();
                if ($setting) {
                    $setting->update($settingData);
                    $updated[] = [
                        'id' => (int) $setting->id,
                        'question_type' => $setting->question_type,
                        'reward_points' => (int) $setting->reward_points,
                        'entry_fee' => (int) $setting->entry_fee,
                        'is_active' => (bool) $setting->is_active,
                        'updated_at' => $setting->updated_at?->toIso8601String(),
                    ];
                }
            }

            return response()->json([
                'success' => true,
                'data' => $updated,
                'count' => count($updated),
                'message' => 'Settings updated successfully',
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update settings',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
