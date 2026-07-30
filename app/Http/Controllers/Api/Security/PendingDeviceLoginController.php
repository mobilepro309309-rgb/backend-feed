<?php

namespace App\Http\Controllers\Api\Security;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\PendingDeviceLoginResponseRequest;
use App\Models\PendingDeviceLogin;
use App\Models\UserDevice;
use App\Models\User;
use App\Services\FirebaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PendingDeviceLoginController extends Controller
{
    public function respond(Request $request): JsonResponse
    {
        $validated = $request->validate((new PendingDeviceLoginResponseRequest())->rules());
        $user = $request->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $pending = PendingDeviceLogin::where('id', $validated['pending_id'])
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if (! $pending) {
            return response()->json(['success' => false, 'message' => 'Pending login request not found or expired.'], 404);
        }

        $result = DB::transaction(function () use ($pending, $validated) {
            $newDevice = UserDevice::find($pending->target_device_id);
            if (! $newDevice) {
                $pending->update(['status' => 'revoked']);
                return ['status' => 'revoked'];
            }

            if ($validated['action'] === 'approve') {
                $pending->update(['status' => 'approved']);
                $newDevice->update(['trusted' => true]);

                $tokenResult = $newDevice->user->createToken('auth_token');
                $plainToken = $tokenResult->plainTextToken;
                $newDevice->update(['access_token_id' => $tokenResult->accessToken->id ?? null]);
                $pending->update(['auth_token' => $plainToken]);

                return ['status' => 'approved', 'token' => $plainToken];
            }

            if ($validated['action'] === 'revoke') {
                $pending->update(['status' => 'revoked']);

                $accessTokenId = $newDevice->access_token_id;
                if ($accessTokenId) {
                    DB::table('personal_access_tokens')
                        ->where('id', $accessTokenId)
                        ->delete();
                }

                $newDevice->delete();
                return ['status' => 'revoked'];
            }

            return ['status' => 'revoked'];
        });

        return response()->json([
            'success' => true,
            'message' => 'Pending login request processed.',
            'status' => $result['status'],
            'token' => $result['token'] ?? null,
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $pendingId = $request->query('pending_id');
        $deviceToken = $request->query('fcm_token');
        $deviceIdentifier = $request->query('device_identifier');

        if (! $pendingId) {
            return response()->json(['success' => false, 'message' => 'pending_id is required.'], 422);
        }

        $pendingQuery = PendingDeviceLogin::where('id', $pendingId);

        if ($user) {
            $pendingQuery->where('user_id', $user->id);
        } elseif ($deviceToken || $deviceIdentifier) {
            $pendingQuery->whereHas('targetDevice', function ($query) use ($deviceToken, $deviceIdentifier) {
                if ($deviceToken) {
                    $query->where('fcm_token', $deviceToken);
                }
                if ($deviceIdentifier) {
                    $query->where('device_identifier', $deviceIdentifier);
                }
            });
        } else {
            // Fallback: allow status checks by pending_id alone when no device identity is available.
            // This supports the new device polling its own pending request during login.
        }

        $pending = $pendingQuery->first();
        if (! $pending) {
            return response()->json(['success' => false, 'message' => 'Pending login request not found.'], 404);
        }

        $response = [
            'success' => true,
            'status' => $pending->status,
            'pending_id' => $pending->id,
        ];

        if ($pending->status === 'approved' && $pending->auth_token) {
            $response['token'] = $pending->auth_token;
            $response['user'] = [
                'id' => $pending->user->id,
                'name' => $pending->user->name,
                'email' => $pending->user->email,
                'phone' => $pending->user->phone,
                'role' => $pending->user->role,
            ];
        }

        return response()->json($response);
    }
}
