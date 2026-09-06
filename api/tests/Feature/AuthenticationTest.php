<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مصادقة نظام الإدارة وحالة الحساب.
 *
 * تغطّي المتطلّب: الحساب المعطَّل لا يدخل، وتظهر له رسالة واضحة،
 * ولا يستمر توكن أُصدر له قبل التعطيل.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private const LOGIN = '/api/v1/login';

    /* ------------------------------ الدخول ------------------------------ */

    public function test_a_valid_account_receives_a_token_and_its_permissions(): void
    {
        $response = $this->postJson(self::LOGIN, [
            'email' => 'admin@demo.test',
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'is_active', 'roles', 'permissions']]);

        $this->assertNotEmpty($response->json('token'));
        $this->assertTrue($response->json('user.is_active'));
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $this->postJson(self::LOGIN, [
            'email' => 'admin@demo.test',
            'password' => 'not-the-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    /**
     * البريد غير الموجود وكلمة السر الخاطئة يعطيان الردّ نفسه،
     * فلا يستطيع أحد اكتشاف البُرد المسجّلة في النظام.
     */
    public function test_an_unknown_email_is_indistinguishable_from_a_wrong_password(): void
    {
        $unknown = $this->postJson(self::LOGIN, [
            'email' => 'nobody@demo.test',
            'password' => 'password',
        ]);

        $wrongPassword = $this->postJson(self::LOGIN, [
            'email' => 'admin@demo.test',
            'password' => 'not-the-password',
        ]);

        $unknown->assertStatus(422);
        $wrongPassword->assertStatus(422);
        $this->assertSame($wrongPassword->json('message'), $unknown->json('message'));
    }

    /* --------------------------- حالة الحساب ---------------------------- */

    public function test_a_disabled_account_cannot_log_in_and_is_told_why(): void
    {
        User::where('email', 'editor@demo.test')->update(['is_active' => false]);

        $this->postJson(self::LOGIN, [
            'email' => 'editor@demo.test',
            'password' => 'password',
        ])
            ->assertStatus(403)
            ->assertJson(['message' => EnsureAccountIsActive::MESSAGE]);
    }

    public function test_disabling_an_account_does_not_delete_it(): void
    {
        $user = User::where('email', 'editor@demo.test')->firstOrFail();
        $user->update(['is_active' => false]);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'is_active' => false]);
        $this->assertSame(['editor'], $user->fresh()->getRoleNames()->all());
    }

    /**
     * الفجوة التي لا يسدّها فحص تسجيل الدخول: توكن أُصدر قبل التعطيل.
     */
    public function test_a_token_issued_before_disabling_stops_working(): void
    {
        $user = User::where('email', 'editor@demo.test')->firstOrFail();
        $token = $user->createToken('test')->plainTextToken;

        // ما زال الحساب نشطاً — التوكن يعمل.
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $user->update(['is_active' => false]);

        /*
         * في الاختبارات يُبنى التطبيق مرة واحدة، وحارس المصادقة يحتفظ
         * بكائن المستخدم الذي حلّه في الطلب الأول — فلا يرى التعطيل.
         * في الإنتاج كل طلب عملية جديدة تقرأ المستخدم من قاعدة البيانات.
         * نمسح الحارس هنا لنُحاكي ذلك، لا لنتجاوز الفحص.
         */
        $this->app['auth']->forgetGuards();

        // بعد التعطيل، التوكن نفسه يُرفض.
        $this->withToken($token)->getJson('/api/v1/me')
            ->assertStatus(403)
            ->assertJson(['message' => EnsureAccountIsActive::MESSAGE]);
    }

    public function test_a_rejected_token_is_deleted_so_it_cannot_be_retried(): void
    {
        $user = User::where('email', 'editor@demo.test')->firstOrFail();
        $token = $user->createToken('test')->plainTextToken;

        $user->update(['is_active' => false]);
        $this->withToken($token)->getJson('/api/v1/me')->assertStatus(403);

        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    /* ------------------------------ الخروج ------------------------------ */

    public function test_logging_out_deletes_only_the_current_token(): void
    {
        $user = User::where('email', 'admin@demo.test')->firstOrFail();
        $first = $user->createToken('device-a')->plainTextToken;
        $user->createToken('device-b');

        $this->withToken($first)->postJson('/api/v1/logout')->assertOk();

        $this->assertSame(1, $user->fresh()->tokens()->count());
    }
}
