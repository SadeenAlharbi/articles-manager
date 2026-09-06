<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * تعديل بيانات حساب: الاسم والبريد وكلمة المرور فقط.
 *
 * الأدوار والصلاحيات لها مساراتها الخاصة لأنها تحرسها صلاحية مختلفة
 * (roles.manage) وتُنتج سطر تدقيق مختلفاً.
 */
class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            && ($this->user()?->can('update', $target) ?? false);
    }

    public function rules(): array
    {
        $target = $this->route('user');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'email' => [
                'sometimes', 'required', 'email', 'max:191',
                Rule::unique('users', 'email')->ignore($target?->id),
            ],
            // كلمة المرور اختيارية: تركها فارغة يعني الإبقاء على القديمة.
            'password' => ['sometimes', 'nullable', 'string', 'min:8'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'هذا البريد مسجَّل لمستخدم آخر.',
            'password.min' => 'كلمة المرور يجب ألّا تقلّ عن 8 أحرف.',
        ];
    }
}
