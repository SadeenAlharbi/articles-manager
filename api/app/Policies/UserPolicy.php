<?php

namespace App\Policies;

use App\Models\User;

/**
 * Hierarchy rules for managing accounts.
 *
 * Three rules govern everything in this file:
 *
 *   1. Never touch someone at your own level or above it.
 *   2. Never assign a role whose level equals or exceeds your own.
 *   3. Never grant a permission you do not hold yourself.
 *
 * The third is the most important and the most often forgotten: without it a
 * user can grant another account permissions beyond their own and then sign in
 * as that account — a complete privilege escalation, even though every visible
 * check looks sound.
 *
 * All of this is enforced on the server. The UI hides buttons for convenience
 * only; hiding a button is not a security control.
 */
class UserPolicy
{
    /* ------------------------------ Reading ----------------------------- */

    public function viewAny(User $actor): bool
    {
        return $actor->can('users.manage');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->can('users.manage');
    }

    /* ------------------------------ Writing ----------------------------- */

    public function create(User $actor): bool
    {
        return $actor->can('users.manage');
    }

    /**
     * Create an account and assign it a role in one step.
     *
     * assignRole() above needs an existing user, and this one does not exist
     * yet — hence a separate rule that checks the requested role level BEFORE
     * creation rather than after. Without it the account could be created and
     * the role assignment then fail, leaving an orphaned account behind.
     */
    public function createWithRole(User $actor, int $roleLevel): bool
    {
        return $actor->can('users.manage')
            && $actor->can('roles.manage')
            && $roleLevel < $actor->roleLevel();
    }

    /**
     * Edit an account's details.
     *
     * A user may edit their own details without holding users.manage — but not
     * their own roles or permissions, which are separate rules below.
     */
    public function update(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return true;
        }

        return $actor->can('users.manage') && $actor->outranks($target);
    }

    /**
     * Disable an account or re-enable it.
     *
     * Blocking self-disable is deliberate: without it the last administrator in
     * the system could lock everyone out with a single click, and nobody would
     * be left who could undo it.
     */
    public function toggleActive(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        return $actor->can('users.manage') && $actor->outranks($target);
    }

    /* -------------------------- Roles and permissions -------------------- */

    /**
     * Assign a role to a user.
     *
     * $roleLevel is the level of the role being assigned.
     *
     * The last condition is the safety valve: an admin at level 80 cannot mint
     * a super admin at level 100 — nobody creates someone stronger than
     * themselves.
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
     * Grant individual permissions to a user.
     *
     * @param  array<int, string>  $permissions  names of the permissions to grant
     */
    public function grantPermissions(User $actor, User $target, array $permissions): bool
    {
        if ($actor->is($target)) {
            return false;
        }

        if (! $actor->can('roles.manage') || ! $actor->outranks($target)) {
            return false;
        }

        // Rule 3: never grant what you do not hold.
        foreach ($permissions as $permission) {
            if (! $actor->can($permission)) {
                return false;
            }
        }

        return true;
    }
}
