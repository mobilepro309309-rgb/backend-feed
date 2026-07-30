<?php

namespace App\Http\Controllers\Api\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class UserProfileController extends Controller
{
    public function show(Request $request)
    {
        $profile = $this->getOrCreateProfile($request->user());

        return response()->json([
            'message' => 'تم جلب الملف الشخصي بنجاح',
            'profile' => $this->serializeProfile($profile),
        ]);
    }

    public function update(Request $request)
    {
        Log::info('UserProfile update request received', [
            'user_id' => $request->user()?->id,
            'has_avatar_url' => $request->has('avatar_url'),
            'avatar_url' => $request->input('avatar_url'),
            'has_avatar_file' => $request->hasFile('avatar'),
            'theme_mode' => $request->input('theme_mode'),
            'settings' => $request->input('settings'),
        ]);

        $validated = $request->validate([
            'avatar_url' => ['nullable', 'string', 'max:2048'],
            'avatar' => ['nullable', 'file', 'image', 'max:4096'],
            'theme_mode' => ['nullable', 'in:light,dark,system'],
            'settings' => ['nullable', 'array'],
        ]);

        $profile = $this->getOrCreateProfile($request->user());
        $data = [];

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('avatars', 'public');
            $data['avatar_url'] = Storage::url($path);
        } elseif ($request->has('avatar_url')) {
            $data['avatar_url'] = $validated['avatar_url'];
        }

        if ($request->has('theme_mode')) {
            $data['theme_mode'] = $validated['theme_mode'];
        }

        if ($request->has('settings')) {
            $data['settings'] = $validated['settings'];
        }

        Log::info('UserProfile update payload prepared', [
            'user_id' => $profile->user_id,
            'data' => $data,
        ]);

        if (! empty($data)) {
            $profile->fill($data);
            $profile->save();
        }

        if (isset($data['theme_mode'])) {
            $request->user()->forceFill(['theme_mode' => $data['theme_mode']])->save();
        }

        return response()->json([
            'message' => 'تم تحديث الملف الشخصي بنجاح',
            'profile' => $this->serializeProfile($profile->fresh()),
        ]);
    }

    protected function getOrCreateProfile(User $user): UserProfile
    {
        return $user->profile()->firstOrCreate([], [
            'theme_mode' => 'light',
            'settings' => [],
        ]);
    }

    protected function serializeProfile(UserProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'user_id' => $profile->user_id,
            'avatar_url' => $profile->avatar_url,
            'theme_mode' => $profile->theme_mode ?? 'light',
            'settings' => $profile->settings ?? [],
            'created_at' => $profile->created_at?->toISOString(),
            'updated_at' => $profile->updated_at?->toISOString(),
        ];
    }
}
