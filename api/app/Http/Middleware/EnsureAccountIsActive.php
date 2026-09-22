<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;


class EnsureAccountIsActive
{
    public const MESSAGE = 'عذراً، تم تعطيل حسابك من قبل إدارة النظام.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {

            $request->user()->currentAccessToken()?->delete();

            return response()->json(['message' => self::MESSAGE], 403);
        }

        return $next($request);
    }
}
