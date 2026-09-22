<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * Writes one row into the audit log for every operation that changes anything.
 *
 * Failed attempts are recorded too (succeeded = false) — a log that keeps only
 * the successes never reveals who reached for what was not theirs.
 */
class AuditLogger
{
    /** The kinds of subject that operations are carried out on. */
    public const SUBJECT_ARTICLE = 'article';

    public const SUBJECT_USER = 'user';

    /**
     * @param  string  $action  the same names as the permissions: articles.delete, users.disable …
     * @param  string  $subjectType  the type of the affected subject
     * @param  string|null  $subjectId  its identifier — a slug for an article, an id for a user
     * @param  array<string, mixed>  $payload  free-form details stored as JSONB
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
