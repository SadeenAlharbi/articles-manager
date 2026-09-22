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


#[Fillable(['name', 'email', 'password', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /*
     * We borrow spatie's two methods under alias names so that our own versions
     * below can call them. parent:: is no use here: these two methods come from
     * a trait, not from a parent class, and a trait is merged into the class
     * itself, so it has no place anywhere in the inheritance chain.
     */
    use HasRoles {
        HasRoles::hasPermissionTo as protected spatieHasPermissionTo;
        HasRoles::getAllPermissions as protected spatieGetAllPermissions;
    }

    /** The highest level there is — held by the system administrator alone. */
    public const LEVEL_SUPER_ADMIN = 100;

    /**
     * The guard under which this system's roles and permissions live.
     *
     * Stated explicitly rather than left to the default: once a request is
     * authenticated with a Sanctum token the default guard becomes "sanctum",
     * and calls such as Role::findByName then fail because the roles were
     * created under "web". Pinning it here heads that confusion off everywhere.
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
     * A user's level of authority = the highest level among their roles.
     *
     * Zero for anyone who holds no role, so they have authority over nobody.
     * That is the safe default: an account without a role manages no one,
     * rather than managing everyone.
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
     * Does this user stand above that one?
     *
     * The comparison is strict (>) and not (>=): two people on the same level
     * hold no authority over one another — which stops two admins from
     * disabling each other.
     */
    public function outranks(self $other): bool
    {
        return $this->roleLevel() > $other->roleLevel();
    }

    /* ------------------------- Individual denial ------------------------- */

    /**
     * Permissions withheld from this one particular user.
     *
     * An exception to their role: "a content moderator, but with no deleting".
     * The alternative is inventing a new role for every exception, until the
     * roles multiply so far that they lose their meaning.
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
     * A denial outranks a grant.
     *
     * We override spatie's own method instead of adding a check beside it:
     * Gate, $user->can() and middleware('permission:...') all pass through
     * here, so the denial holds on the routes exactly as it holds in the
     * interface. Had we put it in the interface alone, the button would be
     * hidden while the route stayed open.
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
     * What they actually hold: the role's permissions + the individual grants −
     * the denials.
     *
     * It is used for display, and it must agree with what hasPermissionTo above
     * allows; otherwise the screen shows a permission the server refuses.
     */
    public function getAllPermissions(): Collection
    {
        $denied = $this->deniedPermissionNames();

        return $this->spatieGetAllPermissions()
            ->reject(fn ($permission) => $denied->contains($permission->name))
            ->values();
    }
}
