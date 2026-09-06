<?php

namespace App\Http\Resources;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * شكل المستخدم كما تراه الواجهة.
 *
 * عقد ثابت: تغيير عمود في قاعدة البيانات لا يغيّر هذي الاستجابة تلقائياً،
 * وكلمة المرور لا يمكن أن تتسرّب لأنها غير مذكورة هنا أصلاً.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /*
         * ثلاث مجموعات تحتاجها الواجهة لتصف كل صلاحية بدقة: أهي من الدور،
         * أم منحة فردية فوقه، أم استثناءٌ محجوب منه.
         */
        $fromRole = $this->roles->flatMap->permissions->pluck('name')->unique()->values();
        $direct = $this->permissions->pluck('name')->values();
        $denied = $this->deniedPermissions->pluck('name')->values();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => (bool) $this->is_active,
            'level' => $this->roleLevel(),
            'role' => $this->roles->first()?->name,
            'role_label' => $this->roles->first()?->name
                ? (RolesAndPermissionsSeeder::ROLES[$this->roles->first()->name]['label'] ?? null)
                : null,
            // ما يستطيعه فعلاً: الدور + المنح − المحجوب (getAllPermissions تطرح المحجوب)
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
            // ما يمنحه الدور وحده — أساس تمييز «من الدور» عن «منحة»
            'role_permissions' => $fromRole,
            // المنح المباشر كما هي مخزَّنة
            'direct_permissions' => $direct,
            /*
             * المنح الفردي الحقيقي = المباشر ناقص ما يمنحه الدور أصلاً.
             * منحٌ مكرّر لصلاحية يمنحها الدور ليس «إضافياً»، وعرضه كذلك مضلّل.
             */
            'extra_permissions' => $direct->diff($fromRole)->values(),
            // استثناءات من الدور: يمنحها الدور والمستخدم لا يملكها
            'denied_permissions' => $denied,
            'created_at' => $this->created_at,

            /*
             * ما يستطيع المستخدم الحالي فعله بهذا الصف.
             * تُحسب في الخادم لا في الواجهة، فلا يوجد منطق تفويض مكرّر.
             */
            'can' => [
                'update' => $request->user()?->can('update', $this->resource) ?? false,
                'toggle_active' => $request->user()?->can('toggleActive', $this->resource) ?? false,
                'manage_access' => ($request->user()?->can('roles.manage') ?? false)
                    && ($request->user()?->outranks($this->resource) ?? false)
                    && ! ($request->user()?->is($this->resource) ?? true),
            ],
        ];
    }
}
