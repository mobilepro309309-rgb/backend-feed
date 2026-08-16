<?php

namespace Tests\Feature;

use App\Models\Location\District;
use App\Models\Location\Governorate;
use App\Models\Location\Village;
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

    public function test_it_returns_all_governorates_and_districts_for_a_selected_governorate(): void
    {
        $cairo = Governorate::create([
            'name_ar' => 'القاهرة',
            'name_en' => 'Cairo',
        ]);

        $alex = Governorate::create([
            'name_ar' => 'الإسكندرية',
            'name_en' => 'Alexandria',
        ]);

        District::create([
            'governorate_id' => $cairo->id,
            'name_ar' => 'المعادي',
            'name_en' => 'Maadi',
        ]);

        District::create([
            'governorate_id' => $alex->id,
            'name_ar' => 'المنتزة',
            'name_en' => 'Montaza',
        ]);

        $governoratesResponse = $this->getJson('/api/locations/governorates');
        $governoratesResponse->assertOk()
            ->assertJsonPath('data.0.name_ar', 'القاهرة')
            ->assertJsonPath('data.1.name_ar', 'الإسكندرية');

        $districtsResponse = $this->getJson('/api/locations/governorates/' . $cairo->id . '/districts');
        $districtsResponse->assertOk()
            ->assertJsonPath('data.0.governorate_id', $cairo->id)
            ->assertJsonPath('data.0.name_ar', 'المعادي');
    }
}
