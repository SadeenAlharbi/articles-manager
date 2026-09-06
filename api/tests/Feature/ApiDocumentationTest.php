<?php

namespace Tests\Feature;

use App\Models\User;
use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * حارس مواصفة OpenAPI.
 *
 * التوثيق المولَّد لا يحمي نفسه من التقادم: قد يتوقّف التوليد بخطأ صامت، أو
 * يُضاف مسار فلا يُوثَّق، أو يُنسى وصف فيظهر السطر فارغاً. وهذا وقع فعلاً في
 * المنصّة الأولى — توثيقها اليدوي تخلّف عن الكود أسبوعين حتى انتبهنا.
 *
 * الاختبار يولّد المواصفة في الذاكرة (لا يقرأ api.json المصدَّر) فيلتقط
 * الانحراف لحظة وقوعه لا لحظة تشغيل أمر التصدير.
 */
class ApiDocumentationTest extends TestCase
{
    /*
     * تسع حالات من العشر تقرأ المواصفة من الذاكرة ولا تمسّ القاعدة، لكن حالة
     * واحدة تُنشئ مستخدماً لتجرّب بوابة viewApiDocs — فتلزم الجداول.
     */
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function spec(): array
    {
        return app(Generator::class)
            ->generate(Scramble::getGeneratorConfig('default'))
            ->spec();
    }

    /** أسماء مسارات api/v1 الفعلية في الموجّه، بصيغة المواصفة (/users/{user}). */
    private function registeredPaths(): array
    {
        $paths = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $paths[] = strtolower($method).' /'.substr($route->uri(), strlen('api/v1/'));
            }
        }

        sort($paths);

        return $paths;
    }

    /* ---------------------------- التوليد نفسه ---------------------------- */

    public function test_the_specification_generates(): void
    {
        $spec = $this->spec();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertNotEmpty($spec['paths']);
    }

    /**
     * صفحة التوثيق تكشف بنية الواجهة كاملة — مساراتها وأجسام طلباتها ورموز
     * أخطائها. ولهذا يحرسها RestrictedDocsAccess: مفتوحة في بيئة التطوير
     * وحدها، ومغلقة بـ403 في غيرها ما لم تسمح بوابة viewApiDocs صراحةً.
     *
     * بيئة الاختبار ليست local، فالرفض هنا هو السلوك الصحيح لا خللاً.
     */
    public function test_the_documentation_page_is_not_public_outside_local(): void
    {
        $this->assertNotSame('local', app()->environment());

        $this->get('/docs/api')->assertForbidden();
    }

    /**
     * وتنفتح لمن تسمح له البوابة — فالحارس يمنع ولا يُعطّل.
     *
     * البوابة تُعرَّف بوسيط User لا بلا وسائط: Gate::allows تردّ الزائر تلقائياً
     * ما لم يقبل أول وسيط قيمة null، وهذا مقصود. والشكل الواقعي في الإنتاج هو
     * فتح التوثيق لمشرف مسجَّل لا للزوّار — فنسجّل مستخدماً كما سيحدث فعلاً.
     */
    public function test_the_documentation_page_opens_for_an_allowed_viewer(): void
    {
        Gate::define('viewApiDocs', fn (User $user) => true);

        $this->actingAs(User::factory()->create())
            ->get('/docs/api')
            ->assertOk();
    }

    /* ------------------------- لا مسار بلا توثيق ------------------------- */

    public function test_every_registered_route_is_documented(): void
    {
        $documented = [];

        foreach ($this->spec()['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = "{$method} {$path}";
            }
        }

        sort($documented);

        // المقارنة في الاتجاهين: لا مسار غير موثَّق، ولا مسار موثَّق لا وجود له.
        $this->assertSame(
            $this->registeredPaths(),
            $documented,
            'المواصفة لا تطابق المسارات المسجَّلة — أُضيف مسار بلا توثيق أو حُذف مسار موثَّق.'
        );
    }

    public function test_every_operation_carries_a_summary(): void
    {
        $blank = [];

        foreach ($this->spec()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (blank($operation['summary'] ?? null)) {
                    $blank[] = strtoupper($method)." {$path}";
                }
            }
        }

        $this->assertSame([], $blank, 'عمليات بلا وصف: '.implode('، ', $blank));
    }

    /* ------------------------------- الأمان ------------------------------- */

    public function test_login_is_the_only_public_operation(): void
    {
        $public = [];

        foreach ($this->spec()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                // security: [] تعني «مفتوح صراحةً»؛ غيابها يعني وراثة الأمان العام.
                if (($operation['security'] ?? null) === []) {
                    $public[] = strtoupper($method)." {$path}";
                }
            }
        }

        $this->assertSame(['POST /login'], $public);
    }

    public function test_the_specification_declares_bearer_authentication(): void
    {
        $schemes = $this->spec()['components']['securitySchemes'] ?? [];

        $this->assertNotEmpty($schemes, 'لا يوجد مخطّط أمان — لن يظهر زر Authorize.');

        $scheme = reset($schemes);
        $this->assertSame('http', $scheme['type']);
        $this->assertSame('bearer', $scheme['scheme']);
    }

    /* ------------------------- لا تسريب أسرار ------------------------- */

    public function test_the_specification_ships_no_real_secret(): void
    {
        $json = json_encode($this->spec());

        foreach (['PLATFORM_TOKEN', 'DB_PASSWORD', 'APP_KEY'] as $key) {
            $value = env($key);

            if (filled($value)) {
                $this->assertStringNotContainsString(
                    (string) $value,
                    $json,
                    "قيمة {$key} تسرّبت داخل مواصفة OpenAPI."
                );
            }
        }
    }

    /* -------------------- عقود لا يجوز أن تنكسر بصمت -------------------- */

    public function test_the_permissions_endpoint_documents_its_body(): void
    {
        $body = $this->spec()['paths']['/users/{user}/permissions']['put']['requestBody'];
        $schema = $body['content']['application/json']['schema'];

        // العقد: المجموعة الفعّالة كاملة، لا قائمة إضافات.
        $this->assertSame('array', $schema['properties']['permissions']['type']);
        $this->assertContains('permissions', $schema['required']);
    }

    public function test_article_operations_document_the_platform_being_unreachable(): void
    {
        foreach ($this->spec()['paths'] as $path => $operations) {
            if (! str_starts_with($path, '/articles')) {
                continue;
            }

            foreach ($operations as $method => $operation) {
                $this->assertArrayHasKey(
                    '502',
                    $operation['responses'],
                    strtoupper($method)." {$path} لا يوثّق تعذّر الوصول إلى منصّة المعرفة."
                );
            }
        }
    }
}
