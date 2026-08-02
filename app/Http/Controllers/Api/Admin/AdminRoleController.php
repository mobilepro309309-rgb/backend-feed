<?php

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

use App\Http\Controllers\Controller;
use App\Models\User;

class AdminRoleController extends Controller
{
    protected array $roleMap = [
        'ادمن للرد على الأسئلة' => 'reply_questions_admin',
        'ادمن لوضع بوستات الأسئلة' => 'question_post_admin',
        'ادمن للمالية' => 'financial_admin',
        'ادمن للدعم الفني' => 'technical_support_admin',
        'main-admin' => 'main-admin',
        'admin' => 'admin',
        'user' => 'user',
    ];

    protected array $adminRoles = [
        'main-admin',
        'admin',
        'reply_questions_admin',
        'question_post_admin',
        'financial_admin',
        'technical_support_admin',
    ];

    public function index()
    {
        $users = User::whereIn('role', $this->adminRoles)
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $users->map(fn(User $user) => $this->serializeUser($user)),
            'message' => 'تم جلب الإداريين بنجاح',
        ]);
    }

    public function options()
    {
        return response()->json([
            'data' => [
                ['value' => 'reply_questions_admin', 'label' => 'ادمن للرد على الأسئلة'],
                ['value' => 'question_post_admin', 'label' => 'ادمن لوضع بوستات الأسئلة'],
                ['value' => 'financial_admin', 'label' => 'ادمن للمالية'],
                ['value' => 'technical_support_admin', 'label' => 'ادمن للدعم الفني'],
                ['value' => 'main-admin', 'label' => 'Main Admin'],
                ['value' => 'admin', 'label' => 'Admin'],
                ['value' => 'user', 'label' => 'User'],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'role' => ['required', 'string'],
        ]);

        $role = $this->normalizeRole($validated['role']);

        $user = $request->user();

        if (! $user) {
            return response()->json([
                'message' => 'المستخدم غير مصادق عليه',
            ], 401);
        }

        if ($user->role !== 'main-admin') {
            return response()->json([
                'message' => 'فقط المستخدم الرئيسي يمكنه إدارة الصلاحيات',
            ], 403);
        }

        $targetUser = $validated['user_id'] ? User::find($validated['user_id']) : null;

        if (! $targetUser) {
            return response()->json([
                'message' => 'المستخدم الهدف غير موجود',
            ], 404);
        }

        $updated = $targetUser->forceFill([
            'role' => $role,
        ])->save();

        if (! $updated) {
            return response()->json([
                'message' => 'فشل حفظ الدور في قاعدة البيانات',
            ], 500);
        }

        $freshUser = $targetUser->fresh();

        return response()->json([
            'message' => 'تم تحديث صلاحية المستخدم بنجاح',
            'data' => $this->serializeUser($freshUser),
            'debug' => [
                'stored_role' => $freshUser->role,
                'phone' => $freshUser->phone,
                'target_user_id' => $freshUser->id,
            ],
        ]);
    }

    public function update(Request $request, User $user)
    {
        $currentUser = $request->user();

        if (! $currentUser) {
            return response()->json([
                'message' => 'المستخدم غير مصادق عليه',
            ], 401);
        }

        if ($currentUser->role !== 'main-admin') {
            return response()->json([
                'message' => 'فقط المستخدم الرئيسي يمكنه إدارة الصلاحيات',
            ], 403);
        }

        $validated = $request->validate([
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['nullable', 'string', 'min:4'],
            'role' => ['nullable', 'string'],
            'name' => ['nullable', 'string', 'max:255'],
        ]);

        $data = [];

        if (array_key_exists('phone', $validated) && $validated['phone'] !== null) {
            $data['phone'] = $validated['phone'];
        }

        if (array_key_exists('password', $validated) && $validated['password'] !== null) {
            $data['password'] = Hash::make($validated['password']);
        }

        if (array_key_exists('role', $validated) && $validated['role'] !== null) {
            $data['role'] = $this->normalizeRole($validated['role']);
        }

        if (array_key_exists('name', $validated) && $validated['name'] !== null) {
            $data['name'] = $validated['name'];
        }

        $user->update($data);

        return response()->json([
            'message' => 'تم تحديث الإداري بنجاح',
            'data' => $this->serializeUser($user->fresh()),
        ]);
    }

    public function destroy(Request $request, User $user)
    {
        $currentUser = $request->user();

        if (! $currentUser) {
            return response()->json([
                'message' => 'المستخدم غير مصادق عليه',
            ], 401);
        }

        if ($currentUser->role !== 'main-admin') {
            return response()->json([
                'message' => 'فقط المستخدم الرئيسي يمكنه إدارة الصلاحيات',
            ], 403);
        }

        $user->update([
            'role' => 'user',
        ]);

        return response()->json([
            'message' => 'تم إعادة المستخدم إلى الدور user بنجاح',
        ]);
    }

    protected function normalizeRole(string $role): string
    {
        return $this->roleMap[$role] ?? $role;
    }

    protected function resolveUserByPhone(string $phone): ?User
    {
        $normalizedInput = $this->normalizePhone($phone);

        if ($normalizedInput === null) {
            return null;
        }

        $users = User::all();

        foreach ($users as $candidate) {
            $candidatePhone = $candidate->phone;
            if (empty($candidatePhone)) {
                continue;
            }

            if ($this->normalizePhone((string) $candidatePhone) === $normalizedInput) {
                return $candidate;
            }
        }

        return null;
    }

    protected function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (! $digits) {
            return null;
        }

        $digits = ltrim($digits, '0');

        if ($digits === '') {
            $digits = '0';
        }

        return $digits;
    }

    protected function makeUniqueEmail(string $phone): string
    {
        $base = preg_replace('/[^0-9a-zA-Z]/', '', $phone) ?: 'admin';
        $email = strtolower($base) . '@local.admin';

        while (User::where('email', $email)->exists()) {
            $email = strtolower($base) . '-' . Str::random(4) . '@local.admin';
        }

        return $email;
    }

    protected function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'email' => $user->email,
            'role' => $user->role,
            'gender' => $user->gender,
            'school_grade' => $user->school_grade,
            'grade' => $user->school_grade ?? $user->grade ?? $user->grade_level ?? $user->academic_year ?? $user->stage ?? null,
            'role_label' => $this->roleLabel($user->role),
        ];
    }

    protected function roleLabel(string $role): string
    {
        return match ($role) {
            'reply_questions_admin' => 'ادمن للرد على الأسئلة',
            'question_post_admin' => 'ادمن لوضع بوستات الأسئلة',
            'financial_admin' => 'ادمن للمالية',
            'technical_support_admin' => 'ادمن للدعم الفني',
            'main-admin' => 'Main Admin',
            'admin' => 'Admin',
            default => 'User',
        };
    }
}
