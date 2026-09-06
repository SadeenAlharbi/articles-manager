<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * الصنف الأساسي للمتحكّمات.
 *
 * منذ Laravel 11 صار هذا الصنف فارغاً افتراضياً، ومن يحتاج
 * $this->authorize() عليه إضافة السمة صراحةً. نضيفها هنا مرة واحدة
 * لأن التفويض على مستوى الكائن (UserPolicy) جزء أصيل من هذا النظام.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
