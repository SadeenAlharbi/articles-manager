<?php

namespace App\Http\Resources;

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The shape of a user as the front end sees it.
 *
 * A fixed contract: changing a column in the database does not change this
 * response of its own accord, and the password cannot leak because it is not
 * named here in the first place.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /*
         * Three sets the front end needs in order to describe every permission
         * precisely: does it come from the role, is it an individual grant on
         * top of the role, or is it an exception withheld from it.
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
            // What they can actually do: role + grants − denied (getAllPermissions subtracts the denied)
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
            // What the role alone grants — the basis for telling "from the role" apart from "a grant"
            'role_permissions' => $fromRole,
            // The direct grants exactly as they are stored
            'direct_permissions' => $direct,
            /*
             * The truly individual grants = the direct ones minus whatever the
             * role already grants. A grant that merely repeats a permission the
             * role gives is not "extra", and showing it as such misleads.
             */
            'extra_permissions' => $direct->diff($fromRole)->values(),
            // Exceptions to the role: it grants them, yet the user does not hold them
            'denied_permissions' => $denied,
            'created_at' => $this->created_at,

            /*
             * What the current user is able to do with this row.
             * Worked out on the server, not in the front end, so no
             * authorization logic is duplicated.
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
