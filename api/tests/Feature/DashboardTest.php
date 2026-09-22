<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesKnowledgePlatform;
use Tests\TestCase;

/**
 * The dashboard.
 *
 * The figures are assembled on the server from the knowledge platform and from
 * the audit log. The test guards three contracts: that the permission is
 * enforced, that the counts come from the platform's own pagination rather than
 * from counting rows, and that an unreachable platform answers "we do not know"
 * rather than a false zero.
 */
class DashboardTest extends TestCase
{
    use FakesKnowledgePlatform, RefreshDatabase;

    /**
     * Is the platform down in this test?
     *
     * The setUp stub cannot be overridden by calling Http::fake inside the test
     * — stubs are appended, not consulted, and the first non-empty response
     * wins. So the stub is a closure that reads this property at request time,
     * and the test flips it before the request runs.
     */
    private bool $platformIsDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->fakePlatform([
            '*/api/v1/tags' => fn () => $this->reply([
                'data' => [
                    ['id' => 1, 'name' => 'الآثار والتراث', 'slug' => 'heritage', 'posts_count' => 7],
                    ['id' => 2, 'name' => 'رؤية السعودية 2030', 'slug' => 'vision-2030', 'posts_count' => 3],
                    ['id' => 3, 'name' => 'الفنون السعودية', 'slug' => 'arts', 'posts_count' => 0],
                ],
                'message' => 'OK',
            ]),
        ], fn () => $this->reply([
            'data' => [[
                'slug' => 'mynaaa-alkhbr',
                'title' => 'ميناء الخبر',
                'status' => 'published',
                'status_label' => 'منشور',
                'views_count' => 42,
                'comments_count' => 3,
                'published_at' => '2026-09-01T00:00:00Z',
            ]],
            'meta' => ['total' => 12],
            'message' => 'OK',
        ]));
    }

    /** The platform's reply: the usual payload, or 500 if it is down in this test. */
    private function reply(array $payload)
    {
        return $this->platformIsDown
            ? Http::response(['message' => 'Server Error'], 500)
            : Http::response($payload, 200);
    }

    /* ----------------------------- Permission ---------------------------- */

    public function test_analytics_view_is_required(): void
    {
        // The author does not hold analytics.view
        $this->actingAsAccount('author@demo.test');

        $this->getJson('/api/v1/dashboard')->assertForbidden();

        // And the platform connection was never touched at all
        Http::assertNothingSent();
    }

    public function test_a_moderator_may_open_the_dashboard(): void
    {
        $this->actingAsAccount('moderator@demo.test');

        $this->getJson('/api/v1/dashboard')->assertOk();
    }

    /** A single direct grant is enough: being a moderator is not required. */
    public function test_a_direct_grant_alone_opens_the_dashboard(): void
    {
        $user = $this->actingAsAccount('viewer@demo.test');
        $user->givePermissionTo('analytics.view');

        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/dashboard')->assertOk();
    }

    /* ---------------------------- The numbers ---------------------------- */

    public function test_counts_come_from_the_platform_pagination(): void
    {
        $this->actingAsAccount('moderator@demo.test');

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('counts.all', 12)
            ->assertJsonPath('counts.published', 12)
            ->assertJsonPath('counts.draft', 12);

        /*
         * per_page=1 is deliberate: all we need is meta.total. Asking for the
         * full page in order to count it would move thousands of articles
         * across the network for no reason.
         */
        Http::assertSent(fn ($request) => str_contains($request->url(), 'per_page=1'));
    }

    public function test_top_categories_are_sorted_and_exclude_empty_ones(): void
    {
        $this->actingAsAccount('moderator@demo.test');

        $response = $this->getJson('/api/v1/dashboard')->assertOk();

        $categories = $response->json('top_categories');

        // Highest first, and an empty category never enters the "most published" list
        $this->assertSame(['heritage', 'vision-2030'], array_column($categories, 'slug'));
        $this->assertSame(7, $categories[0]['posts_count']);
    }

    public function test_latest_operations_come_from_the_audit_log(): void
    {
        $actor = $this->actingAsAccount('moderator@demo.test');

        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'articles.publish',
            'subject_type' => 'article',
            'subject_id' => 'mynaaa-alkhbr',
            'payload' => ['title' => 'ميناء الخبر'],
            'succeeded' => true,
        ]);

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('latest_operations.0.action', 'articles.publish')
            ->assertJsonPath('latest_operations.0.payload.title', 'ميناء الخبر');
    }

    /* ------------------------------ No lying ----------------------------- */

    /**
     * An unreachable platform returns null, not zero.
     *
     * Zero says "there are no articles"; absence says "we do not know".
     * Presenting the two as the same thing lies to whoever reads the dashboard
     * — and your clause forbids fabricated data outright.
     */
    public function test_an_unreachable_platform_reports_unknown_not_zero(): void
    {
        $this->platformIsDown = true;

        $this->actingAsAccount('moderator@demo.test');

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('counts.all', null)
            ->assertJsonPath('latest_articles', [])
            ->assertJsonPath('top_categories', []);
    }
}
