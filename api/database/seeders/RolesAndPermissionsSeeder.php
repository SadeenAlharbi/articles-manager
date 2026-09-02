<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{

    private const PERMISSIONS = [
        'articles.view',
        'articles.create',
        'articles.update',
        'articles.delete',
        'articles.publish',
        'users.manage',
        'roles.manage',
    ];

    private const ROLES = [
        'viewer' => ['articles.view'],
        'author' => ['articles.view', 'articles.create'],
        'editor' => ['articles.view', 'articles.create', 'articles.update'],
        'moderator' => [
            'articles.view', 'articles.create', 'articles.update',
            'articles.delete', 'articles.publish',
        ],
        'admin' => self::PERMISSIONS,
    ];

    public function run(): void
    {
    
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        foreach (self::ROLES as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($permissions);
        }

        $accounts = [
            ['سدين المشرفة', 'admin@demo.test', 'admin'],
            ['روان المراقِبة', 'moderator@demo.test', 'moderator'],
            ['ساره المحرِّرة', 'editor@demo.test', 'editor'],
            ['رنين الكاتبة', 'author@demo.test', 'author'],
            ['دلال القارئة', 'viewer@demo.test', 'viewer'],
        ];

        foreach ($accounts as [$name, $email, $role]) {
            $user = User::firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Hash::make('password')]
            );

            $user->syncRoles([$role]);
        }

        $special = User::firstOrCreate(
            ['email' => 'author.plus@demo.test'],
            ['name' => 'دانة — كاتبة بصلاحية حذف', 'password' => Hash::make('password')]
        );

        $special->syncRoles(['author']);
        $special->syncPermissions([]);
        $special->givePermissionTo('articles.delete');
    }
}