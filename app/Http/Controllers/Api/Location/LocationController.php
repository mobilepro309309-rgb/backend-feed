<?php

namespace App\Http\Controllers\Api\Location;

use Illuminate\Http\Request;

use App\Http\Controllers\Controller;
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
}
