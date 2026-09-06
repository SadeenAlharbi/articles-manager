<?php

namespace App\Policies;

use App\Models\User;

/**
 * قواعد التدرّج في إدارة المستخدمين.
 *
 * ثلاث قواعد تحكم كل ما في هذا الملف:
 *
 *   ١. لا تلمس من هو في مستواك أو أعلى منه.
 *   ٢. لا تمنح دوراً مستواه يساوي مستواك أو يفوقه.
 *   ٣. لا تمنح صلاحية لا تملكها أنت.
 *
 * القاعدة الثالثة أهمّها وأكثرها إغفالاً: بدونها يستطيع مستخدم أن
 * يمنح حساباً آخر صلاحيات تفوق صلاحياته ثم يدخل به — وهي ثغرة
 * تصعيد امتيازات كاملة رغم أن كل فحص ظاهري يبدو سليماً.
 *
 * كل ما هنا يُفرض على الخادم. الواجهة تُخفي الأزرار للتجربة فقط.
 */
class UserPolicy
{
    /* ----------------------------- القراءة ------------------------------ */

    public function viewAny(User $actor): bool
    {
        return $actor->can('users.manage');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->can('users.manage');
    }

    /* ------------------------------ الكتابة ----------------------------- */

    public function create(User $actor): bool
    {
        return $actor->can('users.manage');
    }

    /**
     * إنشاء مستخدم ومنحه دوراً في الوقت نفسه.
     *
     * assignRole أعلاه تحتاج مستخدماً قائماً، وهذا لم يوجد بعد — لذلك
     * قاعدة منفصلة تفحص مستوى الدور المطلوب قبل الإنشاء لا بعده.
     * لولاها لأمكن إنشاء الحساب ثم فشل إسناد الدور، فيبقى حساب يتيم.
     */
    public function createWithRole(User $actor, int $roleLevel): bool
    {
        return $actor->can('users.manage')
            && $actor->can('roles.manage')
            && $roleLevel < $actor->roleLevel();
    }

    /**
     * تعديل بيانات حساب.
     *
     * يُسمح للمستخدم بتعديل بيانات نفسه دون أن يملك users.manage —
     * لكنه لا يستطيع تغيير أدواره ولا صلاحياته، فتلك قواعد منفصلة أدناه.
     */
    public function update(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return true;
        }

        return $actor->can('users.manage') && $actor->outranks($target);
    }

    /**
     * تعطيل حساب أو إعادة تفعيله.
     *
     * منع تعطيل النفس مقصود: لولاه لاستطاع آخر مدير في النظام أن
     * يُقفل الباب على الجميع بضغطة واحدة.
     */
    public function toggleActive(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        return $actor->can('users.manage') && $actor->outranks($target);
    }

    /* ------------------------ الأدوار والصلاحيات ------------------------ */

    /**
     * إسناد دور إلى مستخدم.
     *
     * $roleLevel هو مستوى الدور المُراد إسناده.
     *
     * الشرط الأخير هو صمّام الأمان: لا يستطيع مشرف مستواه 80 أن
     * يصنع مديراً مستواه 100 — أي لا يصنع أحد من هو أقوى منه.
     */
    public function assignRole(User $actor, User $target, int $roleLevel): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        return $actor->can('roles.manage')
            && $actor->outranks($target)
            && $roleLevel < $actor->roleLevel();
    }

    /**
     * منح صلاحيات فردية لمستخدم.
     *
     * @param  array<int, string>  $permissions  أسماء الصلاحيات المطلوب منحها
     */
    public function grantPermissions(User $actor, User $target, array $permissions): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        if (! $actor->can('roles.manage') || ! $actor->outranks($target)) {
            return false;
        }

        // القاعدة الثالثة: لا تمنح ما لا تملك.
        foreach ($permissions as $permission) {
            if (! $actor->can($permission)) {
                return false;
            }
        }

        return true;
    }
}
