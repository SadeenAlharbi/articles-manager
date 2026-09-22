<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\FakesKnowledgePlatform;
use Tests\TestCase;

/**
 * What the dashboard asks of the knowledge platform when it lists articles.
 *
 * The knowledge platform is faked with Http::fake, and the test inspects the
 * **outgoing request** rather than what comes back: that is our layer, and a
 * parameter we drop here is one the platform never sees at all.
 *
 * This very case really happened: the door to viewing drafts was opened on the
 * platform before the dashboard started passing `status`, so the drafts stayed
 * invisible and the cause was on our side, not theirs.
 */
class ArticleListingTest extends TestCase
{
    use FakesKnowledgePlatform, RefreshDatabase;

    /**
     * The article as the platform currently sees it.
     *
     * Tests that need one particular prior state set this. The setUp stub
     * cannot be overridden by calling Http::fake inside the test — stubs are
     * appended, not consulted — so the stub reads this property at request time
     * instead of being frozen at registration time.
     */
    private array $article = [];

    /** Real categories from the knowledge platform — no invented data. */
    private const CATEGORIES = [
        ['id' => 1, 'name' => 'رؤية السعودية 2030', 'slug' => 'vision-2030'],
        ['id' => 2, 'name' => 'الحج والعمرة', 'slug' => 'hajj-umrah'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        /*
         * The trait guards the order: specific first, catch-all last. And the
         * catch-all is a closure that reads $article at request time, so a test
         * sets the state before it runs instead of re-registering a stub that
         * would never be consulted.
         */
        $this->fakePlatform(
            ['*/api/v1/tags' => Http::response(['data' => self::CATEGORIES, 'message' => 'OK'], 200)],
            fn () => Http::response(['data' => $this->article, 'message' => 'ok'], 200),
        );
    }

    /**
     * The URL actually sent to the platform, with its encoding undone.
     *
     * We do not use parse_str: it turns the query string into PHP variables,
     * which substitutes characters inside the names and corrupts multi-byte
     * text — and the search here is Arabic. urldecode on the whole URL gives
     * back intact UTF-8.
     */
    private function sentUrl(): string
    {
        $url = '';

        Http::assertSent(function ($request) use (&$url) {
            $url = urldecode($request->url());

            return true;
        });

        return $url;
    }

    public function test_the_status_filter_reaches_the_platform(): void
    {
        $this->actingAsAccount('admin@demo.test');

        $this->getJson('/api/v1/articles?status=draft')->assertOk();

        $this->assertStringContainsString('status=draft', $this->sentUrl());
    }

    public function test_search_and_paging_reach_the_platform(): void
    {
        $this->actingAsAccount('admin@demo.test');

        /*
         * urlencode, not raw text: the URL standard requires anything outside
         * ASCII to be encoded, and every real client does it automatically (the
         * browser, and URLSearchParams in api.js). Sending the bytes raw here
         * would have Symfony run them through parse_str and mangle them — that
         * is, the test would be sending something no client ever sends.
         */
        $this->getJson(
            '/api/v1/articles?search='.urlencode('ميناء').'&per_page=12&sort=oldest&tag=tourism'
        )->assertOk();

        $url = $this->sentUrl();

        // Arabic survives the wire intact — no small thing in an all-Arabic project
        $this->assertStringContainsString('search=ميناء', $url);
        $this->assertStringContainsString('per_page=12', $url);
        $this->assertStringContainsString('sort=oldest', $url);
        $this->assertStringContainsString('tag=tourism', $url);
    }

    /** We invent no parameters: what was not sent is not passed on. */
    public function test_nothing_is_invented_when_no_filter_is_given(): void
    {
        $this->actingAsAccount('admin@demo.test');

        $this->getJson('/api/v1/articles')->assertOk();

        // No question mark at all: nothing unasked-for was passed along
        $this->assertStringNotContainsString('?', $this->sentUrl());
    }

    /** The permission is checked before the platform connection is touched at all. */
    public function test_a_user_without_articles_view_never_reaches_the_platform(): void
    {
        $user = User::where('email', 'viewer@demo.test')->firstOrFail();
        $user->syncRoles([]);
        $user->syncPermissions([]);

        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/articles?status=draft')->assertForbidden();

        Http::assertNothingSent();
    }

    /* ----------------------------- Categories ---------------------------- */

    public function test_categories_come_from_the_platform(): void
    {
        $this->actingAsAccount('admin@demo.test');

        $this->getJson('/api/v1/articles/categories')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'vision-2030')
            ->assertJsonPath('data.1.name', 'الحج والعمرة');

        $this->assertStringContainsString('/api/v1/tags', $this->sentUrl());
    }

    /**
     * The explicit clause in the specification: no copy of the categories lives
     * in this project.
     *
     * The test inspects the database schema itself rather than the code — so if
     * someone later creates such a table in good faith, it fails right here.
     */
    public function test_no_categories_table_exists_in_this_database(): void
    {
        foreach (['tags', 'categories', 'article_tag', 'posts', 'comments'] as $table) {
            $this->assertFalse(
                Schema::hasTable($table),
                "الجدول {$table} موجود — المشروع الثاني لا يخزّن محتوى المنصّة."
            );
        }
    }

    /** "categories" is a route name, not a slug — the order in api.php ensures it. */
    public function test_the_categories_route_is_not_swallowed_by_the_slug_route(): void
    {
        $this->actingAsAccount('admin@demo.test');

        $this->getJson('/api/v1/articles/categories')->assertOk();

        // If the article route had caught it, /posts/categories would be requested
        $this->assertStringContainsString('/tags', $this->sentUrl());
        $this->assertStringNotContainsString('/posts/categories', $this->sentUrl());
    }

    /* ------------------- Truthfulness of the update log ------------------ */

    /**
     * The log states what actually changed, not what was submitted.
     *
     * The front end sends the whole form on every save. So if we logged what
     * arrived, the log would say the title changed when it had not — and an
     * innocent person would be blamed the day someone asks "who changed the
     * title?".
     */
    public function test_the_audit_records_only_the_fields_that_actually_changed(): void
    {
        $this->article = [
            'slug' => 'mynaaa-alkhbr',
            'title' => 'ميناء الخبر',
            'content' => 'نصّ المقال كما هو.',
            'tags' => [['id' => 1, 'name' => 'الآثار والتراث', 'slug' => 'heritage']],
        ];

        $this->actingAsAccount('admin@demo.test');

        // Title and content unchanged; the tags alone are different
        $this->putJson('/api/v1/articles/mynaaa-alkhbr', [
            'title' => 'ميناء الخبر',
            'content' => 'نصّ المقال كما هو.',
            'tags' => ['heritage', 'vision-2030'],
        ])->assertOk();

        $log = AuditLog::where('action', 'articles.update')->latest('id')->firstOrFail();

        $this->assertSame(['tags'], $log->payload['fields']);
        $this->assertSame('ميناء الخبر', $log->payload['title']);
    }

    /** A save with no real edit records an empty list, not a false one. */
    public function test_saving_without_changing_anything_records_no_fields(): void
    {
        $this->article = ['slug' => 'x', 'title' => 'عنوان', 'content' => 'محتوى', 'tags' => []];

        $this->actingAsAccount('admin@demo.test');

        $this->putJson('/api/v1/articles/x', [
            'title' => 'عنوان',
            'content' => 'محتوى',
            'tags' => [],
        ])->assertOk();

        $log = AuditLog::where('action', 'articles.update')->latest('id')->firstOrFail();

        $this->assertSame([], $log->payload['fields']);
    }

    /** The platform being unreachable turns into a 502, not a crash. */
    public function test_an_unreachable_platform_becomes_a_502(): void
    {
        $this->fakePlatformUnreachable();

        $this->actingAsAccount('admin@demo.test');

        $this->getJson('/api/v1/articles')->assertStatus(502);
    }
}
