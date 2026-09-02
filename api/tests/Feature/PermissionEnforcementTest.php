<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * الاختبارات التي تُثبت أن الصلاحيات تُفرض في الخادم.
 *
 * منصّة المعرفة مُزيَّفة هنا بـHttp::fake — الاختبار يجب أن يعمل
 * بلا إنترنت وبلا تشغيل المشروع الأول، ويقيس سلوكنا نحن لا سلوكها.
 */
class PermissionEnforcementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        Http::fake([
            '*/api/v1/posts/*' => Http::response(['data' => null, 'message' => 'ok'], 200),
            '*/api/v1/posts' => Http::response(
                ['data' => ['slug' => 'article-slug'], 'message' => 'created'],
                201
            ),
        ]);
    }

    private function actingAsAccount(string $email): User
    {
        $user = User::where('email', $email)->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
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

    public function test_only_an_admin_may_read_the_audit_log(): void
    {
        $this->actingAsAccount('moderator@demo.test');
        $this->getJson('/api/v1/audit-logs')->assertStatus(403);

        $this->actingAsAccount('admin@demo.test');
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
}
