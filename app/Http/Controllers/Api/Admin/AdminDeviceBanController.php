<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BannedDevice;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AdminDeviceBanController extends Controller
{
    public function banDevice(Request $request)
    {
        $admin = $request->user();

        Log::info('🚫 [DeviceBan] Incoming ban request', [
            'payload' => $request->all(),
            'user_id' => $request->user()?->id,
            'has_admin' => (bool) $admin,
        ]);

        try {
            $validated = $request->validate([
                'device_identifier' => ['nullable', 'string', 'max:255'],
                'fcm_token' => ['nullable', 'string', 'max:255'],
                'user_id' => ['nullable', 'integer', 'exists:users,id'],
                'reason' => ['nullable', 'string', 'max:500'],
            ]);

            Log::info('🚫 [DeviceBan] Validation passed', [
                'validated' => $validated,
            ]);
        } catch (\Throwable $e) {
            Log::error('🚫 [DeviceBan] Validation failed', [
                'message' => $e->getMessage(),
                'payload' => $request->all(),
            ]);
            throw $e;
        }

        $deviceTokens = [];

        if (!empty($validated['fcm_token'])) {
            $deviceTokens[] = trim((string) $validated['fcm_token']);
        }

        if (!empty($validated['device_identifier'])) {
            $deviceTokens[] = trim((string) $validated['device_identifier']);
        }

        if (!empty($validated['user_id'])) {
            $user = User::find($validated['user_id']);
            Log::info('🚫 [DeviceBan] Resolving user devices by latest fcm token', [
                'user_id' => $validated['user_id'],
                'user_found' => (bool) $user,
            ]);

            if (!$user) {
                return response()->json([
                    'message' => 'المستخدم المحدد غير موجود',
                    'user_id' => $validated['user_id'],
                ], 404);
            }

            $latestFcmToken = UserDevice::where('user_id', $user->id)
                ->whereNotNull('fcm_token')
                ->where('fcm_token', '!=', '')
                ->orderByDesc('updated_at')
                ->value('fcm_token');

            Log::info('🚫 [DeviceBan] Latest user fcm token', [
                'user_id' => $validated['user_id'],
                'fcm_token' => $latestFcmToken,
            ]);

            if ($latestFcmToken) {
                $deviceTokens[] = trim((string) $latestFcmToken);
            }

            $deviceTokens[] = 'USER_ID_' . $user->id;
        }

        $deviceTokens = array_values(array_unique(array_filter($deviceTokens, static fn ($token) => is_string($token) && trim($token) !== '')));

        if (empty($deviceTokens)) {
            return response()->json([
                'message' => 'مطلوب fcm_token أو user_id أو device_identifier',
            ], 422);
        }

        $createdBans = [];
        $alreadyBanned = [];

        foreach ($deviceTokens as $deviceToken) {
            Log::info('🚫 [DeviceBan] Processing banned token', [
                'fcm_token' => $deviceToken,
            ]);

            $existingBan = BannedDevice::where('device_identifier', $deviceToken)
                ->orWhere(function ($query) use ($validated) {
                    if (!empty($validated['user_id'])) {
                        $query->where('user_id', $validated['user_id']);
                    }
                })
                ->first();

            if ($existingBan) {
                $alreadyBanned[] = $deviceToken;
                Log::warning('🚫 [DeviceBan] Token already banned', [
                    'fcm_token' => $deviceToken,
                ]);
                continue;
            }

            try {
                $ban = BannedDevice::create([
                    'device_identifier' => $deviceToken,
                    'user_id' => $validated['user_id'] ?? null,
                    'reason' => $validated['reason'] ?? null,
                    'banned_by' => $admin->id,
                ]);

                if (str_starts_with($deviceToken, 'USER_ID_')) {
                    $this->revokeUserTokens((int) str_replace('USER_ID_', '', $deviceToken));
                } else {
                    $this->revokeDeviceTokens($deviceToken);
                }

                Log::info('🚫 [DeviceBan] Token banned', [
                    'fcm_token' => $deviceToken,
                    'banned_by' => $admin->id,
                    'reason' => $validated['reason'] ?? null,
                ]);

                $createdBans[] = $this->serializeBan($ban);
            } catch (\Throwable $e) {
                Log::error('🚫 [DeviceBan] Failed to create ban record', [
                    'fcm_token' => $deviceToken,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                throw $e;
            }
        }

        if (empty($createdBans)) {
            return response()->json([
                'success' => true,
                'message' => 'تمت معالجة جميع الأجهزة المطلوبة، لكن جميعها كانت محظورة بالفعل',
                'data' => [
                    'already_banned' => $alreadyBanned,
                ],
                'count' => 0,
            ], 200);
        }

        return response()->json([
            'success' => true,
            'message' => count($createdBans) > 1
                ? 'تم حظر جميع الأجهزة المرتبطة بهذا المستخدم بنجاح وتسجيل خروج المستخدمين'
                : 'تم حظر الجهاز بنجاح وتسجيل خروج المستخدم',
            'data' => $createdBans,
            'count' => count($createdBans),
        ], 201);
    }

    public function unbanDevice(Request $request, BannedDevice $ban)
    {
        $admin = $request->user();

        $deviceIdentifier = $ban->device_identifier;
        $ban->delete();

        Log::info('✅ [DeviceBan] Device unbanned', [
            'device_identifier' => $deviceIdentifier,
            'unbanned_by' => $admin->id,
        ]);

        return response()->json([
            'message' => 'تم إلغاء حظر الجهاز بنجاح',
        ]);
    }

    public function getBannedDevices(Request $request)
    {
        $admin = $request->user();

        $bans = BannedDevice::with(['user', 'bannedByUser'])
            ->orderByDesc('created_at')
            ->paginate($request->query('per_page', 50));

        return response()->json([
            'message' => 'تم جلب الأجهزة المحظورة بنجاح',
            'data' => $bans->map(fn($ban) => $this->serializeBan($ban)),
            'pagination' => [
                'total' => $bans->total(),
                'per_page' => $bans->perPage(),
                'current_page' => $bans->currentPage(),
                'last_page' => $bans->lastPage(),
            ],
        ]);
    }

    protected function serializeBan(BannedDevice $ban): array
    {
        $user = $ban->user ?? UserDevice::where('fcm_token', $ban->device_identifier)
            ->orWhere('device_identifier', $ban->device_identifier)
            ->first()?->user;

        return [
            'id' => $ban->id,
            'device_identifier' => $ban->device_identifier,
            'fcm_token' => $ban->device_identifier,
            'user_id' => $ban->user_id ?? $user?->id,
            'user_name' => $ban->user?->name ?? $user?->name,
            'reason' => $ban->reason,
            'banned_by' => $ban->bannedByUser ? [
                'id' => $ban->bannedByUser->id,
                'name' => $ban->bannedByUser->name,
                'email' => $ban->bannedByUser->email,
            ] : null,
            'created_at' => $ban->created_at?->toISOString(),
            'updated_at' => $ban->updated_at?->toISOString(),
        ];
    }

    protected function revokeDeviceTokens(string $deviceToken): void
    {
        $userDevices = UserDevice::where('fcm_token', $deviceToken)
            ->orWhere('device_identifier', $deviceToken)
            ->get();

        foreach ($userDevices as $device) {
            if ($device->access_token_id) {
                \Laravel\Sanctum\PersonalAccessToken::where('id', $device->access_token_id)->delete();
            }
        }
    }

    protected function revokeUserTokens(int $userId): void
    {
        $userDevices = UserDevice::where('user_id', $userId)->get();

        foreach ($userDevices as $device) {
            if ($device->access_token_id) {
                \Laravel\Sanctum\PersonalAccessToken::where('id', $device->access_token_id)->delete();
            }
        }

        \Laravel\Sanctum\PersonalAccessToken::where('tokenable_type', User::class)
            ->where('tokenable_id', $userId)
            ->delete();
    }
}
