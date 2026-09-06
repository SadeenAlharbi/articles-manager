<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * يكتب سطراً في سجلّ التدقيق لكل عملية تغيّر شيئاً.
 *
 * تُسجَّل المحاولات الفاشلة أيضاً (succeeded = false) — سجلّ يحفظ
 * الناجح فقط لا يكشف من حاول ما ليس له.
 */
class AuditLogger
{
    /** أنواع العناصر التي تقع عليها العمليات. */
    public const SUBJECT_ARTICLE = 'article';

    public const SUBJECT_USER = 'user';

    /**
     * @param  string  $action  نفس أسماء الصلاحيات: articles.delete, users.disable …
     * @param  string  $subjectType  نوع العنصر المتأثّر
     * @param  string|null  $subjectId  معرّفه — slug للمقال، id للمستخدم
     * @param  array<string, mixed>  $payload  تفاصيل متغيّرة الشكل تُخزَّن JSONB
     */
    public function record(
        Request $request,
        string $action,
        string $subjectType,
        ?string $subjectId = null,
        array $payload = [],
        bool $succeeded = true,
    ): void {
        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => $payload !== [] ? $payload : null,
            'ip_address' => $request->ip(),
            'succeeded' => $succeeded,
        ]);
    }
}
