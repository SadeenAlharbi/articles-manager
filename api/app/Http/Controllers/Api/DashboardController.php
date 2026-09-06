<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\KnowledgePlatform;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * أرقام لوحة الإدارة.
 *
 * التجميع يقع في الخادم لا في الواجهة: التوكن لا يغادر الخادم، والمتصفّح يرسل
 * طلباً واحداً بدل خمسة فلا تُبنى الشاشة على مراحل. والثمن معروف ومذكور —
 * عدّة طلبات متتابعة إلى المنصّة عند كل فتح.
 *
 * كل رقم هنا استعلام حقيقي. لا بيانات وهمية، وإن غاب مصدرها تُرجَع القيمة
 * فارغة صراحةً لتعرض الواجهة حالة فراغ بدل رقم مخترع.
 *
 * دَين تقني مرصود (تدقيق المرحلة ١٦): الطلبات الخمسة تتابعية، فزمن اللوحة هو
 * مجموعها لا أطولها. علاجه Http::pool — تُرسل معاً وتُنتظر مرة واحدة. لم يُطبَّق
 * الآن لأن pool يغيّر شكل معالجة الفشل الجزئي: اليوم يكفي أن يُرجع كل استعلام
 * null عند تعذّره، ومع pool يلزم تمييز أي عنصر في المجموعة سقط.
 */
#[Group('لوحة المعلومات', 'أرقام المنصّة وآخر العمليات في مكان واحد.', weight: 4)]
class DashboardController extends Controller
{
    /** عدد المقالات المعروضة في «آخر المقالات» و«الأكثر قراءة». */
    private const LIST_SIZE = 5;

    public function __construct(private readonly KnowledgePlatform $platform) {}

    /**
     * أرقام المنصّة وآخر العمليات.
     *
     * الصلاحية المطلوبة: `analytics.view` (عرض الإحصائيات).
     *
     * `counts` تُقرأ من ترقيم المنصّة لا بعدّ الصفوف: نطلب صفحة بعنصر واحد
     * ونأخذ `meta.total` — فلا ننقل آلاف المقالات لنعدّها.
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
             * نفس شكل صفوف /audit-logs بالضبط، فتعيد الواجهة استعمال دوالها
             * في الترجمة والعرض بلا تحويل ثانٍ.
             */
            'latest_operations' => AuditLog::with('user:id,name')
                ->latest('id')
                ->take(self::LIST_SIZE)
                ->get(),
        ]);
    }

    /* --------------------------------------------------------------------- */

    /**
     * إجمالي المقالات في حالة بعينها.
     *
     * per_page=1 مقصود: نحتاج meta.total وحده، فلا داعي لنقل الصفحة كاملة.
     * وnull عند التعذّر لا صفر — الصفر رقم يعني «لا مقالات»، والغياب يعني
     * «لا نعرف»، والواجهة تعرضهما مختلفَين.
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
     * أكثر التصنيفات نشراً.
     *
     * المنصّة تُرجع posts_count مع كل تصنيف، فالترتيب عندنا لا استعلام إضافي.
     * والتصنيفات الفارغة تُستبعد: صفٌّ بصفر لا يقول شيئاً في قائمة «الأكثر».
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
