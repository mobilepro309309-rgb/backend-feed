<?php

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\FiltersQuestionListings;
use App\Models\User;
use App\Models\Location\{District, Governorate};

class AdminUsersController extends Controller
{
    use FiltersQuestionListings;

    public function index(Request $request)
    {
        $gradeValue = $this->resolveGradeFilter(
            $request->query('grade_id', $request->query('stage', $request->query('school_grade')))
        );

        $genderValue = $this->resolveGenderFilter(
            $request->query('gender')
        );

        $governorateId = $request->query('governorate_id');
        $districtId = $request->query('district_id');

        $query = User::query()
            ->where('role', 'user')
            ->select(['id', 'name', 'email', 'phone', 'password', 'gender', 'school_grade', 'role', 'created_at', 'id as user_id']);

        if ($gradeValue !== null) {
            $query->whereRaw(
                $this->getGradeNormalizationClause('school_grade', $gradeValue)
            );
        }

        if ($genderValue !== null) {
            $query->where('gender', $genderValue);
        }

        if ($governorateId) {
            $query->join('user_addresses', 'users.id', '=', 'user_addresses.user_id')
                  ->where('user_addresses.governorate', $governorateId);
        }

        if ($districtId) {
            if (!$governorateId) {
                $query->join('user_addresses', 'users.id', '=', 'user_addresses.user_id');
            }
            $query->where('user_addresses.city_or_center', $districtId);
        }

        $users = $query
            ->with('address')
            ->orderByDesc('created_at')
            ->limit((int) $request->query('limit', 50))
            ->get()
            ->map(function (User $userData) {
                $address = $userData->address;
                return [
                    'id' => $userData->id,
                    'name' => $userData->name,
                    'email' => $userData->email,
                    'phone' => $userData->phone,
                    'password' => $userData->password,
                    'gender' => $userData->gender,
                    'school_grade' => $userData->school_grade,
                    'role' => $userData->role,
                    'created_at' => $userData->created_at?->toISOString(),
                    'address' => [
                        'governorate' => $address?->governorate ?? 'غير محدد',
                        'city_or_center' => $address?->city_or_center ?? 'غير محدد',
                        'village_name' => $address?->village_name ?? 'غير محدد',
                    ],
                ];
            });

        return response()->json([
            'message' => 'تم جلب المستخدمين بنجاح',
            'filter' => [
                'grade_id' => $gradeValue,
                'stage' => $request->query('stage', $request->query('grade_id')),
                'gender' => $genderValue,
                'governorate_id' => $governorateId,
                'district_id' => $districtId,
                'label' => $this->formatStageLabel($gradeValue),
            ],
            'total' => $users->count(),
            'users' => $users,
        ]);
    }

    protected function resolveGenderFilter(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = trim((string) $value);
        $lower = strtolower($normalized);

        if (in_array($lower, ['female', 'girl', 'بنات', 'بنت', 'انثى', 'أنثى'], true)) {
            return 'بنت';
        }

        if (in_array($lower, ['male', 'boy', 'أولاد', 'ولد', 'ذكر'], true)) {
            return 'ولد';
        }

        return $normalized;
    }

    protected function resolveGradeFilter(mixed $value): ?string
    {
        if ($value === null || $value === '' || strtolower((string) $value) === 'all' || trim((string) $value) === 'كل المراحل') {
            return null;
        }

        $rawValue = trim((string) $value);

        if (is_numeric($rawValue)) {
            return (string) $rawValue;
        }

        $normalized = User::normalizeSchoolGradeValue($rawValue);

        return $normalized !== null && $normalized !== '' ? (string) $normalized : null;
    }

    protected function formatStageLabel(?string $gradeValue): string
    {
        if ($gradeValue === null || $gradeValue === '') {
            return 'كل المراحل';
        }

        $map = [
            '1' => 'الأول الإعدادي',
            '2' => 'الثاني الإعدادي',
            '3' => 'الثالث الإعدادي',
            '4' => 'الأول الثانوي',
            '5' => 'الثاني الثانوي',
            '6' => 'الثالث الثانوي',
            '7' => 'السابع',
            '8' => 'الثامن',
            '9' => 'التاسع',
            '10' => 'العاشر',
            '11' => 'الحادي عشر',
            '12' => 'الثاني عشر',
        ];

        return $map[$gradeValue] ?? "المرحلة {$gradeValue}";
    }

    protected function getGradeNormalizationClause(string $column, string $normalizedGrade): string
    {
        return "
            CASE 
                WHEN $column IS NULL THEN ''
                WHEN LOWER($column) IN ('اول', 'اولى', 'اولي', 'اولى اعدادي', 'اول اعدادي', '1') THEN '1'
                WHEN LOWER($column) IN ('ثاني', 'ثانية', 'ثانى', 'ثانيه', 'ثاني اعدادي', 'ثانى اعدادي', '2') THEN '2'
                WHEN LOWER($column) IN ('ثالث', 'ثالثة', 'ثالثه', 'ثالث اعدادي', 'ثالثة اعدادي', '3') THEN '3'
                WHEN LOWER($column) IN ('رابع', 'رابعة', 'رابع ثانوي', 'اول ثانوي', '4') THEN '4'
                WHEN LOWER($column) IN ('خامس', 'خامسة', 'ثاني ثانوي', '5') THEN '5'
                WHEN LOWER($column) IN ('سادس', 'سادسة', 'ثالث ثانوي', '6') THEN '6'
                WHEN LOWER($column) IN ('سابع', 'سابعة', '7') THEN '7'
                WHEN LOWER($column) IN ('ثامن', 'ثامنة', '8') THEN '8'
                WHEN LOWER($column) IN ('تاسع', 'تاسعة', '9') THEN '9'
                WHEN LOWER($column) IN ('عاشر', 'عاشرة', '10') THEN '10'
                WHEN LOWER($column) IN ('حادي عشر', 'حادية عشرة', '11') THEN '11'
                WHEN LOWER($column) IN ('ثاني عشر', 'ثانية عشرة', '12') THEN '12'
                ELSE LOWER($column)
            END = '$normalizedGrade'
        ";
    }

    public function update(Request $request, User $user)
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'phone' => ['sometimes', 'nullable', 'string', 'max:25', 'unique:users,phone,' . $user->id],
            'password' => ['sometimes', 'nullable', 'string', 'min:6', 'max:255'],
            'gender' => ['sometimes', 'nullable', 'in:ولد,بنت'],
            'school_grade' => ['sometimes', 'nullable', 'string', 'max:50'],
            'role' => ['sometimes', 'nullable', 'in:user,teacher,admin'],
        ]);

        if (array_key_exists('password', $validated) && $validated['password'] !== null && $validated['password'] !== '') {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->fill($validated);
        $user->save();
        $user->loadMissing('address');

        return response()->json([
            'message' => 'تم تحديث المستخدم بنجاح',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'gender' => $user->gender,
                'school_grade' => $user->school_grade,
                'role' => $user->role,
                'address' => $user->address ? [
                    'governorate' => $user->address->governorate,
                    'city_or_center' => $user->address->city_or_center,
                    'village_name' => $user->address->village_name,
                ] : null,
            ],
        ]);
    }

    public function destroy(Request $request, User $user)
    {
        if ((int) $request->user()->id === (int) $user->id) {
            return response()->json([
                'message' => 'لا يمكنك حذف حسابك الشخصي من هذه الصفحة.',
            ], 422);
        }

        if ($user->address) {
            $user->address()->delete();
        }

        if ($user->wallet) {
            $user->wallet()->delete();
        }

        $user->delete();

        return response()->json([
            'message' => 'تم حذف المستخدم بنجاح',
            'deleted_user_id' => $user->id,
        ]);
    }

    public function getLocationFilters()
    {
        try {
            \Log::info('🔵 [getLocationFilters] Starting...');
            \Log::info('🔵 [getLocationFilters] Governorate Model:', ['model' => Governorate::class]);
            \Log::info('🔵 [getLocationFilters] District Model:', ['model' => District::class]);
            
            // Check if tables have data
            $govCount = Governorate::count();
            $distCount = District::count();
            
            \Log::info('📊 [getLocationFilters] Database check:', [
                'governorates_count' => $govCount,
                'districts_count' => $distCount,
            ]);

            // Get all governorates first
            $allGovernorateRecords = Governorate::all();
            \Log::info('🔍 [getLocationFilters] All Governorate records:', [
                'count' => $allGovernorateRecords->count(),
                'sample' => $allGovernorateRecords->first()?->toArray(),
            ]);

            $governorates = Governorate::select(['id', 'name_ar', 'name_en'])
                ->orderBy('name_ar')
                ->get();

            \Log::info('📍 [getLocationFilters] Governorates after query:', [
                'count' => $governorates->count(),
                'data' => $governorates->map(fn($g) => ['id' => $g->id, 'name_ar' => $g->name_ar, 'name_en' => $g->name_en])->toArray(),
            ]);

            $governoratesArray = $governorates->map(function ($item) {
                $name = $item->name_ar ?: $item->name_en;

                return [
                    'id' => (int) $item->id,
                    'name' => $name,
                    'label' => $name,
                ];
            })->values()->toArray();

            // Get all districts first
            $allDistrictRecords = District::all();
            \Log::info('🔍 [getLocationFilters] All District records:', [
                'count' => $allDistrictRecords->count(),
                'sample' => $allDistrictRecords->first()?->toArray(),
            ]);

            $districts = District::select(['id', 'governorate_id', 'name_ar', 'name_en'])
                ->orderBy('governorate_id')
                ->orderBy('name_ar')
                ->get();

            \Log::info('🏙️ [getLocationFilters] Districts after query:', [
                'count' => $districts->count(),
                'data' => $districts->map(fn($d) => ['id' => $d->id, 'governorate_id' => $d->governorate_id, 'name_ar' => $d->name_ar, 'name_en' => $d->name_en])->toArray(),
            ]);

            $districtsArray = $districts->map(function ($item) {
                $name = $item->name_ar ?: $item->name_en;

                return [
                    'id' => (int) $item->id,
                    'governorate_id' => (int) $item->governorate_id,
                    'name' => $name,
                    'label' => $name,
                ];
            })->values()->toArray();

            $response = [
                'governorates' => $governoratesArray,
                'districts' => $districtsArray,
                'debug' => [
                    'gov_count' => count($governoratesArray),
                    'dist_count' => count($districtsArray),
                    'total_gov_in_db' => $govCount,
                    'total_dist_in_db' => $distCount,
                ],
            ];

            \Log::info('✅ [getLocationFilters] Sending response:', $response);

            return response()->json($response);
        } catch (\Exception $e) {
            \Log::error('❌ [getLocationFilters] Error:', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'governorates' => [],
                'districts' => [],
                'error' => 'Failed to fetch location filters',
                'exception' => $e->getMessage(),
            ], 500);
        }
    }
}
