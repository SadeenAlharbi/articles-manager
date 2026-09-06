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
 * لوحة المعلومات.
 *
 * الأرقام تُجمَّع في الخادم من منصّة المعرفة ومن سجلّ العمليات. والاختبار يحرس
 * ثلاثة عقود: أن الصلاحية تُفرض، وأن الأرقام تأتي من ترقيم المنصّة لا من عدّ
 * الصفوف، وأن تعذّر المنصّة يُرجع «لا نعرف» لا صفراً كاذباً.
 */
class DashboardTest extends TestCase
{
    use FakesKnowledgePlatform, RefreshDatabase;

    /**
     * هل المنصّة متعطّلة في هذا الاختبار؟
     *
     * لا يمكن تجاوز محاكاة setUp باستدعاء Http::fake داخل الاختبار — فهي
     * تُلحَق ولا تُستشار، وأول ردّ غير فارغ يفوز. فالمحاكاة دالةٌ تقرأ هذي
     * الخاصية لحظة الطلب، والاختبار يقلبها قبل أن يعمل.
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

    /** ردّ المنصّة: الحمولة المعتادة، أو 500 إن كانت متعطّلة في هذا الاختبار. */
    private function reply(array $payload)
    {
        return $this->platformIsDown
            ? Http::response(['message' => 'Server Error'], 500)
            : Http::response($payload, 200);
    }

    /* ------------------------------- الصلاحية ------------------------------ */

    public function test_analytics_view_is_required(): void
    {
        // الكاتب لا يملك analytics.view
        $this->actingAsAccount('author@demo.test');

        $this->getJson('/api/v1/dashboard')->assertForbidden();

        // ولم يُلمس الاتصال بالمنصّة أصلاً
        Http::assertNothingSent();
    }

    public function test_a_moderator_may_open_the_dashboard(): void
    {
        $this->actingAsAccount('moderator@demo.test');

        $this->getJson('/api/v1/dashboard')->assertOk();
    }

    /** صلاحية فردية تكفي: لا يلزم أن يكون مشرفاً. */
    public function test_a_direct_grant_alone_opens_the_dashboard(): void
    {
        $user = $this->actingAsAccount('viewer@demo.test');
        $user->givePermissionTo('analytics.view');

        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/dashboard')->assertOk();
    }

    /* -------------------------------- الأرقام ------------------------------ */

    public function test_counts_come_from_the_platform_pagination(): void
    {
        $this->actingAsAccount('moderator@demo.test');

        $this->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('counts.all', 12)
            ->assertJsonPath('counts.published', 12)
            ->assertJsonPath('counts.draft', 12);

        /*
         * per_page=1 مقصود: نحتاج meta.total وحده. طلب الصفحة كاملة لعدّها
         * ينقل آلاف المقالات عبر الشبكة بلا سبب.
         */
        Http::assertSent(fn ($request) => str_contains($request->url(), 'per_page=1'));
    }

    public function test_top_categories_are_sorted_and_exclude_empty_ones(): void
    {
        $this->actingAsAccount('moderator@demo.test');

        $response = $this->getJson('/api/v1/dashboard')->assertOk();

        $categories = $response->json('top_categories');

        // الأكثر أولاً، والتصنيف بلا مقالات لا يظهر في قائمة «الأكثر نشراً»
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

    /* ------------------------------ لا كذب ------------------------------ */

    /**
     * تعذّر المنصّة يُرجع null لا صفراً.
     *
     * الصفر يقول «لا مقالات»، والغياب يقول «لا نعرف». عرضهما متطابقَين يكذب
     * على قارئ اللوحة — وبندك يمنع البيانات الوهمية صراحةً.
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
