<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\FakesKnowledgePlatform;
use Tests\TestCase;

/**
 * الاختبارات التي تُثبت أن الصلاحيات تُفرض في الخادم.
 *
 * منصّة المعرفة مُزيَّفة هنا بـHttp::fake — الاختبار يجب أن يعمل
 * بلا إنترنت وبلا تشغيل المشروع الأول، ويقيس سلوكنا نحن لا سلوكها.
 */
class PermissionEnforcementTest extends TestCase
{
    use FakesKnowledgePlatform, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->fakePlatform([
            '*/api/v1/posts/*' => Http::response(['data' => null, 'message' => 'ok'], 200),
            '*/api/v1/posts' => Http::response(
                ['data' => ['slug' => 'article-slug'], 'message' => 'created'],
                201
            ),
        ], Http::response(['data' => null, 'message' => 'ok'], 200));
    }

    /* ----------------------------- المصادقة ----------------------------- */

    public function test_guest_cannot_reach_articles(): void
    {
        $this->getJson('/api/v1/articles')->assertStatus(401);
    }

    /* ----------------------------- التفويض ------------------------------ */

    public function test_viewer_cannot_create_an_article(): void
    {
        $this->actingAsAccount('viewer@demo.test');

        $this->postJson('/api/v1/articles', [
            'title' => 'محاولة',
            'content' => 'محتوى',
        ])->assertStatus(403);
    }

    public function test_author_can_create_an_article(): void
    {
        $this->actingAsAccount('author@demo.test');

        $this->postJson('/api/v1/articles', [
            'title' => 'مقال جديد',
            'content' => 'محتوى المقال',
        ])->assertStatus(201);
    }

    public function test_author_cannot_delete_an_article(): void
    {
        $this->actingAsAccount('author@demo.test');

        $this->deleteJson('/api/v1/articles/article-slug')->assertStatus(403);
    }

    /**
     * جوهر المشروع: نفس الدور، نفس الطلب، نتيجة معاكسة —
     * لأن الصلاحية مُنحت لهذي المستخدمة وحدها (Direct Grant).
     */
    public function test_author_with_a_direct_grant_can_delete(): void
    {
        $this->actingAsAccount('author.plus@demo.test');

        $this->deleteJson('/api/v1/articles/article-slug')->assertStatus(200);
    }

    public function test_direct_grant_does_not_leak_to_other_authors(): void
    {
        $plain = User::where('email', 'author@demo.test')->firstOrFail();
        $granted = User::where('email', 'author.plus@demo.test')->firstOrFail();

        $this->assertTrue($granted->can('articles.delete'));
        $this->assertFalse($plain->can('articles.delete'));
        $this->assertSame(['author'], $granted->getRoleNames()->all());
    }

    /* --------------------------- سجلّ التدقيق ---------------------------- */

    public function test_audit_log_records_who_did_what(): void
    {
        $user = $this->actingAsAccount('author@demo.test');

        $this->postJson('/api/v1/articles', [
            'title' => 'مقال للتدقيق',
            'content' => 'محتوى',
        ])->assertStatus(201);

        $log = AuditLog::latest('id')->firstOrFail();

        $this->assertSame($user->id, $log->user_id);
        $this->assertSame('articles.create', $log->action);
        $this->assertSame('article-slug', $log->subject_id);
        $this->assertTrue($log->succeeded);
        // payload يُخزَّن JSONB ويعود مصفوفةً تلقائياً.
        $this->assertSame('مقال للتدقيق', $log->payload['title']);
    }

    /**
     * السجلّ يُحرَس بصلاحية audit.view لا بالدور.
     *
     * الفرق عملي: يمكن منح قراءة السجلّ لشخص بعينه دون ترقيته إلى
     * مشرف — وهذا ما يستحيل في نظام يعتمد على الأدوار وحدها.
     */
    public function test_the_audit_log_is_guarded_by_a_permission_not_a_role(): void
    {
        // مطّلعة: لا تملك audit.view
        $this->actingAsAccount('viewer@demo.test');
        $this->getJson('/api/v1/audit-logs')->assertStatus(403);

        // مشرفة محتوى: تملكها بحكم دورها، وليست مشرفة نظام
        $this->actingAsAccount('moderator@demo.test');
        $this->getJson('/api/v1/audit-logs')->assertStatus(200);

        $this->actingAsAccount('admin@demo.test');
        $this->getJson('/api/v1/audit-logs')->assertStatus(200);
    }

    /**
     * ويمكن منحها منحاً مباشراً لمن لا يملكها بدوره.
     */
    public function test_a_direct_grant_alone_opens_the_audit_log(): void
    {
        $reader = User::where('email', 'viewer@demo.test')->firstOrFail();
        $this->actingAsAccount('viewer@demo.test');
        $this->getJson('/api/v1/audit-logs')->assertStatus(403);

        $reader->givePermissionTo('audit.view');

        $this->actingAsAccount('viewer@demo.test');
        $this->getJson('/api/v1/audit-logs')->assertStatus(200);
    }

    /* ------------------------------ التحقّق ------------------------------ */

    public function test_invalid_payload_is_rejected_before_reaching_the_platform(): void
    {
        $this->actingAsAccount('author@demo.test');

        $this->postJson('/api/v1/articles', ['title' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'content']);
    }

    /* ---------------------------- النشر والسحب ---------------------------- */

    /**
     * أخطر ثغرة كانت ممكنة: كاتب ينشر بلا صلاحية نشر.
     *
     * الواجهة لا تعرض له زرّ النشر، لكن إخفاء الزر ليس حماية — و curl يتجاوزه.
     * فالحارس في الخادم: status=published يلزمه articles.publish.
     */
    public function test_an_author_cannot_publish_by_sending_the_status_directly(): void
    {
        $this->actingAsAccount('author@demo.test');

        $this->postJson('/api/v1/articles', [
            'title' => 'محاولة نشر',
            'content' => 'نصّ.',
            'status' => 'published',
        ])->assertStatus(422)->assertJsonValidationErrors('status');

        // ولم يصل الطلب إلى المنصّة أصلاً
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_an_author_may_still_save_a_draft(): void
    {
        $this->actingAsAccount('author@demo.test');

        $this->postJson('/api/v1/articles', [
            'title' => 'مسودة',
            'content' => 'نصّ.',
            'status' => 'draft',
        ])->assertStatus(201);
    }

    public function test_the_publish_route_is_guarded(): void
    {
        $this->actingAsAccount('author@demo.test');

        $this->postJson('/api/v1/articles/some-slug/publish')->assertForbidden();
    }

    public function test_a_moderator_may_publish(): void
    {
        $this->actingAsAccount('moderator@demo.test');

        $this->postJson('/api/v1/articles/some-slug/publish')->assertOk();
    }

    /**
     * النشر والسحب صلاحيتان منفصلتان، والبذرة تستعمل الفصل فعلاً:
     * المحرّر يملك `articles.draft` ولا يملك `articles.publish`.
     *
     * أي أنه يكتب ويسحب من النشر ليصحّح، والإظهار للعامة يبقى لمشرف المحتوى.
     * لو كانت صلاحية واحدة لضاع هذا التمييز.
     */
    public function test_an_editor_may_unpublish_but_not_publish(): void
    {
        $this->actingAsAccount('editor@demo.test');

        $this->postJson('/api/v1/articles/some-slug/draft')->assertOk();
        $this->postJson('/api/v1/articles/some-slug/publish')->assertForbidden();
    }

    public function test_publishing_is_recorded_under_its_own_action(): void
    {
        $this->actingAsAccount('moderator@demo.test');

        $this->postJson('/api/v1/articles/some-slug/publish')->assertOk();

        // «نشر مقال» لا «تعديل مقال» — العملية تظهر باسمها في السجلّ
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'articles.publish',
            'subject_id' => 'some-slug',
        ]);
    }
}
