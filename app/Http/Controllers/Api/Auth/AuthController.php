<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Models\PendingDeviceLogin;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\UserReferral;
use App\Models\Users\UserAddress;
use App\Services\GeocodingService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use App\Models\BannedDevice;

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
        $deviceToken = $request->input('fcm_token');
        $deviceIdentifier = $request->input('device_identifier') ?? $request->input('device_id');

        if (BannedDevice::isDeviceBanned($deviceToken) || BannedDevice::isDeviceBanned($deviceIdentifier)) {
            return response()->json([
                'message' => 'عذراً، هذا الجهاز محظور من استخدام المنصة',
                'status' => 'device_banned',
            ], 403);
        }

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
            $tokenResult = $user->createToken('auth_token', ['api:access']);
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
            $tokenResult = $user->createToken('auth_token', ['api:access']);
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

        // Disabled intentionally: do not create a new-device login notification / approval push when a login is marked pending.
        $pending = $this->createPendingDeviceLogin($user, $deviceToken, $deviceType, $deviceIdentifier);
        // $this->notifyTrustedDevicesOfPendingLogin($user, $pending);

        return response()->json([
            'status' => 'pending_device_approval',
            'message' => 'تم إرسال طلب موافقة إلى جهازك القديم. انتظر الموافقة لإكمال تسجيل الدخول.',
            'pending_id' => $pending->id,
        ], 202);
    }

    public function register(RegisterRequest $request)
    {
        $deviceToken = $request->input('fcm_token');
        $deviceIdentifier = $request->input('device_identifier') ?? $request->input('device_id');

        if (BannedDevice::isDeviceBanned($deviceToken) || BannedDevice::isDeviceBanned($deviceIdentifier)) {
            return response()->json([
                'message' => 'عذراً، هذا الجهاز محظور من استخدام المنصة',
                'status' => 'device_banned',
            ], 403);
        }

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

        $referralCodeInput = trim((string) ($validated['referral_code'] ?? ''));
        $referrer = null;

        if ($referralCodeInput !== '') {
            $referrer = User::where('referral_code', strtoupper($referralCodeInput))->first();

            if (! $referrer) {
                throw ValidationException::withMessages([
                    'referral_code' => ['رمز الإحالة غير موجود أو غير صالح'],
                ]);
            }
        }

        $user = DB::transaction(function () use ($validated, $phone, $referralCodeInput, $referrer): User {
            $user = User::create([
                'name' => $validated['name'],
                'phone' => $phone,
                'password' => Hash::make($validated['password']),
                'gender' => $validated['gender'] ?? null,
                'school_grade' => $validated['school_grade'] ?? null,
                'stage_id' => $validated['stage_id'] ?? null,
                'grade_id' => $validated['grade_id'] ?? null,
                'track_id' => $validated['track_id'] ?? null,
                'specialized_subject_id' => $validated['specialized_subject_id'] ?? null,
                'education_system' => $validated['education_system'] ?? 'general',
                'city_or_address' => $validated['city_or_address'] ?? null,
                'referral_code' => User::generateUniqueReferralCode($validated['name'] ?? null),
            ]);

            if ($referrer && $referrer->id !== $user->id) {
                UserReferral::create([
                    'referrer_id' => $referrer->id,
                    'referred_id' => $user->id,
                    'points_awarded' => 0,
                ]);
            }

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

        $user->load([
            'stage',
            'grade.stage',
            'track.grade.stage',
            'specializedSubject.track.grade.stage',
        ]);

        return response()->json([
            'token' => $user->createToken('auth_token', ['api:access'])->plainTextToken,
            'user' => $this->serializeUser($user),
            'message' => 'تم إنشاء الحساب بنجاح',
        ], 201);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'user' => $this->serializeUser($user),
            'wallet' => [
                'balance' => (float) ($user->wallet?->balance ?? 0),
            ],
            'wallet_balance' => (float) ($user->wallet?->balance ?? 0),
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
        // Disabled intentionally: no security login notification is created for trusted devices during a pending login.
        Log::info('[AuthController] Trusted-device pending login notification disabled', [
            'user_id' => $user->id,
            'pending_id' => $pending->id,
        ]);
    }

    protected function sendSecurityNotificationToDevice(UserDevice $device, User $user, string $title, string $body, UserDevice $newDevice): void
    {
        // Disabled intentionally: no automatic security notification is sent to the device upon new login detection.
        Log::info('[AuthController] Security notification to device disabled', [
            'user_id' => $user->id,
            'device_id' => $device->id,
            'new_device_id' => $newDevice->id,
        ]);
    }

    protected function serializeUser(User $user): array
    {
        $profile = $user->profile()->first();
        $avatarUrl = $profile?->avatar_url ?? $profile?->avatar ?? null;
        $themeMode = $profile?->theme_mode ?? 'light';
        $walletBalance = (float) ($user->wallet?->balance ?? 0);
        $referralsCount = (int) $user->referrals()->count();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email ?? null,
            'phone' => $user->phone,
            'role' => $user->role,
            'gender' => $user->gender,
            'school_grade' => $user->school_grade ?? null,
            'stage_id' => $user->stage_id,
            'grade_id' => $user->grade_id,
            'track_id' => $user->track_id,
            'specialized_subject_id' => $user->specialized_subject_id,
            'education_system' => $user->education_system ?? 'general',
            'city_or_address' => $user->city_or_address,
            'stage' => $user->stage,
            'educational_grade' => $user->grade,
            'track' => $user->track,
            'specialized_subject' => $user->specializedSubject,
            'grade' => $user->school_grade ?? null,
            'referral_code' => $user->referral_code ?? null,
            'referred_count' => $referralsCount,
            'referrals_count' => $referralsCount,
            'referral_count' => $referralsCount,
            'avatar_url' => $avatarUrl,
            'avatar' => $avatarUrl,
            'profile_image' => $avatarUrl,
            'imageUrl' => $avatarUrl,
            'theme_mode' => $themeMode,
            'profile' => [
                'theme_mode' => $themeMode,
                'avatar_url' => $avatarUrl,
                'avatar' => $avatarUrl,
                'profile_image' => $avatarUrl,
                'imageUrl' => $avatarUrl,
            ],
            'wallet' => [
                'balance' => $walletBalance,
            ],
            'wallet_balance' => $walletBalance,
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
