<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('سجلّ العمليات', 'من فعل ماذا ومتى — الناجح والمرفوض.', weight: 3)]
class AuditLogController extends Controller
{
    /**
     * سجلّ العمليات.
     *
     * الصلاحية المطلوبة: `audit.view` (عرض سجلّ العمليات) — وهي صلاحية فردية
     * يمكن منحها لشخص بعينه دون ترقيته إلى مشرف. الصفوف تُكتب ولا تُعدَّل ولا
     * تُحذف، ولا تحوي كلمات مرور إطلاقاً.
     *
     * تُرجع أيضاً `actors` (قائمة المنفّذين للتصفية) و`labels` (مسمّيات
     * الصلاحيات والأدوار بالعربية) إلى جانب `data`.
     */
    #[ApiResponse(403, description: 'لا تملك صلاحية عرض سجلّ العمليات.')]
    public function index(Request $request): JsonResponse
    {
        $logs = AuditLog::with('user:id,name,email')
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->latest('id')
            ->paginate(20);

        /*
         * قائمة المنفّذين تُبنى من السجلّ نفسه لا من جدول المستخدمين كاملاً:
         * فلترة على شخص لم ينفّذ شيئاً تُرجع صفحة فارغة بلا فائدة. والاستعلام
         * منفصل عن الترقيم عمداً — القائمة يجب أن تشمل الجميع لا صفحةً واحدة.
         */
        $actors = User::query()
            ->whereIn('id', AuditLog::query()->whereNotNull('user_id')->distinct()->pluck('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        /*
         * مسميات الصلاحيات والأدوار تُرسَل مع السجلّ لا تُستنسخ في الواجهة:
         * مصدرها البذرة نفسها، فلا تتباعد النسختان. ولا تصلح هنا نقطة
         * /users/meta لأنها تتطلّب users.manage، وقد يملك قارئ السجلّ
         * audit.view وحدها — وهذا بالضبط ما تتيحه الصلاحيات الفردية.
         */
        $labels = [
            'permissions' => RolesAndPermissionsSeeder::PERMISSIONS,
            'roles' => collect(RolesAndPermissionsSeeder::ROLES)
                ->map(fn (array $role) => $role['label']),
        ];

        return response()->json([
            ...$logs->toArray(),
            'actors' => $actors,
            'labels' => $labels,
        ]);
    }
}
