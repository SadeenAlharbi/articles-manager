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
    public function record(
        Request $request,
        string $action,
        ?string $subjectId = null,
        array $payload = [],
        bool $succeeded = true,
    ): void {
        AuditLog::create([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'subject_type' => 'article',
            'subject_id' => $subjectId,
            'payload' => $payload !== [] ? $payload : null,
            'ip_address' => $request->ip(),
            'succeeded' => $succeeded,
        ]);
    }
}
