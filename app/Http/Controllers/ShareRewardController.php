<?php

namespace App\Http\Controllers;

use App\Models\ShareRewardLog;
use App\Models\WalletTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ShareRewardController extends Controller
{
    /**
     * Claim a daily share reward for WhatsApp or Facebook.
     */
    public function claimShareReward(Request $request)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated',
            ], 401);
        }

        try {
            $validated = $request->validate([
                'platform' => ['required', 'string', 'in:whatsapp,facebook'],
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        }

        $platform = $validated['platform'];
        $rewardMap = [
            'whatsapp' => 5,
            'facebook' => 20,
        ];

        $alreadyClaimedToday = ShareRewardLog::where('user_id', $user->id)
            ->where('platform', $platform)
            ->whereDate('created_at', today())
            ->exists();

        if ($alreadyClaimedToday) {
            return response()->json([
                'message' => 'لقد حصلت على مكافأة هذه المنصة اليوم',
            ], 422);
        }

        $rewardPoints = (int) ($rewardMap[$platform] ?? 0);

        try {
            $result = DB::transaction(function () use ($user, $platform, $rewardPoints) {
                $wallet = $user->wallet()->firstOrCreate(
                    ['user_id' => $user->id],
                    ['balance' => 0.00]
                );

                $wallet->increment('balance', $rewardPoints);
                $wallet->refresh();

                $shareLog = ShareRewardLog::create([
                    'user_id' => $user->id,
                    'platform' => $platform,
                    'points_awarded' => $rewardPoints,
                    'share_day' => now()->toDateString(),
                ]);

                WalletTransaction::create([
                    'wallet_id' => $wallet->id,
                    'type' => 'share_reward',
                    'amount' => $rewardPoints,
                    'status' => 'completed',
                    'reference_id' => "share_reward_{$platform}_{$shareLog->id}",
                ]);

                return [
                    'current_balance' => (float) $wallet->balance,
                    'reward_points' => $rewardPoints,
                ];
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23000') {
                return response()->json([
                    'message' => 'لقد حصلت على مكافأة هذه المنصة اليوم',
                ], 422);
            }

            Log::error('Share reward claim failed', [
                'user_id' => $user->id,
                'platform' => $platform,
                'exception' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'حدث خطأ أثناء إضافة المكافأة',
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'تمت إضافة نقاط المشاركة بنجاح',
            'platform' => $platform,
            'reward_points' => $result['reward_points'],
            'current_balance' => $result['current_balance'],
        ]);
    }
}
