<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\KnowledgePlatform;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * The figures of the admin dashboard.
 *
 * The aggregation happens on the server, not in the front end: the token never
 * leaves the server, and the browser sends one request instead of five, so the
 * screen is not built up in stages. The price is known and stated here — a
 * handful of sequential requests to the platform on every open.
 *
 * Every number here is a real query. No made-up data, and when its source is
 * missing the value is returned explicitly empty, so the front end shows an
 * empty state instead of an invented number.
 *
 * Technical debt, on the record (phase 16 audit): the five requests run one
 * after another, so the dashboard's latency is their sum rather than the
 * longest of them. The cure is Http::pool — they are sent together and awaited
 * once. It has not been applied yet because pool changes the shape of
 * partial-failure handling: today it is enough for each query to return null
 * when it cannot be answered, whereas with pool one has to tell which member of
 * the batch fell over.
 */
#[Group('لوحة المعلومات', 'أرقام المنصّة وآخر العمليات في مكان واحد.', weight: 4)]
class DashboardController extends Controller
{
    /** How many articles are shown under "Latest articles" and "Most read". */
    private const LIST_SIZE = 5;

    public function __construct(private readonly KnowledgePlatform $platform) {}

    /**
     * The platform's figures and the latest operations.
     *
     * Required permission: `analytics.view` (viewing the statistics).
     *
     * `counts` is read from the platform's pagination rather than by counting
     * rows: we ask for a page of a single item and take `meta.total` — so we
     * never transfer thousands of articles merely to count them.
     */
    #[ApiResponse(403, description: 'لا تملك صلاحية عرض الإحصائيات.')]
    #[ApiResponse(502, description: 'منصّة المعرفة غير متاحة حالياً.')]
    public function index(): JsonResponse
    {
        return response()->json([
            'counts' => [
                'all' => $this->total('all'),
                'published' => $this->total('published'),
                'draft' => $this->total('draft'),
                'scheduled' => $this->total('scheduled'),
            ],
            'latest_articles' => $this->articles(['status' => 'all', 'sort' => 'latest']),
            'most_viewed' => $this->articles(['status' => 'published', 'sort' => 'views']),
            'top_categories' => $this->topCategories(),
            /*
             * Exactly the same row shape as /audit-logs, so the front end reuses
             * its own functions for translating and displaying them with no
             * second conversion.
             */
            'latest_operations' => AuditLog::with('user:id,name')
                ->latest('id')
                ->take(self::LIST_SIZE)
                ->get(),
        ]);
    }

    /* --------------------------------------------------------------------- */

    /**
     * The total number of articles in one particular status.
     *
     * per_page=1 is deliberate: we need meta.total alone, so there is no reason
     * to transfer a whole page. And null when it cannot be answered, not zero —
     * zero is a number and means "no articles", while absence means "we do not
     * know", and the front end shows the two differently.
     */
    private function total(string $status): ?int
    {
        $response = $this->platform->listArticles(['status' => $status, 'per_page' => 1]);

        return $response->successful()
            ? (int) ($response->json('meta.total') ?? 0)
            : null;
    }

    /** @return array<int, array<string, mixed>> */
    private function articles(array $query): array
    {
        $response = $this->platform->listArticles($query + ['per_page' => self::LIST_SIZE]);

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('data') ?? [])
            ->map(fn (array $article) => [
                'slug' => $article['slug'] ?? null,
                'title' => $article['title'] ?? null,
                'status' => $article['status'] ?? null,
                'status_label' => $article['status_label'] ?? null,
                'views_count' => $article['views_count'] ?? null,
                'comments_count' => $article['comments_count'] ?? null,
                'published_at' => $article['published_at'] ?? null,
            ])
            ->all();
    }

    /**
     * The most published-in categories.
     *
     * The platform returns posts_count with every category, so the ordering is
     * done on our side and costs no extra query. Empty categories are left out:
     * a row reading zero says nothing in a list of "the most".
     */
    private function topCategories(): array
    {
        $response = $this->platform->listTags();

        if (! $response->successful()) {
            return [];
        }

        return collect($response->json('data') ?? [])
            ->filter(fn (array $tag) => ($tag['posts_count'] ?? 0) > 0)
            ->sortByDesc('posts_count')
            ->take(self::LIST_SIZE)
            ->map(fn (array $tag) => [
                'name' => $tag['name'] ?? null,
                'slug' => $tag['slug'] ?? null,
                'posts_count' => (int) ($tag['posts_count'] ?? 0),
            ])
            ->values()
            ->all();
    }
}
