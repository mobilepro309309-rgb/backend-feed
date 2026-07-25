<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Models\User;
use App\Models\Users\UserAddress;
use App\Services\GeocodingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    protected GeocodingService $geocodingService;

    public function __construct(GeocodingService $geocodingService)
    {
        $this->geocodingService = $geocodingService;
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

        return response()->json([
            'token' => $user->createToken('auth_token')->plainTextToken,
            'user' => $this->serializeUser($user),
            'message' => 'تم تسجيل الدخول بنجاح',
        ]);
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

    protected function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email ?? null,
            'phone' => $user->phone,
            'role' => $user->role,
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
