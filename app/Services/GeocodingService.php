<?php

namespace App\Services;

use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class GeocodingService
{
    protected $client;
    protected $cacheTime = 86400; // 24 ساعات

    protected array $fallbackCoordinates = [
        'القاهرة' => [30.0444, 31.2357],
        'الجيزة' => [30.0131, 31.2089],
        'الإسكندرية' => [31.2001, 29.9187],
        'الاسكندرية' => [31.2001, 29.9187],
        'القليوبية' => [30.1885, 31.2056],
        'الشرقية' => [30.5877, 31.8156],
        'الدقهلية' => [31.0364, 31.3807],
        'البحر الاحمر' => [25.2854, 34.5553],
        'البحر الأحمر' => [25.2854, 34.5553],
        'بني سويف' => [29.0667, 31.0994],
        'الفيوم' => [29.3099, 30.8428],
        'المنيا' => [28.1099, 30.7503],
        'سوهاج' => [26.556, 31.6948],
        'قنا' => [26.1642, 32.7267],
        'أسيوط' => [27.1809, 31.1837],
        'الوادى الجديد' => [25.4490, 30.5478],
        'المنوفية' => [30.4651, 31.0019],
        'كفر الشيخ' => [31.1114, 30.9388],
        'دمياط' => [31.4175, 31.8133],
        'شمال سيناء' => [30.5972, 33.6189],
        'الجنوب سيناء' => [29.9737, 32.5263],
        'السويس' => [29.9737, 32.5263],
        'الإسماعيلية' => [30.5965, 32.2715],
        'الاسماعيلية' => [30.5965, 32.2715],
        'بورسعيد' => [31.2653, 32.3019],
        'اسوان' => [24.0889, 32.8998],
        'أسوان' => [24.0889, 32.8998],
    ];

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => 'https://nominatim.openstreetmap.org/',
            'timeout' => 5,
        ]);
    }

    /**
     * تحويل الإحداثيات إلى اسم المكان (Reverse Geocoding)
     */
    public function reverseGeocode($latitude, $longitude)
    {
        try {
            $cacheKey = "geolocation:{$latitude},{$longitude}";

            if (Cache::has($cacheKey)) {
                return Cache::get($cacheKey);
            }

            $response = $this->client->request('GET', 'reverse', [
                'query' => [
                    'lat' => $latitude,
                    'lon' => $longitude,
                    'format' => 'json',
                    'zoom' => 10,
                    'addressdetails' => 1,
                    'accept-language' => 'ar',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $location = $this->extractLocationName($data);

            Cache::put($cacheKey, $location, $this->cacheTime);

            return $location;
        } catch (\Exception $e) {
            \Log::warning('Geocoding failed: ' . $e->getMessage());
            return null;
        }
    }

    public function geocodeAddress(string $address): ?array
    {
        if (empty($address)) {
            return null;
        }

        $cacheKey = 'geocode:' . md5($address);
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $fallback = $this->getFallbackCoordinates($address);
        if ($fallback) {
            Cache::put($cacheKey, $fallback, $this->cacheTime);
            return $fallback;
        }

        try {
            $response = $this->client->request('GET', 'search', [
                'query' => [
                    'q' => $address,
                    'format' => 'json',
                    'limit' => 1,
                    'accept-language' => 'ar',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $firstResult = $data[0] ?? null;

            if (!empty($firstResult['lat']) && !empty($firstResult['lon'])) {
                $coordinates = [(float) $firstResult['lat'], (float) $firstResult['lon']];
                Cache::put($cacheKey, $coordinates, $this->cacheTime);
                return $coordinates;
            }
        } catch (\Exception $e) {
            \Log::warning('Forward geocoding failed: ' . $e->getMessage());
        }

        return null;
    }

    protected function getFallbackCoordinates(string $address): ?array
    {
        $normalizedAddress = $this->normalizeAddress($address);

        foreach ($this->fallbackCoordinates as $name => $coordinates) {
            if (str_contains($normalizedAddress, $this->normalizeAddress($name))) {
                return $coordinates;
            }
        }

        return null;
    }

    protected function normalizeAddress(string $address): string
    {
        $normalized = mb_strtolower($address, 'UTF-8');
        $normalized = str_replace(['-', '_', '،', ' ', 'ـ'], '', $normalized);
        $normalized = str_replace(['أ', 'إ', 'آ'], 'ا', $normalized);
        $normalized = str_replace(['ى'], 'ي', $normalized);

        return $normalized;
    }

    /**
     * استخراج اسم المكان من بيانات Nominatim
     */
    protected function extractLocationName($data)
    {
        if (!isset($data['address'])) {
            return null;
        }

        $address = $data['address'];

        // الأولوية: city > town > village > county > province > country
        $priorities = [
            'city',
            'town',
            'village',
            'county',
            'province',
            'state',
            'country',
        ];

        foreach ($priorities as $key) {
            if (!empty($address[$key])) {
                return $address[$key];
            }
        }

        return $data['name'] ?? null;
    }
}
