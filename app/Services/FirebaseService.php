<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

use Kreait\Firebase\{Factory, Messaging};
use Kreait\Firebase\Exception\{FirebaseException, MessagingException};
use Kreait\Firebase\Messaging\{CloudMessage, Notification as FirebaseNotification, AndroidConfig, ApnsConfig};

class FirebaseService
{
    protected ?Messaging $messaging = null;
    protected ?string $credentialsPath = null;

    public function __construct()
    {
        $this->credentialsPath = env('FIREBASE_CREDENTIALS', base_path('firebase-credentials.json'));
        $this->messaging = $this->initializeFirebaseMessaging();
    }

    protected function initializeFirebaseMessaging(): ?Messaging
    {
        try {
            $path = $this->credentialsPath;

            if (empty($path)) {
                Log::warning('[FirebaseService] Firebase credentials path is empty');
                return null;
            }

            if (! $this->isAbsolutePath($path)) {
                $path = base_path($path);
            }

            if (! file_exists($path)) {
                Log::error('[FirebaseService] Firebase credentials file not found', ['path' => $path]);
                return null;
            }

            $factory = (new Factory())->withServiceAccount($path);
            $messaging = $factory->createMessaging();

            Log::info('[FirebaseService] Firebase messaging initialized', ['path' => $path]);

            return $messaging;
        } catch (\Throwable $e) {
            Log::error('[FirebaseService] Failed to initialize Firebase messaging', ['error' => $e->getMessage(), 'path' => $this->credentialsPath]);
            return null;
        }
    }

    protected function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1 || str_starts_with($path, '/') || str_starts_with($path, '\\\\');
    }

    /**
     * Send a push notification to a device token using direct FCM.
     *
     * @param string $deviceToken
     * @param string $title
     * @param string $body
     * @param array $data
     * @return array
     */
    public function sendNotification(string $deviceToken, string$title, string $body, array $data = []): array
    {
        try {
            if ($this->messaging === null) {
                Log::error('[FirebaseService] Firebase messaging is not initialized', ['token' => $deviceToken]);
                return [
                    'success' => false,
                    'provider' => 'fcm',
                    'error' => 'firebase_messaging_unavailable',
                ];
            }

            return $this->sendFcmNotification($deviceToken, $title, $body, $data);
        } catch (\Throwable $e) {
            Log::error('[FirebaseService] sendNotification failed', ['error' => $e->getMessage(), 'token' => $deviceToken]);
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function sendFcmNotification(string $deviceToken, string $title, string $body, array $data = []): array
    {
        try {
            $normalizedData = [];
            $reservedKeys = [
                'from',
                'to',
                'message_id',
                'message_type',
                'collapse_key',
                'content_available',
                'mutable_content',
                'priority',
                'dry_run',
                'restricted_package_name',
                'notification',
                'data',
                'android',
                'apns',
                'webpush',
                'fcm_options',
                'registration_ids',
                'condition',
                'ttl',
                'time_to_live',
            ];

            foreach ($data as $key => $value) {
                $normalizedKey = is_string($key) ? trim($key) : (string) $key;
                if ($normalizedKey === '') {
                    continue;
                }

                if (in_array($normalizedKey, $reservedKeys, true)) {
                    $normalizedKey = "payload_{$normalizedKey}";
                }

                $normalizedData[$normalizedKey] = is_scalar($value) || $value === null ? (string) $value : json_encode($value);
            }

            $message = CloudMessage::withTarget('token', $deviceToken)
                ->withNotification(FirebaseNotification::create($title, $body));

            // Ensure OS-level delivery for background/closed apps by setting high priority
            // and specifying Android channel. APNs priority set for iOS.
            try {
                $androidConfig = AndroidConfig::fromArray([
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => $data['channelId'] ?? 'default',
                        'default_sound' => true,
                        'sound' => 'default',
                        'visibility' => 'PUBLIC',
                    ],
                ]);

                $apnsConfig = ApnsConfig::fromArray([
                    'headers' => [
                        'apns-priority' => '10',
                        'apns-push-type' => 'alert',
                    ],
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                            'content-available' => 1,
                            'alert' => [
                                'title' => $title,
                                'body' => $body,
                            ],
                        ],
                    ],
                ]);

                $message = $message->withAndroidConfig($androidConfig)->withApnsConfig($apnsConfig);
            } catch (\Throwable $e) {
                // If the SDK doesn't support these helpers in the environment, continue without them
            }

            if (! empty($normalizedData)) {
                $message = $message->withData($normalizedData);
            }

            // Expose title/body in data too for receivers that read the payload directly
            $message = $message->withData(array_merge($normalizedData, [
                'notification_title' => $title,
                'notification_body' => $body,
                'notification_type' => $data['type'] ?? 'general',
            ]));

            $messageId = $this->messaging->send($message);

            try {
                Log::info('[FirebaseService] FCM message sent', ['token' => $deviceToken, 'message_id' => $messageId, 'data_keys' => array_keys($normalizedData)]);
            } catch (\Throwable $e) {
                // swallow logging errors to avoid breaking notification flow
            }

            return [
                'success' => true,
                'provider' => 'fcm',
                'token' => $deviceToken,
                'message_id' => $messageId,
            ];
        } catch (MessagingException | FirebaseException | \Throwable $e) {
            Log::error('[FirebaseService] FCM send failed', ['error' => $e->getMessage(), 'token' => $deviceToken]);

            return [
                'success' => false,
                'provider' => 'fcm',
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function getExpoAccessToken(): ?string
    {
        return env('EXPO_ACCESS_TOKEN') ?: env('EXPO_TOKEN') ?: null;
    }
}