<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permissions, roles and the demo accounts.
 *
 * The seeder is idempotent: running it once or ten times gives the same result,
 * creates no duplicates, and never touches existing passwords.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Permissions follow a "resource.action" pattern — adding a new permission
     * becomes a row in a table, not a change to the database structure nor to
     * the code.
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
     * The roles: the Arabic label, the level, and the permissions.
     *
     * The level decides a role's authority over people, not its power over
     * content. That is why admin and super_admin hold identical permissions and
     * differ only in level: both do exactly the same things to articles, but the
     * admin cannot edit the system manager's account, nor create another one
     * like it.
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
         * spatie caches the permissions to make checking them faster. After any
         * change to them the cache must be flushed, or the old values stay in
         * place.
         */
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(self::PERMISSIONS) as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => User::PERMISSION_GUARD]);
        }

        $all = array_keys(self::PERMISSIONS);

        foreach (self::ROLES as $name => $definition) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => User::PERMISSION_GUARD]);

            // The level is set explicitly on every run, so correcting it later
            // needs nothing more than db:seed.
            $role->forceFill(['level' => $definition['level']])->save();

            $role->syncPermissions(
                $definition['permissions'] === '*' ? $all : $definition['permissions']
            );
        }

        $this->seedDemoAccounts();
    }

    /**
     * Demo accounts — each one embodies a case that gets asked about.
     *
     * firstOrCreate: the password is written only at creation, so re-running the
     * seeder does not reset a password somebody has since changed.
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
         * The exact case the supervisor asked for: a user whose role is "author"
         * but who holds the delete permission as a personal exception. A direct
         * grant is stored in model_has_permissions rather than on the role — so
         * the rest of the authors are unaffected by it.
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
