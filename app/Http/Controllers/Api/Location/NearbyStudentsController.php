<?php

namespace App\Http\Controllers\Api\Location;

use App\Http\Controllers\Controller;
use App\Models\Users\UserAddress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NearbyStudentsController extends Controller
{
    public function getNearbyCountOrList(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'يجب تسجيل الدخول أولاً.',
            ], 401);
        }

        $userAddress = $user->address()->first();

        if (! $userAddress || $userAddress->latitude === null || $userAddress->longitude === null) {
            return response()->json([
                'message' => 'لم يتم حفظ إحداثيات المستخدم بعد.',
            ], 442);
        }

        $latitude = (float) $userAddress->latitude;
        $longitude = (float) $userAddress->longitude;
        $radiusInKm = 2.0;

        $students = UserAddress::query()
            ->with('user:id,name,phone')
            ->whereHas('user', function ($query): void {
                $query->where('role', 'user');
            })
            ->where('user_id', '!=', $user->id)
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
                ];
            });

        return response()->json([
            'total_nearby_students' => $students->count(),
            'count' => $students->count(),
            'students' => $students,
        ], 200);
    }
}
