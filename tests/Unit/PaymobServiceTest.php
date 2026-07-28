<?php

namespace Tests\Unit;

use App\Services\PaymobService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymobServiceTest extends TestCase
{
    #[Test]
    public function it_uses_paymob_orders_and_payment_keys_for_fawry_and_returns_reference(): void
    {
        Http::fake([
            'https://accept.paymob.com/api/auth/tokens' => Http::response([
                'token' => 'auth-token',
            ], 200),
            'https://accept.paymob.com/api/ecommerce/orders' => Http::response([
                'id' => 123456,
                'merchant_order_id' => 'wallet_topup_test',
                'order_url' => 'https://accept.paymob.com/standalone/?ref=test-ref',
                'token' => 'order-token',
                'payment_status' => 'UNPAID',
            ], 201),
            'https://accept.paymob.com/api/acceptance/payment_keys' => Http::response([
                'token' => 'payment-token',
                'billing_data' => ['email' => 'test@example.com'],
            ], 201),
        ]);

        $service = new PaymobService('api-key', 'public-key', 'secret-key', 'hmac', 123456, 654321, 'iframe-id');

        $result = $service->createTopUpPayment(5500, ['email' => 'test@example.com'], 'fawry');

        $this->assertSame('payment-token', $result['payment_token']);
        $this->assertSame('kiosk', $result['source']);
        $this->assertSame('https://accept.paymob.com/standalone?integration_id=654321&client_secret=payment-token', $result['payment_url']);
        $this->assertSame('https://accept.paymob.com/standalone?integration_id=654321&client_secret=payment-token', $result['redirect_url']);
        $this->assertSame('iframe-id', $result['iframe_id']);
        $this->assertSame('https://accept.paymob.com/standalone?integration_id=654321&client_secret=payment-token', $result['payment_iframe_url']);
        $this->assertArrayNotHasKey('order_url', $result);
    }

    #[Test]
    public function it_uses_the_card_integration_id_for_card_payments(): void
    {
        Http::fake([
            'https://accept.paymob.com/api/auth/tokens' => Http::response([
                'token' => 'auth-token',
            ], 200),
            'https://accept.paymob.com/api/ecommerce/orders' => Http::response([
                'id' => 123456,
                'merchant_order_id' => 'wallet_topup_test',
                'order_url' => 'https://accept.paymob.com/standalone/?ref=test-ref',
                'token' => 'order-token',
                'payment_status' => 'UNPAID',
            ], 201),
            'https://accept.paymob.com/api/acceptance/payment_keys' => Http::response([
                'token' => 'payment-token',
                'billing_data' => ['email' => 'test@example.com'],
            ], 201),
        ]);

        $service = new PaymobService('api-key', 'public-key', 'secret-key', 'hmac', 123456, 654321, 'iframe-id');

        $result = $service->createTopUpPayment(5500, ['email' => 'test@example.com'], 'card');

        $this->assertSame('card', $result['source']);
        $this->assertSame('https://accept.paymob.com/standalone?integration_id=123456&client_secret=payment-token', $result['payment_url']);
        $this->assertSame('https://accept.paymob.com/standalone?integration_id=123456&client_secret=payment-token', $result['redirect_url']);
    }

    #[Test]
    public function it_fetches_and_returns_paymob_bill_reference_for_fawry_flows(): void
    {
        Http::fake([
            'https://accept.paymob.com/api/auth/tokens' => Http::response([
                'token' => 'auth-token',
            ], 200),
            'https://accept.paymob.com/api/ecommerce/orders' => Http::response([
                'id' => 123456,
                'merchant_order_id' => 'wallet_topup_test',
                'order_url' => 'https://accept.paymob.com/standalone/?ref=test-ref',
                'token' => 'order-token',
                'payment_status' => 'UNPAID',
            ], 201),
            'https://accept.paymob.com/api/acceptance/payment_keys' => Http::response([
                'token' => 'payment-token',
                'billing_data' => ['email' => 'test@example.com'],
            ], 201),
            'https://accept.paymob.com/api/acceptance/payments/pay' => Http::response([
                'bill_reference' => 'FWRY-123456',
            ], 200),
        ]);

        $service = new PaymobService('api-key', 'public-key', 'secret-key', 'hmac', 123456, 654321, 'iframe-id');

        $result = $service->createTopUpPayment(5500, ['email' => 'test@example.com'], 'fawry');

        $this->assertSame('FWRY-123456', $result['fawry_code']);
        $this->assertSame('FWRY-123456', $result['payment_reference']);
        $this->assertSame('FWRY-123456', $result['fawry_reference']);
    }
}
