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
 * Guard over the OpenAPI specification.
 *
 * Generated documentation does not protect itself from going stale: generation
 * may stop with a silent error, a route may be added and never documented, or a
 * summary may be forgotten and the line come out blank. This actually happened
 * on the first platform — its hand-written documentation trailed the code by two
 * weeks before anyone noticed.
 *
 * The test generates the specification in memory (it does not read the exported
 * api.json), so it catches the drift the moment it happens rather than the
 * moment someone runs the export command.
 */
class ApiDocumentationTest extends TestCase
{
    /*
     * Nine of the ten cases read the specification from memory and never touch
     * the database, but one creates a user in order to exercise the
     * viewApiDocs gate — so the tables are required.
     */
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function spec(): array
    {
        return app(Generator::class)
            ->generate(Scramble::getGeneratorConfig('default'))
            ->spec();
    }

    /** The api/v1 routes actually in the router, in spec form (/users/{user}). */
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

    /* ------------------------- Generation itself ------------------------- */

    public function test_the_specification_generates(): void
    {
        $spec = $this->spec();

        $this->assertSame('3.1.0', $spec['openapi']);
        $this->assertNotEmpty($spec['paths']);
    }

    /**
     * The documentation page exposes the whole shape of the API — its routes,
     * its request bodies, its error codes. That is why RestrictedDocsAccess
     * guards it: open in the development environment alone, and closed with a
     * 403 everywhere else unless the viewApiDocs gate explicitly allows it.
     *
     * The test environment is not local, so the refusal here is the correct
     * behaviour rather than a fault.
     */
    public function test_the_documentation_page_is_not_public_outside_local(): void
    {
        $this->assertNotSame('local', app()->environment());

        $this->get('/docs/api')->assertForbidden();
    }

    /**
     * And it opens for whoever the gate allows — the guard restricts, it does
     * not disable.
     *
     * The gate is defined with a User argument rather than none: Gate::allows
     * rejects a guest automatically unless the first argument accepts null, and
     * that is deliberate. The realistic production shape is documentation open
     * to a signed-in administrator, not to visitors — so we sign a user in, as
     * would actually happen.
     */
    public function test_the_documentation_page_opens_for_an_allowed_viewer(): void
    {
        Gate::define('viewApiDocs', fn (User $user) => true);

        $this->actingAs(User::factory()->create())
            ->get('/docs/api')
            ->assertOk();
    }

    /* ------------------- No route without documentation ------------------ */

    public function test_every_registered_route_is_documented(): void
    {
        $documented = [];

        foreach ($this->spec()['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = "{$method} {$path}";
            }
        }

        sort($documented);

        // Both directions: no undocumented route, and no documented non-route.
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

    /* ------------------------------ Security ----------------------------- */

    public function test_login_is_the_only_public_operation(): void
    {
        $public = [];

        foreach ($this->spec()['paths'] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                // security: [] means "explicitly open"; absence inherits global security.
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

    /* ------------------------- No leaked secrets ------------------------- */

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

    /* --------------- Contracts that must not break silently -------------- */

    public function test_the_permissions_endpoint_documents_its_body(): void
    {
        $body = $this->spec()['paths']['/users/{user}/permissions']['put']['requestBody'];
        $schema = $body['content']['application/json']['schema'];

        // The contract: the complete effective set, not a list of additions.
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
