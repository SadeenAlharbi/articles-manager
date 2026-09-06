<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/**
 * إنشاء مستخدم في نظام الإدارة.
 *
 * التفويض هنا يفحص الصلاحية العامة فقط؛ قواعد التدرّج (لا تصنع من هو
 * أقوى منك) تُفحص في المتحكّم لأنها تحتاج مستوى الدور المطلوب.
 */
class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:191', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', 'string', Rule::exists(Role::class, 'name')],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'اسم المستخدم مطلوب.',
            'email.required' => 'البريد الإلكتروني مطلوب.',
            'email.email' => 'صيغة البريد الإلكتروني غير صحيحة.',
            'email.unique' => 'هذا البريد مسجَّل في النظام مسبقاً.',
            'password.required' => 'كلمة المرور مطلوبة.',
            'password.min' => 'كلمة المرور يجب ألّا تقلّ عن 8 أحرف.',
            'role.required' => 'يجب اختيار دور للمستخدم.',
            'role.exists' => 'الدور المحدَّد غير موجود.',
            'permissions.*.exists' => 'إحدى الصلاحيات المحدَّدة غير موجودة.',
        ];
    }
}
