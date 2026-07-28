<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class KashierController extends Controller
{
    public function generatePaymentHash(Request $request)
    {
        $validated = $request->validate([
            'orderId' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'max:10'],
        ]);

        $mid = (string) env('KASHIER_MID', '');
        $secretKey = (string) env('KASHIER_SECRET_KEY', '');
        $mode = (string) env('KASHIER_MODE', 'test');

        $orderId = (string) $validated['orderId'];
        $amount = (string) $validated['amount'];
        $currency = strtoupper((string) $validated['currency']);

        $path = '?mid=' . $mid . '&orderId=' . $orderId . '&amount=' . $amount . '&currency=' . $currency;
        $hash = hash_hmac('sha256', $path, $secretKey);

        return response()->json([
            'mid' => $mid,
            'orderId' => $orderId,
            'amount' => $amount,
            'currency' => $currency,
            'hash' => $hash,
            'mode' => $mode,
            'baseURL' => 'https://checkout.kashier.io',
        ]);
    }
}
