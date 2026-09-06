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
 * مصادقة نظام إدارة المقالات.
 *
 * مستخدمو هذا النظام ليسوا مستخدمي منصّة المعرفة: قاعدة بيانات مستقلة،
 * وحسابات مستقلة، ودورة حياة مستقلة. لا تُخلط الهويتان.
 *
 * لا جلسات ولا كوكيز: الواجهة تطبيق React منفصل، فالمصادقة بتوكن Sanctum
 * يُرسَل في ترويسة Authorization مع كل طلب.
 */
#[Group('المصادقة', 'تسجيل الدخول والخروج وبيانات المستخدم الحالي.', weight: 0)]
class AuthController extends Controller
{
    /**
     * تسجيل الدخول.
     *
     * المسار الوحيد المفتوح بلا توكن، ومحدود بـ5 محاولات في الدقيقة. يُرجع توكن
     * Sanctum يُرسَل بعدها في ترويسة `Authorization: Bearer`.
     *
     * فحص التعطيل يقع **بعد** فحص كلمة المرور عمداً: لو سبقه لأمكن معرفة أي
     * البُرد مسجَّلة في النظام بمجرد اختلاف الرسالة.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        /*
         * فحص واحد للحالتين — بريد غير موجود وكلمة سر خاطئة — برسالة واحدة.
         * لو فُرِّق بينهما لأمكن لأي شخص أن يكتشف البُرد المسجّلة في النظام
         * (User Enumeration).
         */
        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['بيانات الدخول غير صحيحة.'],
            ]);
        }

        /*
         * الحساب المعطَّل: يُرفض بعد التحقّق من كلمة المرور لا قبله.
         *
         * الترتيب مقصود — لو رددنا رسالة التعطيل قبل فحص كلمة المرور،
         * لصار بإمكان أي شخص أن يعرف أن بريداً ما مسجَّل ومعطَّل بمجرد
         * تخمين البريد. الآن لا تصله هذي المعلومة إلا إن كان يملك كلمة
         * المرور الصحيحة أصلاً.
         *
         * و403 لا 401: الهوية أُثبتت، لكن الوصول ممنوع.
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

    /** بيانات المستخدم الحالي — تستدعيها الواجهة عند كل تحميل. */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->profile($request->user()),
            'platform_url' => $this->platformUrl(),
        ]);
    }

    /** الاسم العربي لدور المستخدم، أو null إن لم يكن له دور. */
    private function roleLabel(User $user): ?string
    {
        $role = $user->getRoleNames()->first();

        return $role ? (RolesAndPermissionsSeeder::ROLES[$role]['label'] ?? $role) : null;
    }

    /**
     * العنوان العام لمنصّة المعرفة — تبني منه الواجهة روابط المقالات.
     *
     * يُرسَل من الخادم لا يُكرَّر في .env الواجهة: مصدر حقيقة واحد
     * لعنوان المنصّة. وهو عنوان عام يراه أي زائر، بخلاف PLATFORM_TOKEN
     * الذي لا يغادر الخادم إطلاقاً.
     */
    private function platformUrl(): ?string
    {
        $url = config('services.platform.base_url');

        return $url ? rtrim((string) $url, '/') : null;
    }

    /**
     * تسجيل الخروج.
     *
     * يحذف التوكن المستعمل في هذا الطلب وحده — فتبقى جلسات المستخدم الأخرى
     * على أجهزة أخرى تعمل.
     */
    public function logout(Request $request): JsonResponse
    {
        // يُحذف صف التوكن المستخدم في هذا الطلب وحده، فلا تتأثر أجهزته الأخرى.
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'تم تسجيل الخروج.']);
    }

    /**
     * ملف المستخدم كما تحتاجه الواجهة.
     *
     * getAllPermissions() تجمع صلاحيات الأدوار + المنح المباشر معاً،
     * فتستقبل الواجهة قائمة واحدة نهائية ولا تحسب شيئاً بنفسها.
     *
     * تنبيه: هذي القائمة لإخفاء الأزرار فقط. الفحص الحقيقي يقع على
     * الخادم في كل مسار — إخفاء الزر ليس أماناً.
     */
    private function profile(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_active' => $user->is_active,
            'roles' => $user->getRoleNames(),
            // الاسم العربي للدور — الواجهة تعرض المسمّى لا المعرّف البرمجي
            'role_label' => $this->roleLabel($user),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ];
    }
}
