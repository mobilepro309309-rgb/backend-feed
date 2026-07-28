<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class PaymobService
{
    public function __construct(
        protected string $apiKey,
        protected string $publicKey,
        protected string $secretKey,
        protected string $hmac,
        protected ?int $integrationId = null,
        protected ?int $cashIntegrationId = null,
        protected ?string $iframeId = null,
    ) {
        if (empty($this->apiKey) || empty($this->publicKey) || empty($this->secretKey) || empty($this->hmac)) {
            throw new RuntimeException('Paymob service is not configured properly. Please set PAYMOB_API_KEY, PAYMOB_PUBLIC_KEY, PAYMOB_SECRET_KEY, and PAYMOB_HMAC.');
        }

        if ($this->integrationId === null && $this->cashIntegrationId === null) {
            throw new RuntimeException('Paymob integration ID is not configured. Please set PAYMOB_INTEGRATION_ID or PAYMOB_CASH_INTEGRATION_ID in your environment.');
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            (string) config('services.paymob.api_key', ''),
            (string) config('services.paymob.public_key', ''),
            (string) config('services.paymob.secret_key', ''),
            (string) config('services.paymob.hmac', ''),
            config('services.paymob.integration_id') !== null ? (int) config('services.paymob.integration_id') : null,
            config('services.paymob.cash_integration_id') !== null ? (int) config('services.paymob.cash_integration_id') : null,
            config('services.paymob.iframe_id') ?: null,
        );
    }

    public function getAuthToken(): string
    {
        $response = Http::timeout(15)
            ->post('https://accept.paymob.com/api/auth/tokens', [
                'api_key' => $this->apiKey,
            ]);

        $payload = $response->json();

        if (! $response->successful() || empty($payload['token'])) {
            Log::error('Paymob auth token request failed.', [
                'status' => $response->status(),
                'body' => $payload,
            ]);

            throw new RuntimeException('Failed to retrieve Paymob auth token.');
        }

        return (string) $payload['token'];
    }

    public function registerOrder(int $amountCents, string $currency = 'EGP', array $items = [], string $paymentMethod = 'fawry'): array
    {
        $authToken = $this->getAuthToken();
        $payload = [
            'auth_token' => $authToken,
            'delivery_needed' => false,
            'amount_cents' => $amountCents,
            'currency' => $currency,
            // include merchant_order_id to help with cash/kiosk flows and tracking
            'merchant_order_id' => 'wallet_topup_' . time(),
            'items' => $items,
        ];

        $endpoint = $this->resolveOrderEndpoint($paymentMethod);
        Log::info('Paymob register order endpoint', ['endpoint' => $endpoint, 'payment_method' => $paymentMethod]);

        $response = Http::timeout(15)
            ->post($endpoint, $payload);

        $body = $response->json();
        $rawBody = $response->body();

        Log::info('Paymob register order response', [
            'status' => $response->status(),
            'endpoint' => $endpoint,
            'payment_method' => $paymentMethod,
            'payload' => $payload,
            'order_response' => $body,
            'order_raw_body' => $rawBody,
        ]);

        if (! $response->successful() || empty($body['id'])) {
            Log::error('Paymob order registration failed.', [
                'status' => $response->status(),
                'body' => $body,
                'raw_body' => $rawBody,
                'payload' => $payload,
            ]);

            throw new RuntimeException('Failed to register Paymob order.');
        }

        $orderReference = $this->extractPaymentReference($body);

        return [
            'order_id' => (int) $body['id'],
            'merchant_order_id' => $body['merchant_order_id'] ?? null,
            'auth_token' => $authToken,
            'order_reference' => $orderReference,
            'order_response' => $body,
        ];
    }

    public function requestPaymentKey(
        int $amountCents,
        int $orderId,
        array $billingData,
        string $currency = 'EGP',
        string $paymentMethod = 'fawry',
        int $expiration = 3600,
        $user = null,
    ): array {
        $authToken = $this->getAuthToken();

        // ensure billing data contains required fields (prefer user data, then request data, then defaults)
        $billingData = $this->sanitizeBillingData($billingData, $user);

        $integrationId = $this->resolveIntegrationId($paymentMethod);
        $sourceIdentifier = $this->resolveSourceIdentifier($paymentMethod);

        $payload = [
            'auth_token' => $authToken,
            'amount_cents' => $amountCents,
            'expiration' => $expiration,
            'order_id' => $orderId,
            'billing_data' => $billingData,
            'currency' => $currency,
            'integration_id' => $integrationId,
            'lock_order_when_paid' => false,
            'source' => [
                'identifier' => $sourceIdentifier,
            ],
        ];

        $response = Http::timeout(15)
            ->post('https://accept.paymob.com/api/acceptance/payment_keys', $payload);

        $body = $response->json();
        $rawBody = $response->body();

        Log::info('Paymob payment key response', [
            'status' => $response->status(),
            'integration_id' => $integrationId,
            'source_identifier' => $sourceIdentifier,
            'payment_key_payload' => $payload,
            'payment_key_response' => $body,
            'payment_key_raw_body' => $rawBody,
        ]);

        if (! $response->successful() || empty($body['token'])) {
            Log::error('Paymob payment key request failed.', [
                'status' => $response->status(),
                'body' => $body,
                'raw_body' => $rawBody,
                'payload' => $payload,
            ]);

            throw new RuntimeException('Failed to request Paymob payment key.');
        }

        $paymentReference = $this->extractPaymentReference($body);
        $resolvedReference = $paymentReference;

        if ($resolvedReference === null && in_array($paymentMethod, ['fawry', 'kiosk', 'cash'], true)) {
            $resolvedReference = $this->fetchBillReference($authToken, $orderId, $amountCents, $body, $paymentMethod);
        }

        return [
            'payment_token' => (string) $body['token'],
            'payment_key_response' => $body,
            'integration_id' => $integrationId,
            'source' => $sourceIdentifier,
            'payment_method' => $paymentMethod,
            'fawry_reference' => $resolvedReference,
            'payment_reference' => $resolvedReference,
            'fawry_code' => $resolvedReference,
            'bill_reference' => $resolvedReference,
            'payment_url' => null,
            'redirect_url' => null,
            'order_url' => null,
        ];
    }

    protected function sanitizeBillingData(array $billingData, $user = null): array
    {
        // prefer values from $user when available
        $userData = [];
        if ($user) {
            // user can be model or array
            $userData['email'] = $user->email ?? ($user['email'] ?? null);
            $userData['phone'] = $user->phone ?? ($user['phone'] ?? null);
            $name = $user->name ?? ($user['full_name'] ?? null) ?? null;
            if ($name) {
                $parts = preg_split('/\s+/', trim($name), 2);
                $userData['first_name'] = $parts[0] ?? null;
                $userData['last_name'] = $parts[1] ?? null;
            } else {
                $userData['first_name'] = $user->first_name ?? ($user['first_name'] ?? null);
                $userData['last_name'] = $user->last_name ?? ($user['last_name'] ?? null);
            }
        }

        $defaults = [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'test@example.com',
            'phone_number' => '01205613340',
            'phone' => '01205613340',
            'street' => '123 Street',
            'building' => '1',
            'floor' => '1',
            'apartment' => '1',
            'city' => 'Cairo',
            'country' => 'EG',
        ];

        // merge priority: existing billingData -> userData -> defaults
        foreach ($defaults as $key => $value) {
            if (isset($billingData[$key]) && $billingData[$key] !== null && $billingData[$key] !== '') {
                continue;
            }

            if (isset($userData[$key]) && $userData[$key] !== null && $userData[$key] !== '') {
                $billingData[$key] = $userData[$key];
                continue;
            }

            $billingData[$key] = $value;
        }

        // ensure both phone and phone_number exist (some Paymob flows expect phone_number)
        if (! isset($billingData['phone_number']) && isset($billingData['phone'])) {
            $billingData['phone_number'] = $billingData['phone'];
        }
        if (! isset($billingData['phone']) && isset($billingData['phone_number'])) {
            $billingData['phone'] = $billingData['phone_number'];
        }

        return $billingData;
    }

    protected function extractPaymentReference(array $response): ?string
    {
        $candidateKeys = [
            'kiosk_cpp',
            'kiosk_reference',
            'kiosk_reference_number',
            'kiosk_code',
            'fawry_ref_number',
            'fawry_reference',
            'bill_reference',
            'bill_reference_number',
            'transaction_reference',
            'payment_reference',
            'reference',
            'ref_number',
            'code',
            'order_reference',
            'order_url',
        ];

        $value = $this->searchArrayForKeys($response, $candidateKeys);
        if ($value !== null) {
            $normalized = $this->normalizeReferenceValue($value);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        $patternValue = $this->searchArrayForKeyPattern($response, '/(reference|ref|code|bill|cpp)/i');
        if ($patternValue !== null) {
            $normalized = $this->normalizeReferenceValue($patternValue);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    protected function fetchBillReference(string $authToken, int $orderId, int $amountCents, array $paymentKeyResponse, string $paymentMethod = 'fawry'): ?string
    {
        if ($paymentMethod !== 'fawry') {
            return null;
        }

        $paymentToken = $paymentKeyResponse['token'] ?? $paymentKeyResponse['payment_token'] ?? null;
        if (empty($paymentToken)) {
            return null;
        }

        try {
            $response = Http::timeout(15)
                ->asJson()
                ->post('https://accept.paymob.com/api/acceptance/payments/pay', [
                    'source' => [
                        'identifier' => 'kiosk',
                        'subtype' => 'AGGREGATOR',
                    ],
                    'payment_token' => $paymentToken,
                ]);

            $body = $response->json();
            if (! is_array($body)) {
                return null;
            }

            Log::info('Paymob bill reference lookup attempt', [
                'url' => 'https://accept.paymob.com/api/acceptance/payments/pay',
                'status' => $response->status(),
                'payload' => [
                    'source' => [
                        'identifier' => 'kiosk',
                        'subtype' => 'AGGREGATOR',
                    ],
                    'payment_token' => $paymentToken,
                ],
                'body' => $body,
            ]);

            return $this->extractPaymentReference($body);
        } catch (\Throwable $exception) {
            Log::warning('Paymob bill reference lookup failed.', [
                'url' => 'https://accept.paymob.com/api/acceptance/payments/pay',
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    protected function normalizeReferenceValue($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return null;
        }

        if (is_bool($value)) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^(https?:\/\/|mailto:|jwt:)/i', $text)) {
            return null;
        }

        if (str_contains($text, '.')) {
            return null;
        }

        if (strlen($text) > 80) {
            return null;
        }

        if (in_array(strtolower($text), ['true', 'false'], true)) {
            return null;
        }

        if (! preg_match('/[A-Za-z0-9]/', $text)) {
            return null;
        }

        return $text;
    }

    protected function searchArrayForKeys(array $data, array $keys)
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower($key), array_map('strtolower', $keys), true) && ! empty($value)) {
                return $value;
            }

            if (is_array($value)) {
                $match = $this->searchArrayForKeys($value, $keys);
                if ($match !== null) {
                    return $match;
                }
            }
        }

        return null;
    }

    protected function searchArrayForKeyPattern(array $data, string $pattern)
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match($pattern, $key) && ! empty($value)) {
                return $value;
            }

            if (is_array($value)) {
                $match = $this->searchArrayForKeyPattern($value, $pattern);
                if ($match !== null) {
                    return $match;
                }
            }
        }

        return null;
    }

    public function createTopUpPayment(int $amountCents, array $billingData, string $paymentMethod = 'card', $user = null): array
    {
        $order = $this->registerOrder($amountCents, 'EGP', [
            [
                'name' => 'Wallet Top Up',
                'amount_cents' => $amountCents,
                'description' => 'Wallet top-up transaction',
                'quantity' => 1,
            ],
        ], $paymentMethod);

        $paymentKey = $this->requestPaymentKey(
            $amountCents,
            $order['order_id'],
            $billingData,
            'EGP',
            $paymentMethod,
            3600,
            $user,
        );

        $fawryReference = $paymentKey['fawry_reference'] ?? $order['order_reference'] ?? null;
        $paymentToken = $paymentKey['payment_token'] ?? null;
        $paymentUrl = null;

        if (! empty($paymentToken)) {
            $integrationId = $paymentKey['integration_id'] ?? $this->resolveIntegrationId($paymentMethod);
            $paymentUrl = sprintf(
                'https://accept.paymob.com/standalone?integration_id=%s&client_secret=%s',
                rawurlencode((string) $integrationId),
                rawurlencode((string) $paymentToken),
            );
        }

        $response = array_merge($paymentKey, [
            'order_id' => $order['order_id'],
            'order_reference' => $order['order_reference'] ?? null,
            'payment_url' => $paymentUrl,
            'redirect_url' => $paymentUrl,
            'payment_iframe_url' => $paymentUrl,
            'iframe_id' => $this->iframeId,
            'order_response' => $order['order_response'] ?? null,
            'payment_reference' => $fawryReference,
            'fawry_reference' => $fawryReference,
            'fawry_code' => $fawryReference,
            'bill_reference' => $fawryReference,
        ]);

        unset($response['order_url']);

        return $response;
    }


    protected function resolveDirectPaymentUrl(array $order, array $paymentKey, string $paymentMethod): ?string
    {
        $paymentToken = $paymentKey['payment_token'] ?? null;
        if (empty($paymentToken)) {
            return null;
        }

        if (! in_array($paymentMethod, ['fawry', 'kiosk'], true)) {
            return null;
        }

        $integrationId = $paymentKey['integration_id'] ?? $this->resolveIntegrationId($paymentMethod);

        return sprintf(
            'https://accept.paymob.com/standalone?integration_id=%s&client_secret=%s',
            rawurlencode((string) $integrationId),
            rawurlencode((string) $paymentToken),
        );
    }

    protected function resolveOrderEndpoint(string $paymentMethod): string
    {
        return 'https://accept.paymob.com/api/ecommerce/orders';
    }

    protected function resolveIntegrationId(string $paymentMethod): int
    {
        $normalizedMethod = strtolower($paymentMethod);

        if (in_array($normalizedMethod, ['fawry', 'kiosk', 'cash'], true)) {
            return $this->cashIntegrationId ?? $this->integrationId ?? 5806858;
        }

        return $this->integrationId ?? $this->cashIntegrationId ?? 5806858;
    }

    protected function resolveSourceIdentifier(string $paymentMethod): string
    {
        $normalizedMethod = strtolower($paymentMethod);

        if (in_array($normalizedMethod, ['fawry', 'kiosk', 'cash'], true)) {
            return 'kiosk';
        }

        return 'card';
    }
}
