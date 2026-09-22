<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The permission hierarchy: who is able to do what, and to whom.
 *
 * The tests here call UserPolicy directly through can(), because the user
 * management routes only arrive in phase four. The rules are tested where they
 * really belong: the domain layer, not the HTTP layer.
 */
class RoleHierarchyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function account(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /* ----------------------------- The levels ---------------------------- */

    public function test_each_role_carries_its_level(): void
    {
        $this->assertSame(100, $this->account('super@demo.test')->roleLevel());
        $this->assertSame(80, $this->account('admin@demo.test')->roleLevel());
        $this->assertSame(60, $this->account('moderator@demo.test')->roleLevel());
        $this->assertSame(10, $this->account('viewer@demo.test')->roleLevel());
    }

    public function test_a_user_without_a_role_has_no_authority(): void
    {
        $orphan = User::factory()->create();

        $this->assertSame(0, $orphan->roleLevel());
        $this->assertFalse($orphan->outranks($this->account('viewer@demo.test')));
    }

    /* --------------- Rule 1: never touch someone above you --------------- */

    public function test_an_admin_cannot_modify_the_super_admin(): void
    {
        $admin = $this->account('admin@demo.test');
        $super = $this->account('super@demo.test');

        $this->assertFalse($admin->can('update', $super));
        $this->assertFalse($admin->can('toggleActive', $super));
    }

    public function test_an_admin_can_modify_someone_below_them(): void
    {
        $admin = $this->account('admin@demo.test');
        $editor = $this->account('editor@demo.test');

        $this->assertTrue($admin->can('update', $editor));
        $this->assertTrue($admin->can('toggleActive', $editor));
    }

    public function test_equals_have_no_authority_over_each_other(): void
    {
        $first = $this->account('admin@demo.test');

        $second = User::factory()->create();
        $second->assignRole('admin');

        $this->assertFalse($first->can('toggleActive', $second));
        $this->assertFalse($second->can('toggleActive', $first));
    }

    public function test_nobody_may_disable_their_own_account(): void
    {
        $super = $this->account('super@demo.test');

        $this->assertFalse($super->can('toggleActive', $super));
    }

    /* ------------ Rule 2: never make someone stronger than you ----------- */

    public function test_an_admin_cannot_promote_anyone_to_super_admin(): void
    {
        $admin = $this->account('admin@demo.test');
        $editor = $this->account('editor@demo.test');

        // 100 = the super admin's level, and 80 = the admin's own level
        $this->assertFalse($admin->can('assignRole', [$editor, 100]));
        $this->assertFalse($admin->can('assignRole', [$editor, 80]));

        // Anything below that is allowed
        $this->assertTrue($admin->can('assignRole', [$editor, 60]));
    }

    public function test_a_moderator_cannot_assign_roles_at_all(): void
    {
        $moderator = $this->account('moderator@demo.test');
        $author = $this->account('author@demo.test');

        // They do not hold roles.manage in the first place
        $this->assertFalse($moderator->can('assignRole', [$author, 20]));
    }

    /* -------------- Rule 3: never grant what you do not hold ------------- */

    public function test_nobody_may_grant_a_permission_they_do_not_hold(): void
    {
        /*
         * The privilege escalation case: a user who holds roles.manage and a
         * high level, but does not hold users.manage. Without the third rule
         * they could grant it to an account they control and then sign in as
         * that account.
         */
        $limited = User::factory()->create();
        $limited->assignRole('admin');
        $limited->revokePermissionTo([]);          // no direct grants
        $limited->removeRole('admin');
        $limited->assignRole('admin');

        // We strip the permission from the role temporarily to build the case
        $role = Role::findByName('admin');
        $role->revokePermissionTo('users.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $target = $this->account('editor@demo.test');
        $limited = $limited->fresh();

        $this->assertFalse($limited->can('users.manage'));
        $this->assertFalse($limited->can('grantPermissions', [$target, ['users.manage']]));

        // But what they do hold, they can grant
        $this->assertTrue($limited->can('grantPermissions', [$target, ['articles.publish']]));
    }

    public function test_a_super_admin_may_grant_anything_below_them(): void
    {
        $super = $this->account('super@demo.test');
        $editor = $this->account('editor@demo.test');

        $this->assertTrue($super->can('grantPermissions', [$editor, ['users.manage', 'audit.view']]));
        $this->assertTrue($super->can('assignRole', [$editor, 80]));
    }

    public function test_nobody_may_change_their_own_roles_or_permissions(): void
    {
        $super = $this->account('super@demo.test');

        $this->assertFalse($super->can('assignRole', [$super, 10]));
        $this->assertFalse($super->can('grantPermissions', [$super, ['articles.view']]));
    }
}
