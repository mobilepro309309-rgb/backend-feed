<?php

namespace Tests\Feature;

use Tests\TestCase;

class KashierControllerTest extends TestCase
{
    public function test_it_generates_a_kashier_payment_hash(): void
    {
        $response = $this->postJson('/api/kashier/payment-hash', [
            'orderId' => 'order-123',
            'amount' => '100.50',
            'currency' => 'EGP',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'mid',
            'orderId',
            'amount',
            'currency',
            'hash',
            'mode',
            'baseURL',
        ]);

        $expectedPath = '?mid='.env('KASHIER_MID', '').'&orderId=order-123&amount=100.50&currency=EGP';
        $expectedHash = hash_hmac('sha256', $expectedPath, env('KASHIER_SECRET_KEY', ''));

        $response->assertJson([
            'mid' => env('KASHIER_MID', ''),
            'orderId' => 'order-123',
            'amount' => '100.50',
            'currency' => 'EGP',
            'hash' => $expectedHash,
            'mode' => env('KASHIER_MODE', 'test'),
            'baseURL' => 'https://checkout.kashier.io',
        ]);
    }
}
