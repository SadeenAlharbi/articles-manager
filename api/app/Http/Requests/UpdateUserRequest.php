<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Editing an account's details: the name, the email and the password only.
 *
 * Roles and permissions have routes of their own, because a different
 * permission guards them (roles.manage) and they produce a different audit
 * entry.
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
            // The password is optional: leaving it empty means keeping the old one.
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
