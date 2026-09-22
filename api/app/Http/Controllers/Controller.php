<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Base class for the controllers.
 *
 * As of Laravel 11 this class is empty by default, and whoever needs
 * $this->authorize() has to pull the trait in explicitly. We add it here once,
 * because object-level authorization (UserPolicy) is intrinsic to this system.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
