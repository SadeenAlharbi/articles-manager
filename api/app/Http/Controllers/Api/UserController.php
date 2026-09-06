<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * إدارة مستخدمي نظام الإدارة — الحسابات والأدوار والصلاحيات الفردية.
 *
 * هؤلاء ليسوا مستخدمي منصّة المعرفة. هذي الشاشة لا تُدير حسابات المنصّة
 * العامة إطلاقاً، ولا يوجد أي مسار هنا يمسّها.
 *
 * لا حذف — تعطيل فقط. البند صريح: لا تُحذف بيانات ولا سجلّات، والحذف
 * يكسر مفاتيح audit_logs ويُفقد الأثر.
 *
 * طبقتا تفويض: الـmiddleware يحرس المسار (هل تملك الصلاحية أصلاً؟)،
 * و UserPolicy تحرس الهدف (هل تعلو على هذا الشخص بعينه؟).
 */
#[Group('المستخدمون', 'حسابات نظام الإدارة وأدوارها وصلاحياتها الفردية.', weight: 2)]
class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /* ------------------------------ القراءة ------------------------------ */

    /**
     * قائمة مستخدمي نظام الإدارة.
     *
     * الصلاحية المطلوبة: `users.manage` (إدارة المستخدمين). الأعلى مستوى أولاً
     * ثم الأحدث، والترتيب يقع في قاعدة البيانات فلا ينكسر مع الترقيم.
     */
    #[QueryParameter('search', 'بحث بالاسم أو البريد الإلكتروني', type: 'string')]
    #[QueryParameter('status', 'تصفية بالحالة: active أو inactive', type: 'string')]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $users = User::query()
            ->with(['roles.permissions', 'permissions', 'deniedPermissions'])
            ->when($request->filled('search'), function ($query) use ($request) {
                $term = $request->string('search')->trim()->value();
                $query->where(function ($q) use ($term) {
                    $q->where('name', 'like', "%{$term}%")
                        ->orWhere('email', 'like', "%{$term}%");
                });
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                $query->where('is_active', $request->string('status')->value() === 'active');
            })
            /*
             * الأعلى مستوى أولاً ثم الأحدث. الترتيب يتم على مستوى قاعدة
             * البيانات لا في PHP، فلا ينكسر مع الترقيم.
             */
            ->orderByDesc(
                Role::query()
                    ->selectRaw('coalesce(max(roles.level), 0)')
                    ->join('model_has_roles', 'model_has_roles.role_id', '=', 'roles.id')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->where('model_has_roles.model_type', User::class)
            )
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return response()->json([
            'data' => UserResource::collection($users)->resolve($request),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * تفاصيل مستخدم واحد.
     *
     * الصلاحية المطلوبة: `users.manage` (إدارة المستخدمين).
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorize('view', $user);

        $user->load(['roles.permissions', 'permissions', 'deniedPermissions']);

        return response()->json(['data' => (new UserResource($user))->resolve($request)]);
    }

    /**
     * الأدوار والصلاحيات المتاحة، وما يحقّ للمستخدم الحالي منحه.
     *
     * الحساب يتم في الخادم لا في الواجهة: لو تركنا React تقرّر أي دور
     * تعرضه لصار منطق التفويض مكرَّراً في مكانين، وأي اختلاف بينهما ثغرة.
     */
    public function meta(Request $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $actor = $request->user();

        $roles = Role::query()->with('permissions')->orderByDesc('level')->get()->map(fn (Role $role) => [
            'name' => $role->name,
            'label' => RolesAndPermissionsSeeder::ROLES[$role->name]['label'] ?? $role->name,
            'level' => (int) $role->level,
            'assignable' => $actor->can('roles.manage') && (int) $role->level < $actor->roleLevel(),
            // ما يمنحه الدور: الواجهة تقفل هذي الصناديق فوراً عند تبديل الدور
            'permissions' => $role->permissions->pluck('name')->values(),
        ]);

        $permissions = Permission::query()->orderBy('name')->get()->map(fn (Permission $permission) => [
            'name' => $permission->name,
            'label' => RolesAndPermissionsSeeder::PERMISSIONS[$permission->name] ?? $permission->name,
            // لا تمنح ما لا تملك — الواجهة تُعطّل ما لا يحقّ له، والخادم يرفضه أيضاً.
            'grantable' => $actor->can($permission->name),
        ]);

        return response()->json(['roles' => $roles, 'permissions' => $permissions]);
    }

    /* ------------------------------ الإنشاء ------------------------------ */

    /**
     * إنشاء حساب في نظام الإدارة.
     *
     * الصلاحية المطلوبة: `users.manage` (إدارة المستخدمين). ولا يمكن إنشاء حساب
     * بدور في مستواك أو أعلى، ولا منحه صلاحية لا تملكها أنت.
     *
     * هؤلاء مستخدمو نظام الإدارة — لا علاقة لهم بمستخدمي منصّة المعرفة العامّين.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();
        $role = Role::findByName($data['role'], User::PERMISSION_GUARD);

        $this->authorize('createWithRole', [User::class, (int) $role->level]);

        $granted = $this->assertGrantable($request, $data['permissions'] ?? []);
        $granted = array_values(array_diff($granted, $role->permissions->pluck('name')->all()));

        $user = DB::transaction(function () use ($data, $role, $granted) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],   // الـcast يتولّى التعمية
                'is_active' => $data['is_active'] ?? true,
            ]);

            $user->syncRoles([$role->name]);
            $user->syncPermissions($granted);

            return $user;
        });

        $this->audit->record($request, 'users.create', AuditLogger::SUBJECT_USER, (string) $user->id, [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $role->name,
            'direct_permissions' => $granted,
        ]);

        return response()->json(
            ['data' => (new UserResource($this->freshUser($user)))->resolve($request)],
            201
        );
    }

    /* ------------------------------ التعديل ------------------------------ */

    /**
     * تعديل بيانات حساب.
     *
     * الصلاحية المطلوبة: `users.manage` (إدارة المستخدمين). كلمة المرور اختيارية —
     * تركها فارغة يُبقي الحالية. ولا تُسجَّل قيمتها في سجلّ العمليات إطلاقاً،
     * يُسجَّل أنها تغيّرت فقط.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        // كلمة مرور فارغة تعني "لا تغيّرها" لا "اجعلها فارغة".
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $changed = array_keys($data);
        $user->update($data);

        $this->audit->record($request, 'users.update', AuditLogger::SUBJECT_USER, (string) $user->id, [
            // أسماء الحقول لا قيمها — والسجلّ لا يحفظ كلمة مرور أبداً.
            'fields' => array_values(array_diff($changed, ['password'])),
            'password_changed' => in_array('password', $changed, true),
        ]);

        return response()->json(['data' => (new UserResource($this->freshUser($user)))->resolve($request)]);
    }

    /**
     * تفعيل حساب أو تعطيله.
     *
     * الصلاحية المطلوبة: `users.manage` (إدارة المستخدمين)، مع الهرمية: لا يمكن
     * تعطيل مَن هو في مستواك أو أعلى، ولا تعطيل نفسك. **لا تُحذف أي بيانات** —
     * تُلغى جلسات الحساب المفتوحة فوراً ويمكن إعادة تفعيله في أي وقت.
     */
    public function toggleActive(Request $request, User $user): JsonResponse
    {
        $this->authorize('toggleActive', $user);

        $user->update(['is_active' => ! $user->is_active]);

        /*
         * التعطيل يُبطل توكنات الحساب فوراً: لولاه لبقي مفتاح صالح بيد
         * حساب موقوف حتى ينتهي بنفسه. الـmiddleware يسدّ الباب على كل
         * طلب، وهذا يسحب المفاتيح من الأساس.
         */
        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        $this->audit->record(
            $request,
            $user->is_active ? 'users.enable' : 'users.disable',
            AuditLogger::SUBJECT_USER,
            (string) $user->id,
            ['name' => $user->name]
        );

        return response()->json(['data' => (new UserResource($this->freshUser($user)))->resolve($request)]);
    }

    /* ------------------------- الأدوار والصلاحيات ------------------------ */

    /**
     * تغيير دور مستخدم.
     *
     * الصلاحية المطلوبة: `roles.manage` (إدارة الأدوار والصلاحيات). لا يمكن إسناد
     * دور في مستواك أو أعلى — منعاً لتصعيد الامتيازات.
     */
    public function updateRole(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(
            ['role' => ['required', 'string', Rule::exists(Role::class, 'name')]],
            ['role.exists' => 'الدور المحدَّد غير موجود.']
        );

        $role = Role::findByName($data['role'], User::PERMISSION_GUARD);
        $previous = $user->roles->first()?->name;

        $this->authorize('assignRole', [$user, (int) $role->level]);

        $user->syncRoles([$role->name]);

        /*
         * الحجب استثناءٌ من دور بعينه. عند تبديل الدور تُسقط الاستثناءات
         * التي لم يعد الدور الجديد يمنحها أصلاً، وإلا بقيت قنابل صامتة:
         * صلاحية تُمنح لاحقاً فلا تعمل، بلا سبب ظاهر في الشاشة.
         */
        $user->deniedPermissions()->detach(
            $user->deniedPermissions
                ->whereNotIn('name', $role->permissions->pluck('name'))
                ->pluck('id')
        );

        $this->audit->record($request, 'users.role', AuditLogger::SUBJECT_USER, (string) $user->id, [
            // الاسم لحظة العملية: لو أُعيدت تسميته لاحقاً بقي السجلّ مفهوماً
            'name' => $user->name,
            'from' => $previous,
            'to' => $role->name,
        ]);

        return response()->json(['data' => (new UserResource($this->freshUser($user)))->resolve($request)]);
    }

    /**
     * ضبط صلاحيات مستخدم.
     *
     * permissions هنا هي «المجموعة الفعّالة» المطلوبة — ما ينبغي أن يملكه
     * بعد الحفظ — لا قائمة الإضافات. والخادم يشتق منها الطرفين:
     *
     *   المنح  = المطلوب  −  ما يمنحه الدور   (صلاحية زائدة فوق الدور)
     *   الحجب  = ما يمنحه الدور  −  المطلوب   (استثناء من الدور)
     *
     * قائمة واحدة لا قائمتين: الواجهة صناديق اختيار، وحالة الصندوق هي
     * الحقيقة كاملةً. ولو أرسلنا قائمتين لأمكن أن تتناقضا.
     */
    public function updatePermissions(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
        ], [
            'permissions.present' => 'يجب إرسال قائمة الصلاحيات ولو فارغة.',
            'permissions.*.exists' => 'إحدى الصلاحيات المحدَّدة غير موجودة.',
        ]);

        $desired = array_values(array_unique($data['permissions']));
        $fromRole = $this->rolePermissionNames($user);

        $granted = array_values(array_diff($desired, $fromRole));
        $denied = array_values(array_diff($fromRole, $desired));

        // الفحص على ما يُمنح فقط: الحجب تقييد لا تصعيد، فلا يُشترط امتلاكه
        $this->authorize('grantPermissions', [$user, $granted]);

        $previousGrants = $user->permissions->pluck('name')->sort()->values()->all();
        $previousDenials = $user->deniedPermissions->pluck('name')->sort()->values()->all();

        DB::transaction(function () use ($user, $granted, $denied) {
            $user->syncPermissions($granted);

            $user->deniedPermissions()->sync(
                Permission::query()
                    ->whereIn('name', $denied)
                    ->where('guard_name', User::PERMISSION_GUARD)
                    ->pluck('id')
            );
        });

        $this->audit->record($request, 'users.permissions', AuditLogger::SUBJECT_USER, (string) $user->id, [
            'name' => $user->name,
            'granted_from' => $previousGrants,
            'granted_to' => $granted,
            'denied_from' => $previousDenials,
            'denied_to' => $denied,
        ]);

        return response()->json(['data' => (new UserResource($this->freshUser($user)))->resolve($request)]);
    }

    /* ------------------------------------------------------------------- */

    /**
     * أسماء الصلاحيات التي يمنحها دور المستخدم.
     *
     * @return array<int, string>
     */
    private function rolePermissionNames(User $user): array
    {
        return $user->loadMissing('roles.permissions')
            ->roles->flatMap->permissions->pluck('name')->unique()->values()->all();
    }

    /** إعادة تحميل كاملة بعد أي تغيير — بما فيها المحجوب. */
    private function freshUser(User $user): User
    {
        return $user->fresh(['roles.permissions', 'permissions', 'deniedPermissions']);
    }

    /**
     * يرفض منح صلاحية لا يملكها المانح — القاعدة الثالثة في UserPolicy.
     *
     * @param  array<int, string>  $permissions
     * @return array<int, string>
     */
    private function assertGrantable(Request $request, array $permissions): array
    {
        foreach ($permissions as $permission) {
            if (! $request->user()->can($permission)) {
                throw ValidationException::withMessages([
                    'permissions' => ["لا يمكنك منح صلاحية لا تملكها: {$permission}"],
                ]);
            }
        }

        return array_values($permissions);
    }
}
