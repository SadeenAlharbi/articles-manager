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
 * The tests that prove permissions are enforced on the server.
 *
 * The knowledge platform is faked here with Http::fake — the test has to run
 * with no internet and without the first project running, and it measures our
 * behaviour, not theirs.
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

    /* --------------------------- Authentication -------------------------- */

    public function test_guest_cannot_reach_articles(): void
    {
        $this->getJson('/api/v1/articles')->assertStatus(401);
    }

    /* --------------------------- Authorization --------------------------- */

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
     * The heart of the project: the same role, the same request, the opposite
     * outcome — because the permission was granted to this one user alone
     * (a direct grant).
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

    /* --------------------------- The audit log --------------------------- */

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
        // payload is stored as JSONB and comes back as an array automatically.
        $this->assertSame('مقال للتدقيق', $log->payload['title']);
    }

    /**
     * The log is guarded by the audit.view permission, not by a role.
     *
     * The difference is practical: reading the log can be given to one specific
     * person without promoting them to administrator — which is impossible in a
     * system that relies on roles alone.
     */
    public function test_the_audit_log_is_guarded_by_a_permission_not_a_role(): void
    {
        // A viewer: does not hold audit.view
        $this->actingAsAccount('viewer@demo.test');
        $this->getJson('/api/v1/audit-logs')->assertStatus(403);

        // A content moderator: holds it by way of the role, without being a system admin
        $this->actingAsAccount('moderator@demo.test');
        $this->getJson('/api/v1/audit-logs')->assertStatus(200);

        $this->actingAsAccount('admin@demo.test');
        $this->getJson('/api/v1/audit-logs')->assertStatus(200);
    }

    /**
     * And it can be handed out as a direct grant to someone whose role does not carry it.
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

    /* ----------------------------- Validation --------------------------- */

    public function test_invalid_payload_is_rejected_before_reaching_the_platform(): void
    {
        $this->actingAsAccount('author@demo.test');

        $this->postJson('/api/v1/articles', ['title' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'content']);
    }

    /* -------------------- Publishing and unpublishing -------------------- */

    /**
     * The most dangerous hole that was possible: an author publishing without
     * the publish permission.
     *
     * The interface does not show them a publish button, but hiding a button is
     * not protection — and curl walks straight past it. So the guard sits on
     * the server: status=published requires articles.publish.
     */
    public function test_an_author_cannot_publish_by_sending_the_status_directly(): void
    {
        $this->actingAsAccount('author@demo.test');

        $this->postJson('/api/v1/articles', [
            'title' => 'محاولة نشر',
            'content' => 'نصّ.',
            'status' => 'published',
        ])->assertStatus(422)->assertJsonValidationErrors('status');

        // And the request never reached the platform at all
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
     * Publishing and unpublishing are two separate permissions, and the seeder
     * genuinely uses that separation: the editor holds `articles.draft` and
     * does not hold `articles.publish`.
     *
     * That is, they write and pull a piece back out of publication to correct
     * it, while making it public stays with the content moderator. Had it been
     * a single permission, that distinction would have been lost.
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

        // "Published an article", not "edited" — the operation appears by its own name
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'articles.publish',
            'subject_id' => 'some-slug',
        ]);
    }
}
