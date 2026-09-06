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
 * ما تطلبه اللوحة من منصّة المعرفة حين تعرض المقالات.
 *
 * منصّة المعرفة مُزيَّفة بـHttp::fake، والاختبار يفحص **الطلب الصادر** لا ما
 * يعود: هذي هي طبقتنا، وأي معامل نُسقطه هنا لا تراه المنصّة أصلاً.
 *
 * وهذي الحالة وقعت فعلاً: الباب لعرض المسودات فُتح في المنصّة قبل أن تمرّر
 * اللوحة `status`، فبقيت المسودات غير مرئية والسبب في طرفنا لا طرفها.
 */
class ArticleListingTest extends TestCase
{
    use FakesKnowledgePlatform, RefreshDatabase;

    /**
     * المقال كما تراه المنصّة الآن.
     *
     * تضبطه الاختبارات التي تحتاج حالة سابقة بعينها. ولا يمكن تجاوز محاكاة
     * setUp باستدعاء Http::fake داخل الاختبار — تُلحَق ولا تُستشار — فالمحاكاة
     * تقرأ هذي الخاصية لحظة الطلب بدل أن تُثبَّت وقت التسجيل.
     */
    private array $article = [];

    /** تصنيفات حقيقية من منصّة المعرفة — لا بيانات مخترعة. */
    private const CATEGORIES = [
        ['id' => 1, 'name' => 'رؤية السعودية 2030', 'slug' => 'vision-2030'],
        ['id' => 2, 'name' => 'الحج والعمرة', 'slug' => 'hajj-umrah'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        /*
         * الترتيب تحرسه السمة: المخصّص أولاً والشامل أخيراً. والقاعدة الشاملة
         * دالةٌ تقرأ $article لحظة الطلب، فالاختبار يضبط الحالة قبل أن يعمل
         * بدل أن يعيد تسجيل محاكاة لن تُستشار.
         */
        $this->fakePlatform(
            ['*/api/v1/tags' => Http::response(['data' => self::CATEGORIES, 'message' => 'OK'], 200)],
            fn () => Http::response(['data' => $this->article, 'message' => 'ok'], 200),
        );
    }

    /**
     * العنوان المرسَل فعلاً إلى المنصّة، مفكوك الترميز.
     *
     * لا نستعمل parse_str: هي تحوّل الاستعلام إلى متغيّرات PHP فتستبدل محارف
     * في الأسماء وتُفسد النصّ متعدّد البايت — والبحث هنا عربي. urldecode على
     * العنوان كاملاً يعيد UTF-8 سليماً.
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
         * urlencode لا نصّ خام: معيار الـURL يوجب ترميز ما خرج عن ASCII، وأي
         * عميل حقيقي يفعله تلقائياً (المتصفّح، و URLSearchParams في api.js).
         * إرسال البايتات خاماً هنا يجعل Symfony يمرّرها على parse_str فتتلف —
         * أي أن الاختبار يرسل ما لا يرسله عميل قطّ.
         */
        $this->getJson(
            '/api/v1/articles?search='.urlencode('ميناء').'&per_page=12&sort=oldest&tag=tourism'
        )->assertOk();

        $url = $this->sentUrl();

        // العربية تصل سليمة عبر الشبكة — ليست تفصيلة في مشروع عربي بالكامل
        $this->assertStringContainsString('search=ميناء', $url);
        $this->assertStringContainsString('per_page=12', $url);
        $this->assertStringContainsString('sort=oldest', $url);
        $this->assertStringContainsString('tag=tourism', $url);
    }

    /** لا نخترع معاملات: ما لم يُرسل لا يُمرَّر. */
    public function test_nothing_is_invented_when_no_filter_is_given(): void
    {
        $this->actingAsAccount('admin@demo.test');

        $this->getJson('/api/v1/articles')->assertOk();

        // لا علامة استفهام أصلاً: لم يُمرَّر أي معامل لم يُطلَب
        $this->assertStringNotContainsString('?', $this->sentUrl());
    }

    /** الصلاحية تُفحص قبل أن يُلمس الاتصال بالمنصّة أصلاً. */
    public function test_a_user_without_articles_view_never_reaches_the_platform(): void
    {
        $user = User::where('email', 'viewer@demo.test')->firstOrFail();
        $user->syncRoles([]);
        $user->syncPermissions([]);

        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/articles?status=draft')->assertForbidden();

        Http::assertNothingSent();
    }

    /* ------------------------------ التصنيفات ------------------------------ */

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
     * البند الصريح في المواصفة: لا نسخة من التصنيفات في هذا المشروع.
     *
     * الاختبار يفحص بنية القاعدة نفسها لا الكود — فلو أنشأ أحد جدولاً لاحقاً
     * بحسن نيّة، سقط هنا فوراً.
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

    /** «categories» اسم مسار لا اسم مقال — الترتيب في api.php يضمن ذلك. */
    public function test_the_categories_route_is_not_swallowed_by_the_slug_route(): void
    {
        $this->actingAsAccount('admin@demo.test');

        $this->getJson('/api/v1/articles/categories')->assertOk();

        // لو التقطه مسار المقال لطُلب /posts/categories بدل /tags
        $this->assertStringContainsString('/tags', $this->sentUrl());
        $this->assertStringNotContainsString('/posts/categories', $this->sentUrl());
    }

    /* ------------------------- صدق سجلّ التعديل ------------------------- */

    /**
     * السجلّ يذكر ما تغيّر فعلاً لا ما أُرسل.
     *
     * الواجهة ترسل النموذج كاملاً في كل حفظ. فلو سجّلنا ما وصل، لقال السجلّ إن
     * العنوان تغيّر وهو لم يتغيّر — واتُّهم بريء لو سُئل «مَن غيّر العنوان؟».
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

        // العنوان والمحتوى كما هما، والتصنيفات وحدها اختلفت
        $this->putJson('/api/v1/articles/mynaaa-alkhbr', [
            'title' => 'ميناء الخبر',
            'content' => 'نصّ المقال كما هو.',
            'tags' => ['heritage', 'vision-2030'],
        ])->assertOk();

        $log = AuditLog::where('action', 'articles.update')->latest('id')->firstOrFail();

        $this->assertSame(['tags'], $log->payload['fields']);
        $this->assertSame('ميناء الخبر', $log->payload['title']);
    }

    /** حفظ بلا تعديل فعلي يُسجَّل قائمة فارغة لا قائمة كاذبة. */
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

    /** تعذّر الوصول إلى المنصّة يُترجَم إلى 502 لا إلى انهيار. */
    public function test_an_unreachable_platform_becomes_a_502(): void
    {
        $this->fakePlatformUnreachable();

        $this->actingAsAccount('admin@demo.test');

        $this->getJson('/api/v1/articles')->assertStatus(502);
    }
}
