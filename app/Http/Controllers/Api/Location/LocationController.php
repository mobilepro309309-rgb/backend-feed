<?php

namespace App\Http\Controllers\Api\Location;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Http\Controllers\Controller;
use App\Models\Location\District;
use App\Models\Location\Governorate;

class LocationController extends Controller
{
    public function index(Request $request)
    {
        $governorates = Governorate::query()
            ->orderBy('id')
            ->with(['districts' => function ($query) {
                $query->orderBy('id')
                    ->with(['villages' => function ($villageQuery) {
                        $villageQuery->orderBy('id');
                    }]);
            }])
            ->get(['id', 'name_ar', 'name_en']);

        return response()->json([
            'governorates' => $governorates->map(function (Governorate $governorate) {
                return [
                    'id' => $governorate->id,
                    'name_ar' => $governorate->name_ar,
                    'name_en' => $governorate->name_en,
                    'districts' => $governorate->districts->map(function ($district) {
                        return [
                            'id' => $district->id,
                            'name_ar' => $district->name_ar,
                            'name_en' => $district->name_en,
                            'villages' => $district->villages->map(function ($village) {
                                return [
                                    'id' => $village->id,
                                    'name_ar' => $village->name_ar,
                                    'name_en' => $village->name_en,
                                ];
                            })->values(),
                        ];
                    })->values(),
                ];
            })->values(),
        ]);
    }

    public function governorates(Request $request)
    {
        $items = Governorate::query()
            ->select(['id', 'name_ar', 'name_en'])
            ->orderBy('name_ar')
            ->get();

        return response()->json([
            'data' => $items->map(function (Governorate $governorate) {
                return [
                    'id' => (int) $governorate->id,
                    'name_ar' => $governorate->name_ar,
                    'name_en' => $governorate->name_en,
                    'name' => $governorate->name_ar ?: $governorate->name_en,
                    'label' => $governorate->name_ar ?: $governorate->name_en,
                ];
            })->values(),
        ]);
    }

    public function districtsForGovernorate(Request $request, Governorate $governorate)
    {
        $items = District::query()
            ->where('governorate_id', $governorate->id)
            ->select(['id', 'governorate_id', 'name_ar', 'name_en'])
            ->orderBy('name_ar')
            ->get();

        return response()->json([
            'data' => $items->map(function (District $district) {
                return [
                    'id' => (int) $district->id,
                    'governorate_id' => (int) $district->governorate_id,
                    'name_ar' => $district->name_ar,
                    'name_en' => $district->name_en,
                    'name' => $district->name_ar ?: $district->name_en,
                    'label' => $district->name_ar ?: $district->name_en,
                ];
            })->values(),
            'governorate' => [
                'id' => (int) $governorate->id,
                'name_ar' => $governorate->name_ar,
                'name_en' => $governorate->name_en,
                'name' => $governorate->name_ar ?: $governorate->name_en,
            ],
        ]);
    }
}
