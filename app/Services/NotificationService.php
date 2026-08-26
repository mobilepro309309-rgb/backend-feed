<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\NotificationUser;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    public function __construct(protected FirebaseService $firebaseService)
    {
    }

    public function sendNotificationToUser(int $userId, array $payload = []): array
    {
        $user = User::find($userId);
        if (! $user) {
            return [
                'success' => false,
                'error' => 'user_not_found',
                'user_id' => $userId,
            ];
        }

        $title = trim((string) ($payload['title'] ?? 'رسالة جديدة'));
        $body = trim((string) ($payload['body'] ?? 'لديك رسالة جديدة'));
        $data = is_array($payload['data'] ?? []) ? $payload['data'] : [];

        return $this->sendNotification($user, $title, $body, $data);
    }

    public function registerToken(User $user, string $token, ?string $deviceType = null): UserDevice
    {
        return UserDevice::updateOrCreate(
            [
                'user_id' => $user->id,
                'fcm_token' => $token,
            ],
            [
                'device_type' => $deviceType,
            ]
        );
    }

    public function sendNotification(User $user, string $title, string $body, array $data = [], ?string $excludeToken = null): array
    {
        if ($user->notifications_enabled === false) {
            return [
                'skipped' => true,
                'reason' => 'notifications_disabled',
                'user_id' => $user->id,
            ];
        }

        $notification = Notification::create([
            'title' => $title,
            'body' => $body,
            'type' => $data['type'] ?? 'general',
            'data' => $data,
        ]);

        $delivery = NotificationUser::firstOrCreate([
            'notification_id' => $notification->id,
            'user_id' => $user->id,
        ]);

        $devices = UserDevice::where('user_id', $user->id)
            ->when($excludeToken, fn($query) => $query->where('fcm_token', '!=', $excludeToken))
            ->get();

        $firebaseResults = [];

        if ($devices->isEmpty()) {
            $firebaseResults[] = [
                'success' => false,
                'error' => 'No other device token registered for user',
            ];
        } else {
            foreach ($devices as $device) {
                try {
                    $result = $this->firebaseService->sendNotification(
                        $device->fcm_token,
                        $title,
                        $body,
                        $data
                    );
                } catch (\Throwable $e) {
                    Log::warning('Firebase notification dispatch failed for device', [
                        'user_id' => $user->id,
                        'device_id' => $device->id,
                        'error' => $e->getMessage(),
                    ]);

                    $result = [
                        'success' => false,
                        'error' => $e->getMessage(),
                    ];
                }

                $firebaseResults[] = [
                    'device_id' => $device->id,
                    'fcm_token' => $device->fcm_token,
                    'result' => $result,
                ];
            }
        }

        return [
            'notification' => $notification,
            'delivery' => $delivery,
            'firebase' => $firebaseResults,
        ];
    }

    public function sendPushOnlyToUser(User $user, string $title, string $body, array $data = []): array
    {
        if ($user->notifications_enabled === false) {
            return [
                'skipped' => true,
                'reason' => 'notifications_disabled',
                'user_id' => $user->id,
            ];
        }

        $devices = UserDevice::where('user_id', $user->id)
            ->whereNotNull('fcm_token')
            ->where('fcm_token', '!=', '')
            ->get();

        $firebaseResults = [];
        foreach ($devices as $device) {
            $result = $this->firebaseService->sendNotification(
                $device->fcm_token,
                $title,
                $body,
                $data
            );

            $firebaseResults[] = [
                'device_id' => $device->id,
                'result' => $result,
            ];
        }

        return [
            'success' => collect($firebaseResults)->contains(fn (array $delivery): bool => ($delivery['result']['success'] ?? false) === true),
            'firebase' => $firebaseResults,
        ];
    }

    public function sendNotificationToDeviceTokens(User $user, string $title, string $body, array $data = [], array $deviceTokens = []): array
    {
        if ($user->notifications_enabled === false) {
            return [
                'skipped' => true,
                'reason' => 'notifications_disabled',
                'user_id' => $user->id,
            ];
        }

        $notification = Notification::create([
            'title' => $title,
            'body' => $body,
            'type' => $data['type'] ?? 'general',
            'data' => $data,
        ]);

        $delivery = NotificationUser::firstOrCreate([
            'notification_id' => $notification->id,
            'user_id' => $user->id,
        ]);

        $firebaseResults = [];
        $uniqueTokens = array_values(array_unique(array_filter($deviceTokens, fn($token) => is_string($token) && $token !== '')));

        if (empty($uniqueTokens)) {
            $firebaseResults[] = [
                'success' => false,
                'error' => 'No device tokens provided for notification delivery',
            ];
        } else {
            foreach ($uniqueTokens as $token) {
                try {
                    $result = $this->firebaseService->sendNotification(
                        $token,
                        $title,
                        $body,
                        $data
                    );
                } catch (\Throwable $e) {
                    Log::warning('Firebase notification dispatch failed for token', [
                        'user_id' => $user->id,
                        'fcm_token' => $token,
                        'error' => $e->getMessage(),
                    ]);

                    $result = [
                        'success' => false,
                        'error' => $e->getMessage(),
                    ];
                }

                $firebaseResults[] = [
                    'fcm_token' => $token,
                    'result' => $result,
                ];
            }
        }

        return [
            'notification' => $notification,
            'delivery' => $delivery,
            'firebase' => $firebaseResults,
        ];
    }
}
