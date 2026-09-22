<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\KnowledgePlatform;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as ApiResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/*
 * Every route here forwards the request on to the knowledge platform — not a
 * single article is stored in this database. That is why a 502 can come out of
 * any operation: it means the platform was unreachable, not that we failed.
 */
#[Group('المقالات', 'إدارة مقالات منصّة المعرفة عبر واجهتها البرمجية.', weight: 1)]
class ArticleController extends Controller
{
    /**
     * Article statuses as the knowledge platform defines them — the values are
     * identical to Post::statuses().
     */
    private const STATUSES = ['draft', 'published', 'scheduled'];

    /** Statuses that make an article public, and so require publish permission. */
    private const PUBLIC_STATUSES = ['published', 'scheduled'];

    public function __construct(
        private readonly KnowledgePlatform $platform,
        private readonly AuditLogger $audit,
    ) {}

    /* ------------------------------ Reading ------------------------------ */

    /**
     * List of articles.
     *
     * Fetched straight from the knowledge platform. Required permission:
     * `articles.view` (view articles).
     *
     * Drafts and scheduled articles are reachable through `status`, because the
     * service token the dashboard carries belongs to an administrator account on
     * the platform. A visitor to the platform itself sees nothing but published
     * articles — that guard lives over there, not here.
     */
    #[QueryParameter('search', 'بحث في العنوان والمحتوى', type: 'string')]
    #[QueryParameter('tag', 'تصفية بتصنيف واحد (المعرّف المختصر للتصنيف)', type: 'string')]
    #[QueryParameter('status', 'حالة المقال: published أو draft أو scheduled أو all. '
        .'تُنفَّذ في منصّة المعرفة، ولا تُرجع غير المنشور إلا لحساب مشرف فيها.', type: 'string')]
    #[QueryParameter('sort', 'الترتيب: latest أو oldest أو title أو views', type: 'string')]
    #[QueryParameter('page', 'رقم الصفحة', type: 'int', default: 1)]
    #[QueryParameter('per_page', 'عدد النتائج في الصفحة', type: 'int', default: 10)]
    #[ApiResponse(403, description: 'لا تملك الصلاحية المطلوبة لهذي العملية.')]
    #[ApiResponse(502, description: 'منصّة المعرفة غير متاحة حالياً.')]
    public function index(Request $request): JsonResponse
    {
        return $this->forward(
            $this->platform->listArticles(
                $request->only(['search', 'tag', 'page', 'per_page', 'sort', 'status'])
            )
        );
    }

    /**
     * Details of a single article.
     *
     * Required permission: `articles.view` (view articles).
     */
    #[ApiResponse(403, description: 'لا تملك الصلاحية المطلوبة لهذي العملية.')]
    #[ApiResponse(502, description: 'منصّة المعرفة غير متاحة حالياً.')]
    public function show(string $slug): JsonResponse
    {
        return $this->forward($this->platform->getArticle($slug));
    }

    /**
     * Categories from the knowledge platform.
     *
     * Required permission: `articles.view` (view articles) — because they are
     * used by both the create-article and the edit-article form.
     *
     * They are read from the platform on every request and no copy of them is
     * kept here: any category added over there shows up immediately, and
     * managing categories stays the platform's business alone.
     */
    #[ApiResponse(403, description: 'لا تملك الصلاحية المطلوبة لهذي العملية.')]
    #[ApiResponse(502, description: 'منصّة المعرفة غير متاحة حالياً.')]
    public function categories(): JsonResponse
    {
        return $this->forward($this->platform->listTags());
    }

    /* ------------------------------ Writing ------------------------------ */

    /**
     * Create an article on the knowledge platform.
     *
     * Required permission: `articles.create` (add article). The operation is
     * written to the audit log under the name of whoever performed it, whether
     * it succeeded or the platform rejected it.
     */
    #[ApiResponse(201, description: 'أُنشئ المقال في منصّة المعرفة.')]
    #[ApiResponse(403, description: 'لا تملك الصلاحية المطلوبة لهذي العملية.')]
    #[ApiResponse(502, description: 'منصّة المعرفة غير متاحة حالياً.')]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ], [
            'status.in' => 'حالة النشر غير صالحة.',
            'image.image' => 'الملف المرفوع يجب أن يكون صورة.',
            'image.mimes' => 'صيغة الصورة يجب أن تكون JPEG أو PNG أو WEBP.',
            'image.max' => 'حجم الصورة يجب ألّا يتجاوز 5 ميجابايت.',
        ]);

        /*
         * These rules duplicate the platform's own deliberately, not as a
         * shortcut around them: we reject an oversized or invalid file **before**
         * pushing it across the network, and we do not lean on the platform's
         * rejection alone — two layers, not one.
         */
        $image = $request->file('image');
        unset($data['image']);

        /*
         * Creating a draft is open to anyone holding articles.create — a draft is
         * not a publication. Creating one already published requires
         * articles.publish; without that, a writer could publish an article by
         * sending status=published straight to the route, going around an
         * interface that never offered them the option in the first place.
         */
        $this->assertMayPublish($request, $data['status'] ?? null);

        $response = $this->platform->createArticle($data, $image);

        $this->audit->record(
            $request,
            'articles.create',
            AuditLogger::SUBJECT_ARTICLE,
            data_get($response->json(), 'data.slug'),
            [
                'title' => $data['title'],
                'has_image' => $image !== null,
                'status' => $response->status(),
            ],
            $response->successful(),
        );

        return $this->forward($response);
    }

    /**
     * Update an article.
     *
     * Required permission: `articles.update` (edit article). Only the fields
     * actually sent are modified — any field left out keeps whatever value it
     * already has on the platform.
     */
    #[ApiResponse(403, description: 'لا تملك الصلاحية المطلوبة لهذي العملية.')]
    #[ApiResponse(502, description: 'منصّة المعرفة غير متاحة حالياً.')]
    public function update(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'content' => ['sometimes', 'required', 'string'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'image' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
        ], [
            'status.in' => 'حالة النشر غير صالحة.',
            'image.image' => 'الملف المرفوع يجب أن يكون صورة.',
            'image.mimes' => 'صيغة الصورة يجب أن تكون JPEG أو PNG أو WEBP.',
            'image.max' => 'حجم الصورة يجب ألّا يتجاوز 5 ميجابايت.',
        ]);

        $image = $request->file('image');
        unset($data['image']);

        /*
         * We read the article before editing it so we can tell what actually
         * changed.
         *
         * The interface submits the whole form on every save, so array_keys($data)
         * means "what was sent", not "what was edited". A log that says the title
         * changed when it did not is a lying log — and if anyone ever asked "who
         * changed this article's title?", it would accuse an innocent person.
         *
         * The comparison belongs here and not in React: leaving it to the client
         * would make the log's truthfulness depend on that client, and any other
         * client — or curl — could spoil it.
         */
        $before = null;

        try {
            $before = $this->platform->getArticle($slug)->json('data');
        } catch (\Throwable) {
            // Best effort: a failed read must not stand in the way of the edit
        }

        $this->assertMayPublish($request, $data['status'] ?? null, $before['status'] ?? null);

        $response = $this->platform->updateArticle($slug, $data, $image);

        $this->audit->record(
            $request,
            'articles.update',
            AuditLogger::SUBJECT_ARTICLE,
            $slug,
            [
                // Title as the platform returned it, else the one sent, else the previous
                'title' => $response->json('data.title')
                    ?? ($data['title'] ?? null)
                    ?? ($before['title'] ?? null),
                /*
                 * The image is added to the changed list by hand: an uploaded
                 * file cannot be compared against an old image URL, and the mere
                 * presence of a new file is itself the change.
                 */
                'fields' => $image === null
                    ? $this->changedFields($data, $before)
                    : [...$this->changedFields($data, $before), 'image'],
                'status' => $response->status(),
            ],
            $response->successful(),
        );

        return $this->forward($response);
    }

    /* -------------------- Publishing and unpublishing ------------------- */

    /**
     * Publish an article.
     *
     * Required permission: `articles.publish` (publish article). A standalone
     * action, not a generic edit: one button in the list, a guard on the route
     * itself, and an explicit line in the log — so that "published an article"
     * never vanishes inside "edited an article".
     */
    #[ApiResponse(403, description: 'لا تملك صلاحية نشر المقالات.')]
    #[ApiResponse(502, description: 'منصّة المعرفة غير متاحة حالياً.')]
    public function publish(Request $request, string $slug): JsonResponse
    {
        return $this->changeStatus($request, $slug, 'published', 'articles.publish');
    }

    /**
     * Unpublish — return the article to draft.
     *
     * Required permission: `articles.draft` (unpublish). The article disappears
     * from the platform's visitors at once without any of its data being
     * deleted, and it can be published again at any time.
     */
    #[ApiResponse(403, description: 'لا تملك صلاحية سحب النشر.')]
    #[ApiResponse(502, description: 'منصّة المعرفة غير متاحة حالياً.')]
    public function draft(Request $request, string $slug): JsonResponse
    {
        return $this->changeStatus($request, $slug, 'draft', 'articles.draft');
    }

    /**
     * Delete an article from the knowledge platform.
     *
     * Required permission: `articles.delete` (delete article). An attempt to
     * delete without it is refused with a 403 and recorded in the audit log as a
     * failed attempt.
     */
    #[ApiResponse(403, description: 'لا تملك الصلاحية المطلوبة لهذي العملية.')]
    #[ApiResponse(502, description: 'منصّة المعرفة غير متاحة حالياً.')]
    public function destroy(Request $request, string $slug): JsonResponse
    {
        /*
         * We read the title before the delete, not after: once it is deleted it
         * can no longer be retrieved, and the log would be left saying "deleted
         * mynaaa-alkhbr", which means nothing to whoever reads it.
         *
         * The read is best effort, not a precondition: if it fails we go ahead
         * with the delete and log without a title. A failed read must never block
         * an operation that a permission holder asked for.
         */
        $title = null;

        try {
            $title = $this->platform->getArticle($slug)->json('data.title');
        } catch (\Throwable) {
            // Ignored: the title improves the log, it is not required for a valid delete
        }

        $response = $this->platform->deleteArticle($slug);

        $this->audit->record(
            $request,
            'articles.delete',
            AuditLogger::SUBJECT_ARTICLE,
            $slug,
            ['title' => $title, 'status' => $response->status()],
            $response->successful(),
        );

        return $this->forward($response);
    }

    /**
     * Refuses a change of publication status to anyone without the right to it.
     *
     * Two different rules, because these are two different operations:
     *
     *  - Making an article public (published / scheduled) requires
     *    `articles.publish`.
     *  - Pulling a published article back to draft requires `articles.draft`.
     *
     * Saving a new article as a draft is not an unpublish — it requires nothing
     * beyond articles.create. That is why we insist the article was published
     * beforehand ($previous) rather than merely that the requested status is
     * draft; otherwise an editor would be barred from writing a draft at all.
     */
    private function assertMayPublish(Request $request, ?string $requested, ?string $previous = null): void
    {
        if ($requested === null) {
            return;
        }

        $user = $request->user();

        if (in_array($requested, self::PUBLIC_STATUSES, true) && ! $user->can('articles.publish')) {
            throw ValidationException::withMessages([
                'status' => ['لا تملك صلاحية نشر المقالات. احفظه مسودةً، أو اطلب النشر ممّن يملكها.'],
            ]);
        }

        $isUnpublishing = $requested === 'draft'
            && in_array($previous, self::PUBLIC_STATUSES, true);

        if ($isUnpublishing && ! $user->can('articles.draft')) {
            throw ValidationException::withMessages([
                'status' => ['لا تملك صلاحية سحب النشر.'],
            ]);
        }
    }

    /**
     * Change an article's status as a standalone action.
     *
     * The title is read before the change, not after: the log has to say
     * "published Mina Al-Khobar", not "published mynaaa-alkhbr". The read is
     * best effort — its failure does not block the action.
     */
    private function changeStatus(Request $request, string $slug, string $status, string $action): JsonResponse
    {
        $title = null;

        try {
            $title = $this->platform->getArticle($slug)->json('data.title');
        } catch (\Throwable) {
            // Ignored: the title improves the log, it is not required for a valid action
        }

        $response = $this->platform->updateArticle($slug, ['status' => $status]);

        $this->audit->record(
            $request,
            $action,
            AuditLogger::SUBJECT_ARTICLE,
            $slug,
            ['title' => $title, 'status' => $response->status()],
            $response->successful(),
        );

        return $this->forward($response);
    }

    /**
     * Names of the fields whose values genuinely differ from the article as it
     * stood before the edit.
     *
     * If the previous state could not be read we fall back to what was sent —
     * the best estimate available; we do not drop the line entirely and leave
     * the log meaningless.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $before
     * @return array<int, string>
     */
    private function changedFields(array $data, ?array $before): array
    {
        if ($before === null) {
            return array_keys($data);
        }

        $changed = [];

        foreach ($data as $field => $value) {
            /*
             * Tags arrive as slugs and come back from the platform as objects,
             * and their order carries no meaning — so we compare two sorted sets
             * rather than two arrays.
             */
            if ($field === 'tags') {
                $now = collect($value)->sort()->values()->all();
                $was = collect($before['tags'] ?? [])->pluck('slug')->sort()->values()->all();

                if ($now !== $was) {
                    $changed[] = $field;
                }

                continue;
            }

            if (($before[$field] ?? null) !== $value) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private function forward(Response $response): JsonResponse
    {
        return response()->json($response->json() ?? [], $response->status());
    }
}
