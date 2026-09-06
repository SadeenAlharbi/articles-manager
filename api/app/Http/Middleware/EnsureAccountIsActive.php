<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * يوقف حساباً عُطِّل بعد أن أصدر توكنه.
 *
 * فحص تسجيل الدخول وحده لا يكفي: التوكن الذي صدر قبل التعطيل يبقى صالحاً،
 * فيستمر صاحبه في العمل رغم إيقافه. هذي الطبقة تُغلق تلك الفجوة على كل طلب.
 *
 * الرسالة تعيش هنا ويستدعيها AuthController أيضاً، فلا تتكرّر في مكانين.
 */
class EnsureAccountIsActive
{
    public const MESSAGE = 'عذراً، تم تعطيل حسابك من قبل إدارة النظام.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            /*
             * التوكن المستخدم في هذا الطلب يُحذف فوراً: لا معنى لإبقاء
             * مفتاح صالح بيد حساب موقوف. وباقي توكناته تُحذف عند التعطيل
             * نفسه في شاشة إدارة المستخدمين (المرحلة الرابعة).
             */
            $request->user()->currentAccessToken()?->delete();

            return response()->json(['message' => self::MESSAGE], 403);
        }

        return $next($request);
    }
}
