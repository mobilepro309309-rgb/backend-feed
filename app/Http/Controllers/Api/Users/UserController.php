<?php

namespace App\Http\Controllers\Api\Users;

use Illuminate\Http\Request;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Users\UserAddress;
use App\Services\GeocodingService;

class UserController extends Controller
{
    protected $geocodingService;

    public function __construct(GeocodingService $geocodingService)
    {
        $this->geocodingService = $geocodingService;
    }

    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'school_grade' => 'nullable|in:1,2,3,4,5,6,7,8,9,10,11,12,اولى,ثانية,ثالثة,رابعة,خامسة,سادسة,سابعة,الثامنة,التاسعة,العاشرة,الحادية عشرة,الثانية عشرة,اعدادي,ثانوي',
            'gender' => 'nullable|in:ولد,بنت',
            'location' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'theme_mode' => 'nullable|in:light,dark',
            'governorate_id' => 'nullable|exists:governorates,id',
            'district_id' => 'nullable|exists:districts,id',
            'village_id' => 'nullable|exists:villages,id',
            'street_details' => 'nullable|string|max:255',
        ]);

        $user = $request->user();

        if (isset($validated['school_grade'])) {
            $user->school_grade = $validated['school_grade'];
        }
        if (isset($validated['gender'])) {
            $user->gender = $validated['gender'];
        }

        if (isset($validated['theme_mode'])) {
            $user->theme_mode = $validated['theme_mode'];
        }

        if (isset($validated['location']) || isset($validated['latitude']) || isset($validated['longitude'])) {
            $addressData = [];

            if (isset($validated['location'])) {
                $addressData['village_name'] = $validated['location'];
            }

            if (isset($validated['latitude'])) {
                $addressData['latitude'] = (float) $validated['latitude'];
            }

            if (isset($validated['longitude'])) {
                $addressData['longitude'] = (float) $validated['longitude'];
            }

            if (! empty($addressData)) {
                UserAddress::updateOrCreate(
                    ['user_id' => $user->id],
                    $addressData
                );
            }
        }

        $user->save();

        $user->loadMissing('address');

        return response()->json([
            'message' => 'تم تحديث البيانات بنجاح',
            'user' => $this->serializeUser($user),
        ]);
    }

    public function show(Request $request)
    {
        return response()->json([
            'user' => $this->serializeUser($request->user()),
        ]);
    }

    public function nearbyStudents(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['nullable', 'numeric', 'min:0.1'],
        ]);

        $radiusInKm = (float) ($validated['radius'] ?? 2.0);
        $latitude = (float) $validated['latitude'];
        $longitude = (float) $validated['longitude'];

        $students = UserAddress::query()
            ->with('user')
            ->whereHas('user', function ($query): void {
                $query->where('role', 'user');
            })
            ->where('user_id', '!=', $request->user()->id)
            ->withinRadius($latitude, $longitude, $radiusInKm)
            ->get()
            ->filter(fn(UserAddress $address): bool => $address->user !== null)
            ->map(function (UserAddress $address): array {
                return [
                    'user' => [
                        'id' => $address->user->id,
                        'name' => $address->user->name,
                        'phone' => $address->user->phone,
                    ],
                    'distance_km' => round((float) $address->distance_km, 2),
                    'latitude' => (float) $address->latitude,
                    'longitude' => (float) $address->longitude,
                ];
            });

        return response()->json([
            'students' => $students,
            'count' => $students->count(),
        ]);
    }

    protected function serializeUser($user): array
    {
        $address = $user->address;
        $walletBalance = (float) ($user->wallet?->balance ?? 0);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'gender' => $user->gender,
            'school_grade' => $user->school_grade,
            'grade' => $user->school_grade ?? $user->grade ?? $user->grade_level ?? $user->academic_year ?? $user->stage ?? null,
            'referral_code' => $user->referral_code ?? null,
            'location' => $address?->village_name ?? $address?->governorate ?? null,
            'latitude' => $address?->latitude,
            'longitude' => $address?->longitude,
            'theme_mode' => $user->theme_mode,
            'wallet' => [
                'balance' => $walletBalance,
            ],
            'wallet_balance' => $walletBalance,
            'geolocation_updated_at' => null,
        ];
    }
}
