<?php

namespace App\Http\Middleware;

use App\Models\BannedDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckBannedDevice
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $fcmToken = $request->input('fcm_token')
            ?? $request->header('X-FCM-Token')
            ?? $request->query('fcm_token');

        $deviceIdentifier = $request->input('device_identifier')
            ?? $request->input('device_id')
            ?? null;

        if (
            BannedDevice::isUserBanned((int) $user->id)
            || BannedDevice::isDeviceBanned((string) $fcmToken)
            || BannedDevice::isDeviceBanned((string) $deviceIdentifier)
        ) {
            return response()->json([
                'message' => 'عذراً، هذا الجهاز محظور من استخدام المنصة',
                'status' => 'device_banned',
            ], 403);
        }

        return $next($request);
    }
}
