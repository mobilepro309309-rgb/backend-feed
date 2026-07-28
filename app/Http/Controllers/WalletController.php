<?php

namespace App\Http\Controllers;

use App\Models\WalletTransaction;
use App\Services\PaymobService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class WalletController extends Controller
{
    public function topUp(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => ['required', 'numeric', 'min:1'],
            'billing_data' => ['required', 'array'],
        ]);

            if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();
        if (! $user) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized',
            ], 401);
        }

        $data = $validator->validated();
        $amountCents = (int) round($data['amount'] * 100);
        $paymentMethod = isset($data['payment_method']) && is_string($data['payment_method'])
            ? strtolower(trim($data['payment_method']))
            : (isset($data['gateway']) && is_string($data['gateway']) ? strtolower(trim($data['gateway'])) : 'fawry');
        $billingData = $data['billing_data'];

        try {
            $wallet = $user->wallet;
            if (! $wallet) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Wallet not found for user.'],
                404);
            }

            $transaction = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'type' => 'deposit',
                'amount' => $data['amount'],
                'status' => 'pending',
                'reference_id' => null,
            ]);

            $paymobService = PaymobService::fromConfig();
            $paymentResult = $paymobService->createTopUpPayment($amountCents, $billingData, $paymentMethod, $user);

            $transaction->update([
                'order_id' => $paymentResult['order_id'],
            ]);

            $paymentUrl = $paymentResult['payment_url'] ?? null;
            if (empty($paymentUrl) && ! empty($paymentResult['payment_token'])) {
                $integrationId = $paymentResult['integration_id'] ?? 5806858;
                $paymentUrl = sprintf(
                    'https://accept.paymob.com/standalone?integration_id=%s&client_secret=%s',
                    rawurlencode((string) $integrationId),
                    rawurlencode((string) $paymentResult['payment_token']),
                );
            }

            $referenceValue = $paymentResult['payment_reference'] ?? $paymentResult['fawry_reference'] ?? $paymentResult['fawry_code'] ?? null;
            $isReferenceFlow = in_array($paymentMethod, ['fawry', 'kiosk', 'cash'], true);

            return response()->json([
                'status' => 'success',
                'transaction_id' => $transaction->id,
                'payment_token' => $paymentResult['payment_token'],
                'payment_method' => $paymentResult['payment_method'] ?? $paymentMethod,
                'source' => $paymentResult['source'] ?? null,
                'payment_key_response' => $paymentResult['payment_key_response'],
                'payment_url' => $isReferenceFlow ? null : $paymentUrl,
                'redirect_url' => $isReferenceFlow ? null : $paymentUrl,
                'payment_iframe_url' => $isReferenceFlow ? null : $paymentUrl,
                'iframe_id' => $paymentResult['iframe_id'] ?? null,
                'payment_reference' => $referenceValue,
                'fawry_code' => $referenceValue,
                'order_reference' => $paymentResult['order_reference'] ?? null,
                'fawry_reference' => $referenceValue,
            ]);
        } catch (RuntimeException $exception) {
            Log::error('Wallet top-up failed.', [
                'user_id' => optional($user)->id,
                'exception' => $exception->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage(),
            ], 500);
        }
    }

    public function webhook(Request $request)
    {
        $signature = $request->header('X-Callback-Signature') ?? $request->header('X-Paymob-Signature');
        $payload = $request->getContent();

        if (! $this->isValidPaymobSignature($payload, $signature)) {
            Log::warning('Invalid Paymob webhook signature.', [
                'signature' => $signature,
                'payload' => $payload,
            ]);

            return response()->json(['status' => 'error', 'message' => 'Invalid signature.'], 403);
        }

        $data = json_decode($payload, true);

        if (! is_array($data)) {
            return response()->json(['status' => 'error', 'message' => 'Invalid payload.'], 400);
        }

        $success = $data['success'] ?? false;
        $statusValue = strtolower((string) ($data['status'] ?? $data['data']['status'] ?? ''));
        $acceptedStatuses = ['success', 'completed', 'paid'];

        if (! $success && ! in_array($statusValue, $acceptedStatuses, true)) {
            return response()->json(['status' => 'ignored']);
        }

        $orderId = $data['data']['order']['id'] ?? $data['data']['order_id'] ?? $data['order_id'] ?? null;

        if (! $orderId) {
            return response()->json(['status' => 'error', 'message' => 'Missing order_id.'], 400);
        }

        $transaction = WalletTransaction::where('order_id', $orderId)->first();

        if (! $transaction) {
            Log::warning('Paymob webhook received for unknown order.', [
                'order_id' => $orderId,
                'payload' => $data,
            ]);

            return response()->json(['status' => 'error', 'message' => 'Transaction not found.'], 404);
        }

        if ($transaction->status !== 'completed') {
            $transaction->update([
                'status' => 'completed',
                'reference_id' => $data['id'] ?? $data['data']['id'] ?? $transaction->reference_id,
            ]);

            $transaction->wallet->increment('balance', $transaction->amount);
        }

        return response()->json(['status' => 'success']);
    }

    protected function isValidPaymobSignature(string $payload, ?string $signature): bool
    {
        if (empty($signature)) {
            return false;
        }

        $hmacKey = config('services.paymob.hmac', '');
        if (empty($hmacKey)) {
            return false;
        }

        $computed = hash_hmac('sha512', $payload, $hmacKey);

        return hash_equals($computed, $signature);
    }
}

