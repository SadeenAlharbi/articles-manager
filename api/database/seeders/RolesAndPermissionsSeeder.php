<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * الصلاحيات والأدوار وحسابات العرض.
 *
 * البذرة خاملة (idempotent): تشغيلها مرة أو عشراً يعطي النتيجة نفسها،
 * ولا تُنشئ تكراراً ولا تمسّ كلمات المرور القائمة.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * الصلاحيات بنمط "مورد.فعل" — إضافة صلاحية جديدة تصبح صفّاً في
     * جدول، لا تعديلاً على بنية قاعدة البيانات ولا على الكود.
     */
    public const PERMISSIONS = [
        'articles.view' => 'عرض المقالات',
        'articles.create' => 'إضافة مقال',
        'articles.update' => 'تعديل مقال',
        'articles.delete' => 'حذف مقال',
        'articles.publish' => 'نشر مقال',
        'articles.draft' => 'سحب النشر (إرجاع لمسودة)',
        'analytics.view' => 'عرض الإحصائيات',
        'audit.view' => 'عرض سجلّ العمليات',
        'users.manage' => 'إدارة المستخدمين',
        'roles.manage' => 'إدارة الأدوار والصلاحيات',
    ];

    /**
     * الأدوار: الاسم العربي، المستوى، والصلاحيات.
     *
     * المستوى يحدّد سلطة الدور على الأشخاص لا قدرته على المحتوى.
     * لهذا يتساوى admin و super_admin في الصلاحيات ويختلفان في المستوى:
     * كلاهما يفعل بالمقالات الشيء نفسه، لكن المشرف لا يعدّل حساب مدير
     * النظام ولا يصنع واحداً مثله.
     */
    public const ROLES = [
        'super_admin' => [
            'label' => 'مدير النظام',
            'level' => 100,
            'permissions' => '*',
        ],
        'admin' => [
            'label' => 'مشرف',
            'level' => 80,
            'permissions' => '*',
        ],
        'moderator' => [
            'label' => 'مشرف محتوى',
            'level' => 60,
            'permissions' => [
                'articles.view', 'articles.create', 'articles.update',
                'articles.delete', 'articles.publish', 'articles.draft',
                'analytics.view', 'audit.view',
            ],
        ],
        'editor' => [
            'label' => 'محرّر',
            'level' => 40,
            'permissions' => [
                'articles.view', 'articles.create', 'articles.update', 'articles.draft',
            ],
        ],
        'author' => [
            'label' => 'كاتب',
            'level' => 20,
            'permissions' => ['articles.view', 'articles.create', 'articles.draft'],
        ],
        'viewer' => [
            'label' => 'مطّلع',
            'level' => 10,
            'permissions' => ['articles.view'],
        ],
    ];

    public function run(): void
    {
        /*
         * spatie تخزّن الصلاحيات في ذاكرة مؤقتة لتسريع الفحص.
         * بعد أي تغيير عليها يجب إفراغها، وإلا بقيت القيم القديمة.
         */
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(self::PERMISSIONS) as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => User::PERMISSION_GUARD]);
        }

        $all = array_keys(self::PERMISSIONS);

        foreach (self::ROLES as $name => $definition) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => User::PERMISSION_GUARD]);

            // المستوى يُضبط صراحةً في كل تشغيل، فتصحيحه لاحقاً يكفيه db:seed.
            $role->forceFill(['level' => $definition['level']])->save();

            $role->syncPermissions(
                $definition['permissions'] === '*' ? $all : $definition['permissions']
            );
        }

        $this->seedDemoAccounts();
    }

    /**
     * حسابات عرض — كل واحدة تُجسّد حالة يُسأل عنها.
     *
     * firstOrCreate: كلمة المرور تُكتب عند الإنشاء فقط، فإعادة التشغيل
     * لا تُعيد ضبط كلمة سر غيّرها أحد.
     */
    private function seedDemoAccounts(): void
    {
        $accounts = [
            ['هيا — مديرة النظام', 'super@demo.test', 'super_admin'],
            ['سديم المشرفة', 'admin@demo.test', 'admin'],
            ['نورة مشرفة المحتوى', 'moderator@demo.test', 'moderator'],
            ['ريم المحرِّرة', 'editor@demo.test', 'editor'],
            ['رنين الكاتبة', 'author@demo.test', 'author'],
            ['دلال القارئة', 'viewer@demo.test', 'viewer'],
        ];

        foreach ($accounts as [$name, $email, $role]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make('password'), 'is_active' => true]
            );

            $user->syncRoles([$role]);
        }

        /*
         * الحالة التي طلبها المشرف حرفياً: مستخدمة دورها "كاتبة" لكنها
         * تملك صلاحية الحذف كاستثناء شخصي. منح مباشر (Direct Grant)
         * يُخزَّن في model_has_permissions لا في الدور — فلا يتأثر به
         * بقية الكُتّاب.
         */
        $special = User::firstOrCreate(
            ['email' => 'author.plus@demo.test'],
            ['name' => 'دانة — كاتبة بصلاحية حذف', 'password' => Hash::make('password'), 'is_active' => true]
        );

        $special->syncRoles(['author']);
        $special->syncPermissions([]);
        $special->givePermissionTo('articles.delete');
    }
}
