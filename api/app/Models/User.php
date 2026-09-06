<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Traits\HasRoles;

/**
 * مستخدم نظام إدارة المقالات.
 *
 * ليس مستخدم منصّة المعرفة: قاعدة بيانات مستقلة وحسابات مستقلة.
 * لا تُخلط الهويتان ولا تُربطان.
 */
#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /*
     * نستعير دالتَي spatie باسمين بديلين لنستدعيهما من داخل نسختينا أدناه.
     * parent:: لا تصلح هنا: هاتان الدالتان من سمة (trait) لا من صنف أب،
     * والسمة تُدمج في الصنف نفسه فلا وجود لها في سلسلة الوراثة.
     */
    use HasRoles {
        HasRoles::hasPermissionTo as protected spatieHasPermissionTo;
        HasRoles::getAllPermissions as protected spatieGetAllPermissions;
    }

    /** أعلى مستوى ممكن — يملكه مدير النظام وحده. */
    public const LEVEL_SUPER_ADMIN = 100;

    /**
     * الحارس الذي تعيش تحته أدوار هذا النظام وصلاحياته.
     *
     * صريح لا افتراضي: بعد المصادقة بتوكن Sanctum يصير الحارس الافتراضي
     * "sanctum"، فتفشل عمليات مثل Role::findByName لأن الأدوار مُنشأة
     * تحت "web". تثبيته هنا يمنع هذا الالتباس في كل موضع.
     */
    public const PERMISSION_GUARD = 'web';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * مستوى سلطة المستخدم = أعلى مستوى بين أدواره.
     *
     * صفر لمن لا دور له، فلا يملك سلطة على أحد. وهذا هو الافتراض
     * الآمن: الحساب بلا دور لا يُدير أحداً بدل أن يُدير الجميع.
     */
    public function roleLevel(): int
    {
        return (int) ($this->roles->max('level') ?? 0);
    }

    public function isSuperAdmin(): bool
    {
        return $this->roleLevel() >= self::LEVEL_SUPER_ADMIN;
    }

    /**
     * هل يقف هذا المستخدم فوق ذاك؟
     *
     * المقارنة صارمة (>) لا (>=): المتساويان في المستوى لا يملك
     * أحدهما سلطة على الآخر — وهذا يمنع مشرفَين من تعطيل بعضهما.
     */
    public function outranks(self $other): bool
    {
        return $this->roleLevel() > $other->roleLevel();
    }

    /* ---------------------------- الحجب الفردي --------------------------- */

    /**
     * صلاحيات محجوبة عن هذا المستخدم بعينه.
     *
     * استثناء من دوره: «مشرف محتوى، لكن بلا حذف». البديل عن هذا هو إنشاء
     * دور جديد لكل استثناء، فتتضخّم الأدوار حتى تفقد معناها.
     */
    public function deniedPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_denials');
    }

    /** @return Collection<int, string> */
    public function deniedPermissionNames(): Collection
    {
        return $this->deniedPermissions->pluck('name');
    }

    /**
     * الحجب يعلو على المنح.
     *
     * نتجاوز دالة spatie نفسها لا نضيف فحصاً بجانبها: كل من
     * Gate و $user->can() و middleware('permission:...') يمرّ من هنا،
     * فيسري الحجب على المسارات كما يسري على الواجهة. ولو وضعناه في
     * الواجهة وحدها لكان الزر مخفياً والمسار مفتوحاً.
     */
    public function hasPermissionTo($permission, ?string $guardName = null): bool
    {
        $name = match (true) {
            is_string($permission) => $permission,
            $permission instanceof PermissionContract => $permission->name,
            default => null,
        };

        if ($name !== null && $this->deniedPermissionNames()->contains($name)) {
            return false;
        }

        return $this->spatieHasPermissionTo($permission, $guardName);
    }

    /**
     * ما يملكه فعلاً: صلاحيات الدور + المنح الفردي − المحجوب.
     *
     * تُستعمل في العرض، ولا بدّ أن تطابق ما تسمح به hasPermissionTo أعلاه،
     * وإلا عرضت الشاشة صلاحية يرفضها الخادم.
     */
    public function getAllPermissions(): Collection
    {
        $denied = $this->deniedPermissionNames();

        return $this->spatieGetAllPermissions()
            ->reject(fn ($permission) => $denied->contains($permission->name))
            ->values();
    }
}
