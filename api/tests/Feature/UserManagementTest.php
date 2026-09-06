<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * إدارة مستخدمي نظام الإدارة عبر الـAPI.
 *
 * تُثبت أن قواعد التدرّج ليست نظرية في UserPolicy، بل مفروضة فعلاً
 * على كل نقطة نهاية — ولا تُتجاوَز باستدعاء الـAPI مباشرة.
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

    /* ------------------------------ الوصول ------------------------------ */

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
        $this->actingAsAccount('admin@demo.test');   // المستوى 80

        $roles = collect($this->getJson('/api/v1/users/meta')->assertOk()->json('roles'));

        $this->assertFalse($roles->firstWhere('name', 'super_admin')['assignable']);
        $this->assertFalse($roles->firstWhere('name', 'admin')['assignable']);
        $this->assertTrue($roles->firstWhere('name', 'moderator')['assignable']);
    }

    /* ------------------------------ الإنشاء ------------------------------ */

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

    /* ------------------------------ التعطيل ------------------------------ */

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

    /* --------------------------- الأدوار والصلاحيات --------------------- */

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
     * أهم اختبار في الملف: منع تصعيد الامتيازات.
     */
    public function test_nobody_may_grant_a_permission_they_do_not_hold(): void
    {
        // مشرفة محتوى تملك roles.manage بمنح مباشر، لكنها لا تملك users.manage
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
     * القائمة المرسَلة هي «المجموعة الفعّالة»: ما ينبغي أن يملكه بعد الحفظ.
     * هنا نرسل صلاحيات دوره كاملة مضافاً إليها اثنتان، فيبقى دوره كما هو
     * وتُضاف الاثنتان منحاً فردياً.
     */
    public function test_an_admin_grants_individual_permissions(): void
    {
        $target = $this->account('author@demo.test');
        $this->actingAsAccount('admin@demo.test');

        $this->putJson("/api/v1/users/{$target->id}/permissions", [
            'permissions' => [
                'articles.view', 'articles.create', 'articles.draft',   // من دوره
                'articles.publish', 'analytics.view',                    // منحة فردية
            ],
        ])->assertOk();

        $fresh = $target->fresh();
        $this->assertTrue($fresh->can('articles.publish'));
        $this->assertTrue($fresh->can('analytics.view'));
        $this->assertTrue($fresh->can('articles.create'));   // ما زال يملك صلاحيات دوره
        $this->assertSame(['author'], $fresh->getRoleNames()->all());
        $this->assertEmpty($fresh->deniedPermissions);
    }

    /**
     * الحجب: إزالة صلاحية يمنحها الدور دون إنزال المستخدم إلى دور أدنى.
     *
     * هذي الحالة التي كانت مستحيلة قبل جدول permission_denials: الدور يمنح
     * مجموعة كاملة أو لا يمنحها، والاستثناء كان يستلزم دوراً جديداً لكل شخص.
     */
    public function test_an_admin_may_revoke_a_permission_the_role_grants(): void
    {
        $target = $this->account('author@demo.test');
        $this->assertTrue($target->can('articles.create'));

        $this->actingAsAccount('admin@demo.test');

        // المجموعة المطلوبة = صلاحيات دوره عدا الإضافة
        $this->putJson("/api/v1/users/{$target->id}/permissions", [
            'permissions' => ['articles.view', 'articles.draft'],
        ])->assertOk();

        $fresh = $target->fresh();

        $this->assertFalse($fresh->can('articles.create'));            // محجوبة رغم الدور
        $this->assertTrue($fresh->can('articles.view'));               // والباقي سليم
        $this->assertSame(['author'], $fresh->getRoleNames()->all());  // ودوره لم يتغيّر

        // ولا تظهر في ما يستطيعه، فلا تختلف الشاشة عمّا يسمح به الخادم
        $this->assertNotContains('articles.create', $fresh->getAllPermissions()->pluck('name'));
    }

    /** تبديل الدور يُسقط الاستثناءات التي لم يعد الدور الجديد يمنحها. */
    public function test_changing_the_role_clears_denials_the_new_role_does_not_grant(): void
    {
        $target = $this->account('author@demo.test');
        $this->actingAsAccount('admin@demo.test');

        $this->putJson("/api/v1/users/{$target->id}/permissions", [
            'permissions' => ['articles.view', 'articles.draft'],
        ])->assertOk();

        $this->assertFalse($target->fresh()->can('articles.create'));

        // «مطّلع» لا يمنح articles.create أصلاً، فالحجب عليها بلا معنى
        $this->putJson("/api/v1/users/{$target->id}/role", ['role' => 'viewer'])->assertOk();

        $this->assertEmpty($target->fresh()->deniedPermissions);
    }

    /**
     * صلاحية يمنحها الدور أصلاً لا تُخزَّن منحاً مباشراً.
     *
     * تخزينها يترك صفاً في model_has_permissions لا يضيف للمستخدم شيئاً،
     * فتعرض الشاشة «منح فردي» لصلاحية ليست فردية — رقم كاذب في واجهة
     * الغرض منها بيان من يملك ماذا.
     */
    public function test_a_permission_the_role_already_grants_is_not_stored_directly(): void
    {
        $target = $this->account('author@demo.test');
        $this->actingAsAccount('admin@demo.test');

        $this->putJson("/api/v1/users/{$target->id}/permissions", [
            // articles.view يمنحها دور «كاتب» أصلاً، و articles.delete لا يمنحها
            'permissions' => ['articles.view', 'articles.delete'],
        ])->assertOk();

        $fresh = $target->fresh();

        // المخزَّن مباشرةً هو الفرق وحده
        $this->assertSame(['articles.delete'], $fresh->permissions->pluck('name')->all());

        // ولم يخسر شيئاً: articles.view ما زالت له من دوره
        $this->assertTrue($fresh->can('articles.view'));
        $this->assertTrue($fresh->can('articles.delete'));
    }

    /** قائمة فارغة = «لا يملك شيئاً»: تُلغى المنح ويُحجب كل ما يمنحه الدور. */
    public function test_an_empty_list_strips_every_permission(): void
    {
        $target = $this->account('author.plus@demo.test');
        $this->assertTrue($target->can('articles.delete'));   // منحة فردية
        $this->assertTrue($target->can('articles.view'));     // من الدور

        $this->actingAsAccount('admin@demo.test');

        $this->putJson("/api/v1/users/{$target->id}/permissions", ['permissions' => []])
            ->assertOk();

        $fresh = $target->fresh();
        $this->assertFalse($fresh->can('articles.delete'));
        $this->assertFalse($fresh->can('articles.view'));
        $this->assertCount(0, $fresh->getAllPermissions());
    }

    /* --------------------------- سجلّ التدقيق --------------------------- */

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

    /* ---------------------------- لا حذف إطلاقاً ------------------------- */

    public function test_there_is_no_delete_endpoint_for_users(): void
    {
        $target = $this->account('editor@demo.test');
        $this->actingAsAccount('super@demo.test');

        // 405 = المسار موجود لكن الطريقة غير مسموحة — أي لا حذف بالتصميم
        $this->deleteJson("/api/v1/users/{$target->id}")->assertStatus(405);

        $this->assertDatabaseHas('users', ['id' => $target->id]);
    }
}
