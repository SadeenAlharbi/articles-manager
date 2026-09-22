<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('سجلّ العمليات', 'من فعل ماذا ومتى — الناجح والمرفوض.', weight: 3)]
class AuditLogController extends Controller
{
    /**
     * The audit log.
     *
     * Required permission: `audit.view` (viewing the audit log) — an individual
     * permission that can be granted to one particular person without promoting
     * them to admin. Rows are written and never edited nor deleted, and they
     * never hold passwords of any kind.
     *
     * Alongside `data` it also returns `actors` (the list of actors, for
     * filtering) and `labels` (the Arabic names of the permissions and roles).
     */
    #[ApiResponse(403, description: 'لا تملك صلاحية عرض سجلّ العمليات.')]
    public function index(Request $request): JsonResponse
    {
        $logs = AuditLog::with('user:id,name,email')
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->latest('id')
            ->paginate(20);

        /*
         * The list of actors is built from the log itself rather than from the
         * whole users table: filtering on somebody who has never performed
         * anything returns an empty page and serves no purpose. The query is
         * deliberately kept apart from the pagination — the list has to cover
         * everyone, not a single page.
         */
        $actors = User::query()
            ->whereIn('id', AuditLog::query()->whereNotNull('user_id')->distinct()->pluck('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        /*
         * The permission and role labels are shipped with the log instead of
         * being copied into the front end: they come from the seeder itself, so
         * the two copies cannot drift apart. The /users/meta endpoint is no use
         * here because it requires users.manage, whereas a reader of the log may
         * hold audit.view and nothing else — which is precisely what individual
         * permissions make possible.
         */
        $labels = [
            'permissions' => RolesAndPermissionsSeeder::PERMISSIONS,
            'roles' => collect(RolesAndPermissionsSeeder::ROLES)
                ->map(fn (array $role) => $role['label']),
        ];

        return response()->json([
            ...$logs->toArray(),
            'actors' => $actors,
            'labels' => $labels,
        ]);
    }
}
