<?php

namespace Tests\Feature;

use App\Models\District;
use App\Models\Governorate;
use App\Models\Village;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocationsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_governorates_with_nested_districts_and_villages(): void
    {
        $governorate = Governorate::create([
            'name_ar' => 'القاهرة',
            'name_en' => 'Cairo',
        ]);

        $district = District::create([
            'governorate_id' => $governorate->id,
            'name_ar' => 'المعادي',
            'name_en' => 'Maadi',
        ]);

        Village::create([
            'district_id' => $district->id,
            'name_ar' => 'المعادي القديمة',
            'name_en' => 'Old Maadi',
        ]);

        $response = $this->getJson('/api/v1/locations');

        $response->assertOk()
            ->assertJsonPath('governorates.0.name_ar', 'القاهرة')
            ->assertJsonPath('governorates.0.districts.0.name_ar', 'المعادي')
            ->assertJsonPath('governorates.0.districts.0.villages.0.name_ar', 'المعادي القديمة');
    }
}
