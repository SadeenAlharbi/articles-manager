<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authentication for the admin system, and account state.
 *
 * Covers the requirement: a disabled account cannot sign in, it is told
 * plainly why, and a token issued to it before it was disabled does not live on.
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

    /* ----------------------------- Signing in ---------------------------- */

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
     * An unknown email and a wrong password give exactly the same response,
     * so nobody can discover which addresses are registered in the system.
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

    /* --------------------------- Account state --------------------------- */

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
     * The gap the login check does not close: a token issued before disabling.
     */
    public function test_a_token_issued_before_disabling_stops_working(): void
    {
        $user = User::where('email', 'editor@demo.test')->firstOrFail();
        $token = $user->createToken('test')->plainTextToken;

        // The account is still active — the token works.
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $user->update(['is_active' => false]);

        /*
         * In the tests the application is booted once, and the auth guard holds
         * on to the user object it resolved during the first request — so it
         * never sees the account being disabled. In production every request is
         * a new process that reads the user from the database. We clear the
         * guard here to reproduce that, not to get around the check.
         */
        $this->app['auth']->forgetGuards();

        // Once disabled, that same token is refused.
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

    /* ---------------------------- Signing out ---------------------------- */

    public function test_logging_out_deletes_only_the_current_token(): void
    {
        $user = User::where('email', 'admin@demo.test')->firstOrFail();
        $first = $user->createToken('device-a')->plainTextToken;
        $user->createToken('device-b');

        $this->withToken($first)->postJson('/api/v1/logout')->assertOk();

        $this->assertSame(1, $user->fresh()->tokens()->count());
    }
}
