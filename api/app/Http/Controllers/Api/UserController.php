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
 * Management of the admin system's users — accounts, roles and individual
 * permissions.
 *
 * These are not the knowledge platform's users. This screen never manages the
 * platform's public accounts, and there is no route here that touches them.
 *
 * No deletion — deactivation only. The requirement is explicit: no data and no
 * records are ever deleted, and a delete would break the audit_logs keys and
 * lose the trail.
 *
 * Two layers of authorization: the middleware guards the route (do you hold the
 * permission at all?), and UserPolicy guards the target (do you outrank this
 * particular person?).
 */
#[Group('المستخدمون', 'حسابات نظام الإدارة وأدوارها وصلاحياتها الفردية.', weight: 2)]
class UserController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /* ------------------------------ Reading ------------------------------ */

    /**
     * List of the admin system's users.
     *
     * Required permission: `users.manage` (manage users). Highest level first,
     * then the most recent; the ordering happens in the database, so it does not
     * break across pagination.
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
             * Highest level first, then the most recent. The ordering is done at
             * the database level rather than in PHP, so it does not break across
             * pagination.
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
     * Details of a single user.
     *
     * Required permission: `users.manage` (manage users).
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorize('view', $user);

        $user->load(['roles.permissions', 'permissions', 'deniedPermissions']);

        return response()->json(['data' => (new UserResource($user))->resolve($request)]);
    }

    /**
     * The available roles and permissions, and which of them the current user is
     * entitled to grant.
     *
     * The calculation happens on the server, not in the interface: if we let
     * React decide which roles to show, the authorization logic would be
     * duplicated in two places, and any divergence between them is a hole.
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
            // What the role grants — the interface locks these boxes on a role switch
            'permissions' => $role->permissions->pluck('name')->values(),
        ]);

        $permissions = Permission::query()->orderBy('name')->get()->map(fn (Permission $permission) => [
            'name' => $permission->name,
            'label' => RolesAndPermissionsSeeder::PERMISSIONS[$permission->name] ?? $permission->name,
            // Grant nothing you lack — the UI disables it and the server refuses it too.
            'grantable' => $actor->can($permission->name),
        ]);

        return response()->json(['roles' => $roles, 'permissions' => $permissions]);
    }

    /* ------------------------------ Creating ----------------------------- */

    /**
     * Create an account in the admin system.
     *
     * Required permission: `users.manage` (manage users). You cannot create an
     * account with a role at your own level or above, nor grant it a permission
     * you do not hold yourself.
     *
     * These are the admin system's users — they have nothing to do with the
     * knowledge platform's public users.
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
                'password' => $data['password'],   // the cast takes care of hashing
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

    /* ------------------------------ Editing ------------------------------ */

    /**
     * Update an account's details.
     *
     * Required permission: `users.manage` (manage users). The password is
     * optional — leaving it empty keeps the current one. Its value is never
     * written to the audit log; only the fact that it changed is recorded.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        // An empty password means "do not change it", not "make it empty".
        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $changed = array_keys($data);
        $user->update($data);

        $this->audit->record($request, 'users.update', AuditLogger::SUBJECT_USER, (string) $user->id, [
            // Field names, not their values — the log never stores a password.
            'fields' => array_values(array_diff($changed, ['password'])),
            'password_changed' => in_array('password', $changed, true),
        ]);

        return response()->json(['data' => (new UserResource($this->freshUser($user)))->resolve($request)]);
    }

    /**
     * Activate or deactivate an account.
     *
     * Required permission: `users.manage` (manage users), together with the
     * hierarchy: you cannot deactivate someone at your own level or above, nor
     * deactivate yourself. **No data is ever deleted** — the account's open
     * sessions are revoked immediately and it can be reactivated at any time.
     */
    public function toggleActive(Request $request, User $user): JsonResponse
    {
        $this->authorize('toggleActive', $user);

        $user->update(['is_active' => ! $user->is_active]);

        /*
         * Deactivation invalidates the account's tokens at once: without it a
         * valid key would stay in the hands of a suspended account until it
         * expired of its own accord. The middleware bars the door on every
         * request; this takes the keys away in the first place.
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

    /* ----------------------- Roles and permissions ----------------------- */

    /**
     * Change a user's role.
     *
     * Required permission: `roles.manage` (manage roles and permissions). A role
     * at your own level or above cannot be assigned — this is what prevents
     * privilege escalation.
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
         * A denial is an exception carved out of one particular role. When the
         * role is switched we drop the exceptions the new role no longer grants
         * in the first place; otherwise they linger as silent bombs: a permission
         * granted later that simply does not work, with no visible reason on the
         * screen.
         */
        $user->deniedPermissions()->detach(
            $user->deniedPermissions
                ->whereNotIn('name', $role->permissions->pluck('name'))
                ->pluck('id')
        );

        $this->audit->record($request, 'users.role', AuditLogger::SUBJECT_USER, (string) $user->id, [
            // The name at the time of the action: a later rename leaves the log readable
            'name' => $user->name,
            'from' => $previous,
            'to' => $role->name,
        ]);

        return response()->json(['data' => (new UserResource($this->freshUser($user)))->resolve($request)]);
    }

    /**
     * Set a user's permissions.
     *
     * `permissions` here is the desired "effective set" — what the user ought to
     * hold once the save is done — not a list of additions. The server derives
     * both sides from it:
     *
     *   granted = desired − what the role grants  (an extra on top of the role)
     *   denied  = what the role grants − desired  (an exception to the role)
     *
     * One list, not two: the interface is a set of checkboxes, and a box's state
     * is the whole truth. Had we sent two lists, they could contradict each
     * other.
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

        // Checked on grants only: a denial restricts rather than escalates, so it need not be held
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
     * The names of the permissions granted by the user's role.
     *
     * @return array<int, string>
     */
    private function rolePermissionNames(User $user): array
    {
        return $user->loadMissing('roles.permissions')
            ->roles->flatMap->permissions->pluck('name')->unique()->values()->all();
    }

    /** A full reload after any change — including the denied permissions. */
    private function freshUser(User $user): User
    {
        return $user->fresh(['roles.permissions', 'permissions', 'deniedPermissions']);
    }

    /**
     * Refuses to grant a permission the granter does not hold — the third rule
     * in UserPolicy.
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
