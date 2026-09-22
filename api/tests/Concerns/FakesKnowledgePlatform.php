<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use LogicException;

/**
 * Faking the knowledge platform in tests.
 *
 * This trait exists because of a bug that was hit three times in this project:
 *
 * Http::fake **appends** stubs, it does not replace them, and PendingRequest
 * builds its handler with ->map->__invoke(...)->filter()->first() — meaning the
 * **first** non-empty response wins. So a '*' rule registered before a specific
 * one swallows it with no visible error: a response unrelated to the URL comes
 * back, and the test fails somewhere far away from the cause.
 *
 * fakePlatform makes that impossible structurally rather than by convention:
 * the specific rules always come first, the catch-all always last, and the test
 * has no way to reverse them.
 */
trait FakesKnowledgePlatform
{
    /**
     * Registers the platform stubs in the guaranteed correct order.
     *
     * @param  array<string, mixed>  $routes  specific rules keyed by URL pattern
     * @param  mixed  $fallback  the catch-all rule for anything else
     */
    protected function fakePlatform(array $routes, mixed $fallback): void
    {
        if (array_key_exists('*', $routes)) {
            throw new LogicException(
                'القاعدة الشاملة تُمرَّر في $fallback لا في $routes: ترتيبها هو ما تحرسه هذي السمة.'
            );
        }

        // Array union preserves key order: the specific rules first, then '*'
        Http::fake($routes + ['*' => $fallback]);
    }

    /**
     * Makes every subsequent request fail to connect — call it inside the test,
     * not in setUp.
     *
     * It works even though Http::fake appends rather than replaces:
     * buildStubHandler invokes **every** stub (map is eager) before picking the
     * first non-empty one, so the exception is thrown during that invocation,
     * not after the selection.
     *
     * Relying on an internal detail like this is delicate, so it is confined to
     * a single documented place instead of being repeated across the tests with
     * no explanation.
     */
    protected function fakePlatformUnreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('unreachable'));
    }

    /** Signs in as one of the seeded accounts, and returns it. */
    protected function actingAsAccount(string $email): User
    {
        $user = User::where('email', $email)->firstOrFail();

        Sanctum::actingAs($user);

        return $user;
    }
}
