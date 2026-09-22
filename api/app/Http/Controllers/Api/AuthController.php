<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Authentication for the article management system.
 *
 * The users of this system are not the users of the knowledge platform: a
 * separate database, separate accounts, a separate life cycle. The two
 * identities are never mixed.
 *
 * No sessions and no cookies: the front end is a standalone React app, so
 * authentication is a Sanctum token sent in the Authorization header with
 * every request.
 */
#[Group('المصادقة', 'تسجيل الدخول والخروج وبيانات المستخدم الحالي.', weight: 0)]
class AuthController extends Controller
{
    /**
     * Signing in.
     *
     * The only route open without a token, and it is capped at 5 attempts per
     * minute. It returns a Sanctum token that is sent from then on in the
     * `Authorization: Bearer` header.
     *
     * The disabled-account check deliberately comes **after** the password
     * check: had it come first, the mere difference in the message would reveal
     * which email addresses are registered in the system.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        /*
         * One check for both cases — an email that does not exist and a wrong
         * password — with one and the same message. Were they told apart,
         * anybody could discover which addresses are registered in the system
         * (User Enumeration).
         */
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['بيانات الدخول غير صحيحة.'],
            ]);
        }

        /*
         * A disabled account: it is rejected after the password has been
         * verified, not before.
         *
         * The ordering is intentional — if we returned the disabled message
         * before checking the password, anybody could learn that some address
         * is registered and disabled just by guessing the address. As it
         * stands, that information reaches them only if they already hold the
         * correct password.
         *
         * And 403, not 401: identity was proven, but access is denied.
         */
        if (! $user->is_active) {
            return response()->json(['message' => EnsureAccountIsActive::MESSAGE], 403);
        }

        $token = $user->createToken('admin-panel')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->profile($user),
            'platform_url' => $this->platformUrl(),
        ]);
    }

    /** The current user's details — the front end calls this on every load. */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->profile($request->user()),
            'platform_url' => $this->platformUrl(),
        ]);
    }

    /** The Arabic name of the user's role, or null if they have none. */
    private function roleLabel(User $user): ?string
    {
        $role = $user->getRoleNames()->first();

        return $role ? (RolesAndPermissionsSeeder::ROLES[$role]['label'] ?? $role) : null;
    }

    /**
     * The public address of the knowledge platform — the front end builds
     * article links out of it.
     *
     * It is sent from the server rather than repeated in the front end's .env:
     * one single source of truth for the platform's address. And it is a public
     * address that any visitor can see, unlike PLATFORM_TOKEN, which never
     * leaves the server at all.
     */
    private function platformUrl(): ?string
    {
        $url = config('services.platform.base_url');

        return $url ? rtrim((string) $url, '/') : null;
    }

    /**
     * Signing out.
     *
     * It deletes only the token used in this request — so the user's other
     * sessions on other devices keep working.
     */
    public function logout(Request $request): JsonResponse
    {
        // Only the row of the token used in this request is deleted, so the user's other devices are untouched.
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }

    /**
     * The user profile in the shape the front end needs.
     *
     * getAllPermissions() gathers the role permissions and the direct grants
     * together, so the front end receives one final list and works nothing out
     * for itself.
     *
     * A warning: this list is for hiding buttons, nothing more. The real check
     * happens on the server on every route — hiding a button is not security.
     */
    private function profile(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'roles' => $user->getRoleNames(),
            // The Arabic name of the role — the front end shows the label, not the programmatic identifier
            'role_label' => $this->roleLabel($user),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ];
    }
}
