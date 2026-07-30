<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Models\PendingDeviceLogin;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\Users\UserAddress;
use App\Services\GeocodingService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    protected GeocodingService $geocodingService;
    protected NotificationService $notificationService;

    public function __construct(GeocodingService $geocodingService, NotificationService $notificationService)
    {
        $this->geocodingService = $geocodingService;
        $this->notificationService = $notificationService;
    }

    public function login(LoginRequest $request)
    {
        $identifier = $request->input('phone') ?? $request->input('identifier') ?? $request->input('email');
        $user = null;

        if ($identifier) {
            $normalizedIdentifier = $this->normalizePhone($identifier);
            $user = User::whereNotNull('phone')
                ->get()
                ->first(fn($candidate) => $this->normalizePhone($candidate->phone) === $normalizedIdentifier);
        }

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'phone' => ['بيانات المصادقة غير صحيحة'],
            ]);
        }

        $deviceToken = $request->input('fcm_token');
        $deviceIdentifier = $request->input('device_identifier') ?? $request->input('device_id');
        $deviceType = $request->input('device_type');

        $existingDevice = UserDevice::where('user_id', $user->id)
            ->when($deviceToken, fn($query) => $query->where('fcm_token', $deviceToken))
            ->when(!$deviceToken && $deviceIdentifier, fn($query) => $query->where('device_identifier', $deviceIdentifier))
            ->first();

        $hasTrustedDevice = UserDevice::where('user_id', $user->id)
            ->where('trusted', true)
            ->exists();

        if ($existingDevice && $existingDevice->trusted) {
            $tokenResult = $user->createToken('auth_token');
            $accessToken = $tokenResult->accessToken;

            $existingDevice->update([
                'device_type' => $deviceType,
                'device_identifier' => $deviceIdentifier,
                'access_token_id' => $accessToken?->id,
            ]);

            return response()->json([
                'token' => $tokenResult->plainTextToken,
                'user' => $this->serializeUser($user),
                'message' => 'تم تسجيل الدخول بنجاح',
            ]);
        }

        if (! $hasTrustedDevice) {
            $tokenResult = $user->createToken('auth_token');
            $accessToken = $tokenResult->accessToken;

            $device = UserDevice::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'fcm_token' => $deviceToken ?? '',
                    'device_identifier' => $deviceIdentifier ?? '',
                ],
                [
                    'device_type' => $deviceType,
                    'trusted' => true,
                    'access_token_id' => $accessToken?->id,
                ]
            );

            return response()->json([
                'token' => $tokenResult->plainTextToken,
                'user' => $this->serializeUser($user),
                'message' => 'تم تسجيل الدخول بنجاح',
            ]);
        }

        if ($existingDevice && ! $existingDevice->trusted) {
            $pending = PendingDeviceLogin::where('target_device_id', $existingDevice->id)
                ->where('status', 'pending')
                ->first();

            if ($pending) {
                return response()->json([
                    'status' => 'pending_device_approval',
                    'message' => 'لا يزال طلب الموافقة معلقاً. يرجى الانتظار.',
                    'pending_id' => $pending->id,
                ], 202);
            }
        }

        $pending = $this->createPendingDeviceLogin($user, $deviceToken, $deviceType, $deviceIdentifier);
        $this->notifyTrustedDevicesOfPendingLogin($user, $pending);

        return response()->json([
            'status' => 'pending_device_approval',
            'message' => 'تم إرسال طلب موافقة إلى جهازك القديم. انتظر الموافقة لإكمال تسجيل الدخول.',
            'pending_id' => $pending->id,
        ], 202);
    }

    public function register(RegisterRequest $request)
    {
        $validated = $request->validated();

        $phone = $validated['phone'] ?? null;
        if ($phone) {
            $normalizedPhone = $this->normalizePhone($phone);
            $existingUser = User::all()->first(fn($candidate) => $this->normalizePhone($candidate->phone) === $normalizedPhone);

            if ($existingUser) {
                throw ValidationException::withMessages([
                    'phone' => ['هذا الرقم مسجل بالفعل'],
                ]);
            }
        }

        $user = DB::transaction(function () use ($validated, $phone): User {
            $user = User::create([
                'name' => $validated['name'],
                'phone' => $phone,
                'password' => Hash::make($validated['password']),
            ]);

            $addressData = [
                'user_id' => $user->id,
                'governorate' => $validated['governorate'] ?? null,
                'city_or_center' => $validated['district'] ?? null,
                'village_name' => $validated['village'] ?? null,
            ];

            if (array_key_exists('latitude', $validated) && $validated['latitude'] !== null) {
                $addressData['latitude'] = (float) $validated['latitude'];
            }

            if (array_key_exists('longitude', $validated) && $validated['longitude'] !== null) {
                $addressData['longitude'] = (float) $validated['longitude'];
            }

            if (! empty(array_filter($addressData, static fn($value): bool => $value !== null && $value !== '' && $value !== []))) {
                UserAddress::create($addressData);
            }

            $user->profile()->firstOrCreate([], [
                'theme_mode' => 'light',
                'settings' => [],
            ]);

            return $user;
        });

        return response()->json([
            'token' => $user->createToken('auth_token')->plainTextToken,
            'user' => $this->serializeUser($user),
            'message' => 'تم إنشاء الحساب بنجاح',
        ], 201);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->serializeUser($request->user()),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'تم تسجيل الخروج بنجاح',
        ]);
    }

    protected function createPendingDeviceLogin(User $user, ?string $deviceToken, ?string $deviceType = null, ?string $deviceIdentifier = null): \App\Models\PendingDeviceLogin
    {
        $device = UserDevice::updateOrCreate(
            [
                'user_id' => $user->id,
                'fcm_token' => $deviceToken ?? '',
            ],
            [
                'device_type' => $deviceType,
                'device_identifier' => $deviceIdentifier,
                'trusted' => false,
                'access_token_id' => null,
            ]
        );

        return PendingDeviceLogin::create([
            'user_id' => $user->id,
            'target_device_id' => $device->id,
            'status' => 'pending',
            'reason' => 'new_device_login',
        ]);
    }

    protected function notifyTrustedDevicesOfPendingLogin(User $user, \App\Models\PendingDeviceLogin $pending): void
    {
        $trustedDevices = UserDevice::where('user_id', $user->id)
            ->where('trusted', true)
            ->where('fcm_token', '!=', '')
            ->get();

        $tokens = $trustedDevices->pluck('fcm_token')
            ->filter()
            ->values()
            ->toArray();

        if (empty($tokens)) {
            Log::warning('No trusted device tokens found for pending login approval', ['user_id' => $user->id]);
            return;
        }

        $title = 'محاولة تسجيل دخول جديدة';
        $body = 'محاولة تسجيل دخول جديدة من هاتف آخر. هل توافق؟';

        $this->notificationService->sendNotificationToDeviceTokens(
            $user,
            $title,
            $body,
            [
                'type' => 'security_login_alert',
                'action_type' => 'security_alert',
                'user_id' => $user->id,
                'pending_id' => $pending->id,
                'target_device_id' => $pending->target_device_id,
            ],
            $tokens
        );
    }

    protected function sendSecurityNotificationToDevice(UserDevice $device, User $user, string $title, string $body, UserDevice $newDevice): void
    {
        $this->notificationService->sendNotificationToDeviceTokens(
            $user,
            $title,
            $body,
            [
                'type' => 'security_login_alert',
                'action_type' => 'security_alert',
                'user_id' => $user->id,
                'target_device_id' => $newDevice->id,
                'target_device_identifier' => $newDevice->device_identifier,
                'target_device_type' => $newDevice->device_type,
                'target_session_token_id' => $newDevice->access_token_id,
            ],
            [$device->fcm_token]
        );
    }

    protected function serializeUser(User $user): array
    {
        $profile = $user->profile()->first();
        $avatarUrl = $profile?->avatar_url ?? $profile?->avatar ?? null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email ?? null,
            'phone' => $user->phone,
            'role' => $user->role,
            'avatar_url' => $avatarUrl,
            'avatar' => $avatarUrl,
            'profile_image' => $avatarUrl,
            'imageUrl' => $avatarUrl,
        ];
    }

    protected function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        return preg_replace('/\D/', '', (string) $phone);
    }
}
