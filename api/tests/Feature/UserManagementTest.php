<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Managing the admin system's users through the API.
 *
 * Proves that the hierarchy rules are not theory living in UserPolicy but are
 * genuinely enforced at every endpoint — and cannot be sidestepped by calling
 * the API directly.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingAsAccount(string $email): User
    {
        $user = User::where('email', $email)->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    private function account(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /* ------------------------------- Access ------------------------------ */

    public function test_a_user_without_users_manage_cannot_list_users(): void
    {
        $this->actingAsAccount('editor@demo.test');

        $this->getJson('/api/v1/users')->assertStatus(403);
    }

    public function test_an_admin_lists_users_with_their_roles(): void
    {
        $this->actingAsAccount('admin@demo.test');

        $this->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'email', 'is_active', 'level', 'role', 'permissions', 'can']],
                'meta' => ['current_page', 'last_page', 'total'],
            ]);
    }

    public function test_meta_only_offers_roles_below_the_actor(): void
    {
        $this->actingAsAccount('admin@demo.test');   // level 80

        $roles = collect($this->getJson('/api/v1/users/meta')->assertOk()->json('roles'));

        $this->assertFalse($roles->firstWhere('name', 'super_admin')['assignable']);
        $this->assertFalse($roles->firstWhere('name', 'admin')['assignable']);
        $this->assertTrue($roles->firstWhere('name', 'moderator')['assignable']);
    }

    /* ------------------------------ Creating ----------------------------- */

    public function test_an_admin_creates_a_user(): void
    {
        $this->actingAsAccount('admin@demo.test');

        $this->postJson('/api/v1/users', [
            'name' => 'مستخدمة جديدة',
            'email' => 'new@demo.test',
            'password' => 'password123',
            'role' => 'editor',
        ])->assertStatus(201)->assertJsonPath('data.role', 'editor');

        $this->assertDatabaseHas('users', ['email' => 'new@demo.test', 'is_active' => true]);
    }

    public function test_an_admin_cannot_create_someone_at_or_above_their_level(): void
    {
        $this->actingAsAccount('admin@demo.test');

        $this->postJson('/api/v1/users', [
            'name' => 'مدير آخر',
            'email' => 'rival@demo.test',
            'password' => 'password123',
            'role' => 'super_admin',
        ])->assertStatus(403);

        $this->assertDatabaseMissing('users', ['email' => 'rival@demo.test']);
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        $this->actingAsAccount('admin@demo.test');

        $this->postJson('/api/v1/users', [
            'name' => 'مكرّرة',
            'email' => 'editor@demo.test',
            'password' => 'password123',
            'role' => 'viewer',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    /* ----------------------------- Disabling ---------------------------- */

    public function test_disabling_a_user_revokes_their_tokens_immediately(): void
    {
        $target = $this->account('editor@demo.test');
        $target->createToken('device')->plainTextToken;
        $this->assertSame(1, $target->tokens()->count());

        $this->actingAsAccount('admin@demo.test');

        $this->postJson("/api/v1/users/{$target->id}/toggle-active")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertSame(0, $target->fresh()->tokens()->count());
        $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);
    }

    public function test_an_admin_cannot_disable_the_super_admin(): void
    {
        $super = $this->account('super@demo.test');
        $this->actingAsAccount('admin@demo.test');

        $this->postJson("/api/v1/users/{$super->id}/toggle-active")->assertStatus(403);

        $this->assertTrue($super->fresh()->is_active);
    }

    public function test_nobody_can_disable_themselves(): void
    {
        $admin = $this->actingAsAccount('admin@demo.test');

        $this->postJson("/api/v1/users/{$admin->id}/toggle-active")->assertStatus(403);
    }

    /* ----------------------- Roles and permissions ----------------------- */

    public function test_an_admin_cannot_promote_someone_to_super_admin(): void
    {
        $target = $this->account('editor@demo.test');
        $this->actingAsAccount('admin@demo.test');

        $this->putJson("/api/v1/users/{$target->id}/role", ['role' => 'super_admin'])
            ->assertStatus(403);

        $this->assertSame(['editor'], $target->fresh()->getRoleNames()->all());
    }

    public function test_an_admin_may_change_a_role_below_their_level(): void
    {
        $target = $this->account('author@demo.test');
        $this->actingAsAccount('admin@demo.test');

        $this->putJson("/api/v1/users/{$target->id}/role", ['role' => 'moderator'])
            ->assertOk()
            ->assertJsonPath('data.role', 'moderator');
    }

    /**
     * The most important test in the file: blocking privilege escalation.
     */
    public function test_nobody_may_grant_a_permission_they_do_not_hold(): void
    {
        // A content moderator holds roles.manage by direct grant, but not users.manage
        $actor = $this->account('moderator@demo.test');
        $actor->givePermissionTo('roles.manage');

        $target = $this->account('author@demo.test');
        Sanctum::actingAs($actor->fresh());

        $this->putJson("/api/v1/users/{$target->id}/permissions", [
            'permissions' => ['users.manage'],
        ])->assertStatus(403);

        $this->assertFalse($target->fresh()->can('users.manage'));
    }

    /**
     * The list that is sent is the "effective set": what the user should hold
     * once it is saved. Here we send all of the permissions their role gives
     * plus two more, so the role stays as it is and the two are added as
     * individual grants.
     */
    public function test_an_admin_grants_individual_permissions(): void
    {
        $target = $this->account('author@demo.test');
        $this->actingAsAccount('admin@demo.test');

        $this->putJson("/api/v1/users/{$target->id}/permissions", [
            'permissions' => [
                'articles.view', 'articles.create', 'articles.draft',   // from the role
                'articles.publish', 'analytics.view',                    // direct grant
            ],
        ])->assertOk();

        $fresh = $target->fresh();
        $this->assertTrue($fresh->can('articles.publish'));
        $this->assertTrue($fresh->can('analytics.view'));
        $this->assertTrue($fresh->can('articles.create'));   // still holds the role's permissions
        $this->assertSame(['author'], $fresh->getRoleNames()->all());
        $this->assertEmpty($fresh->deniedPermissions);
    }

    /**
     * Denial: taking away a permission the role grants without demoting the
     * user to a lesser role.
     *
     * This is the case that was impossible before the permission_denials table:
     * a role grants its whole set or none of it, and an exception used to
     * require a brand new role for every single person.
     */
    public function test_an_admin_may_revoke_a_permission_the_role_grants(): void
    {
        $target = $this->account('author@demo.test');
        $this->assertTrue($target->can('articles.create'));

        $this->actingAsAccount('admin@demo.test');

        // The requested set = their role's permissions minus create
        $this->putJson("/api/v1/users/{$target->id}/permissions", [
            'permissions' => ['articles.view', 'articles.draft'],
        ])->assertOk();

        $fresh = $target->fresh();

        $this->assertFalse($fresh->can('articles.create'));            // denied despite the role
        $this->assertTrue($fresh->can('articles.view'));               // and the rest is intact
        $this->assertSame(['author'], $fresh->getRoleNames()->all());  // and the role is unchanged

        // And it is absent from what they can do, so the screen matches the server
        $this->assertNotContains('articles.create', $fresh->getAllPermissions()->pluck('name'));
    }

    /** Changing the role drops the denials the new role no longer grants. */
    public function test_changing_the_role_clears_denials_the_new_role_does_not_grant(): void
    {
        $target = $this->account('author@demo.test');
        $this->actingAsAccount('admin@demo.test');

        $this->putJson("/api/v1/users/{$target->id}/permissions", [
            'permissions' => ['articles.view', 'articles.draft'],
        ])->assertOk();

        $this->assertFalse($target->fresh()->can('articles.create'));

        // "viewer" does not grant articles.create at all, so denying it is meaningless
        $this->putJson("/api/v1/users/{$target->id}/role", ['role' => 'viewer'])->assertOk();

        $this->assertEmpty($target->fresh()->deniedPermissions);
    }

    /**
     * A permission the role already grants is not stored as a direct grant.
     *
     * Storing it would leave a row in model_has_permissions that adds nothing
     * to the user, so the screen would report an "individual grant" for a
     * permission that is not individual — a false figure in an interface whose
     * whole purpose is to show who holds what.
     */
    public function test_a_permission_the_role_already_grants_is_not_stored_directly(): void
    {
        $target = $this->account('author@demo.test');
        $this->actingAsAccount('admin@demo.test');

        $this->putJson("/api/v1/users/{$target->id}/permissions", [
            // articles.view comes with the "author" role; articles.delete does not
            'permissions' => ['articles.view', 'articles.delete'],
        ])->assertOk();

        $fresh = $target->fresh();

        // What gets stored directly is the difference alone
        $this->assertSame(['articles.delete'], $fresh->permissions->pluck('name')->all());

        // And nothing was lost: articles.view still reaches them through the role
        $this->assertTrue($fresh->can('articles.view'));
        $this->assertTrue($fresh->can('articles.delete'));
    }

    /** An empty list = "holds nothing": grants cleared, role permissions denied. */
    public function test_an_empty_list_strips_every_permission(): void
    {
        $target = $this->account('author.plus@demo.test');
        $this->assertTrue($target->can('articles.delete'));   // direct grant
        $this->assertTrue($target->can('articles.view'));     // from the role

        $this->actingAsAccount('admin@demo.test');

        $this->putJson("/api/v1/users/{$target->id}/permissions", ['permissions' => []])
            ->assertOk();

        $fresh = $target->fresh();
        $this->assertFalse($fresh->can('articles.delete'));
        $this->assertFalse($fresh->can('articles.view'));
        $this->assertCount(0, $fresh->getAllPermissions());
    }

    /* --------------------------- The audit log --------------------------- */

    public function test_user_operations_are_recorded_in_the_audit_log(): void
    {
        $actor = $this->actingAsAccount('admin@demo.test');
        $target = $this->account('editor@demo.test');

        $this->postJson("/api/v1/users/{$target->id}/toggle-active")->assertOk();

        $log = AuditLog::latest('id')->firstOrFail();

        $this->assertSame($actor->id, $log->user_id);
        $this->assertSame('users.disable', $log->action);
        $this->assertSame('user', $log->subject_type);
        $this->assertSame((string) $target->id, $log->subject_id);
        $this->assertTrue($log->succeeded);
    }

    public function test_the_audit_log_never_stores_a_password(): void
    {
        $target = $this->account('editor@demo.test');
        $this->actingAsAccount('admin@demo.test');

        $this->putJson("/api/v1/users/{$target->id}", [
            'name' => 'ريم بعد التعديل',
            'password' => 'a-brand-new-password',
        ])->assertOk();

        $log = AuditLog::latest('id')->firstOrFail();

        $this->assertSame('users.update', $log->action);
        $this->assertTrue($log->payload['password_changed']);
        $this->assertStringNotContainsString('a-brand-new-password', json_encode($log->payload));
    }

    /* ------------------------- No deletion, ever ------------------------- */

    public function test_there_is_no_delete_endpoint_for_users(): void
    {
        $target = $this->account('editor@demo.test');
        $this->actingAsAccount('super@demo.test');

        // 405 = the route exists but the method is not allowed — no deletion by design
        $this->deleteJson("/api/v1/users/{$target->id}")->assertStatus(405);

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }
}
