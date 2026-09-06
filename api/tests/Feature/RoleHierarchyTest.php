<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * تدرّج الصلاحيات: من يستطيع أن يفعل ماذا بمَن.
 *
 * الاختبارات هنا تستدعي UserPolicy مباشرة عبر can()، لأن مسارات إدارة
 * المستخدمين تأتي في المرحلة الرابعة. القواعد تُختبر في موضعها الصحيح:
 * طبقة النطاق، لا طبقة HTTP.
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

    /* ----------------------------- المستويات ---------------------------- */

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

    /* ------------------- القاعدة ١: لا تلمس من فوقك ---------------------- */

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

    /* ---------------- القاعدة ٢: لا تصنع من هو أقوى منك ------------------ */

    public function test_an_admin_cannot_promote_anyone_to_super_admin(): void
    {
        $admin = $this->account('admin@demo.test');
        $editor = $this->account('editor@demo.test');

        // 100 = مستوى مدير النظام، و80 = مستوى المشرف نفسه
        $this->assertFalse($admin->can('assignRole', [$editor, 100]));
        $this->assertFalse($admin->can('assignRole', [$editor, 80]));

        // ما دونه مسموح
        $this->assertTrue($admin->can('assignRole', [$editor, 60]));
    }

    public function test_a_moderator_cannot_assign_roles_at_all(): void
    {
        $moderator = $this->account('moderator@demo.test');
        $author = $this->account('author@demo.test');

        // لا يملك roles.manage أصلاً
        $this->assertFalse($moderator->can('assignRole', [$author, 20]));
    }

    /* ---------------- القاعدة ٣: لا تمنح ما لا تملك --------------------- */

    public function test_nobody_may_grant_a_permission_they_do_not_hold(): void
    {
        /*
         * حالة تصعيد الامتيازات: مستخدم يملك roles.manage ومستوى عالياً،
         * لكنه لا يملك users.manage. لولا القاعدة الثالثة لاستطاع منحها
         * لحساب يسيطر عليه ثم الدخول به.
         */
        $limited = User::factory()->create();
        $limited->assignRole('admin');
        $limited->revokePermissionTo([]);          // لا منح مباشر
        $limited->removeRole('admin');
        $limited->assignRole('admin');

        // ننزع الصلاحية من الدور مؤقتاً لبناء الحالة
        $role = Role::findByName('admin');
        $role->revokePermissionTo('users.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $target = $this->account('editor@demo.test');
        $limited = $limited->fresh();

        $this->assertFalse($limited->can('users.manage'));
        $this->assertFalse($limited->can('grantPermissions', [$target, ['users.manage']]));

        // أما ما يملكه فيستطيع منحه
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
