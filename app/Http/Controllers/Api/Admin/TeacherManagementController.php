<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TeacherScope;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeacherManagementController extends Controller
{
    public function lookupUser($id)
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'عذراً، هذا الرقم التعريفي (ID) غير مسجل بالنظام',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
            ],
        ]);
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = User::query()
            ->where(function ($q) use ($search) {
                $q->where('role', 'teacher')
                    ->orWhereHas('teacherScopes');

                if ($search !== '') {
                    $q->where(function ($inner) use ($search) {
                        $inner->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%" );
                    });
                }
            })
            ->with('teacherScopes');

        $teachers = $query->orderBy('name')->get();

        return response()->json([
            'status' => 'success',
            'data' => $teachers->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'role' => $user->role,
                    'teacher_scopes' => $user->teacherScopes->map(function (TeacherScope $scope) {
                        return [
                            'id' => $scope->id,
                            'school_grade' => $scope->school_grade,
                            'subject' => $scope->subject,
                            'can_answer' => (bool) $scope->can_answer,
                            'can_create_questions' => (bool) $scope->can_create_questions,
                        ];
                    }),
                ];
            }),
        ]);
    }

    public function assignScope(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'school_grade' => 'required|string',
            'subject' => 'required|string',
            'can_answer' => 'nullable|boolean',
            'can_create_questions' => 'nullable|boolean',
        ]);

        $user = User::findOrFail($validated['user_id']);

        if (strtolower((string) $user->role) === 'user') {
            $user->forceFill(['role' => 'teacher'])->save();
        }

        $scope = TeacherScope::updateOrCreate(
            [
                'user_id' => $user->id,
                'school_grade' => (string) $validated['school_grade'],
                'subject' => $validated['subject'],
            ],
            [
                'can_answer' => $validated['can_answer'] ?? true,
                'can_create_questions' => $validated['can_create_questions'] ?? true,
            ]
        );

        return response()->json([
            'status' => 'success',
            'message' => 'تم حفظ صلاحية المدرس بنجاح',
            'data' => [
                'id' => $scope->id,
                'user_id' => $scope->user_id,
                'school_grade' => $scope->school_grade,
                'subject' => $scope->subject,
                'can_answer' => (bool) $scope->can_answer,
                'can_create_questions' => (bool) $scope->can_create_questions,
            ],
        ]);
    }

    public function removeScope($scopeId)
    {
        $scope = TeacherScope::findOrFail($scopeId);
        $user = $scope->user;

        $scope->delete();

        if ($user) {
            $remainingScopes = $user->teacherScopes()->count();
            if ($remainingScopes === 0) {
                $user->forceFill(['role' => 'user'])->save();
            }
        }

        return response()->json([
            'status' => 'success',
            'message' => 'تم حذف صلاحية المادة بنجاح',
        ]);
    }
}
