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
 * كل مسار هنا ينقل الطلب إلى منصّة المعرفة — لا يُخزَّن مقال في هذي القاعدة.
 * ولهذا يظهر الرمز 502 في كل عملية: هو تعذّر الوصول إلى المنصّة، لا خطأ فينا.
 */
#[Group('المقالات', 'إدارة مقالات منصّة المعرفة عبر واجهتها البرمجية.', weight: 1)]
class ArticleController extends Controller
{
    /** حالات المقال كما تعرّفها منصّة المعرفة — القيم مطابقة لـPost::statuses(). */
    private const STATUSES = ['draft', 'published', 'scheduled'];

    /** الحالات التي تجعل المقال مرئياً للعامة، فتلزمها صلاحية النشر. */
    private const PUBLIC_STATUSES = ['published', 'scheduled'];

    public function __construct(
        private readonly KnowledgePlatform $platform,
        private readonly AuditLogger $audit,
    ) {}

    /* ------------------------------- قراءة ------------------------------- */

    /**
     * قائمة المقالات.
     *
     * تُجلب من منصّة المعرفة مباشرة. الصلاحية المطلوبة: `articles.view` (عرض المقالات).
     *
     * المسودات والمقالات المجدولة تظهر عبر `status`، لأن توكن الخدمة الذي تحمله
     * اللوحة يخصّ حساب مشرف في المنصّة. أما زائر المنصّة فلا يرى إلا المنشور،
     * والحارس هناك لا هنا.
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
     * تفاصيل مقال واحد.
     *
     * الصلاحية المطلوبة: `articles.view` (عرض المقالات).
     */
    #[ApiResponse(403, description: 'لا تملك الصلاحية المطلوبة لهذي العملية.')]
    #[ApiResponse(502, description: 'منصّة المعرفة غير متاحة حالياً.')]
    public function show(string $slug): JsonResponse
    {
        return $this->forward($this->platform->getArticle($slug));
    }

    /**
     * تصنيفات منصّة المعرفة.
     *
     * الصلاحية المطلوبة: `articles.view` (عرض المقالات) — لأنها تُستعمل في
     * نموذجَي إضافة المقال وتعديله.
     *
     * تُقرأ من المنصّة عند كل طلب ولا تُخزَّن نسخة منها هنا: أي تصنيف يُضاف
     * هناك يظهر فوراً، وإدارة التصنيفات تبقى في المنصّة وحدها.
     */
    #[ApiResponse(403, description: 'لا تملك الصلاحية المطلوبة لهذي العملية.')]
    #[ApiResponse(502, description: 'منصّة المعرفة غير متاحة حالياً.')]
    public function categories(): JsonResponse
    {
        return $this->forward($this->platform->listTags());
    }

    /* ------------------------------- كتابة ------------------------------- */

    /**
     * إنشاء مقال في منصّة المعرفة.
     *
     * الصلاحية المطلوبة: `articles.create` (إضافة مقال). تُسجَّل العملية في سجلّ
     * العمليات باسم المنفّذ، سواء نجحت أو رفضتها المنصّة.
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
         * القواعد نسخة من قواعد المنصّة عمداً لا اختصاراً لها: نرفض الملف
         * الضخم أو غير الصالح **قبل** رفعه عبر الشبكة، ولا نعتمد على رفضها
         * وحده — طبقتان لا واحدة.
         */
        $image = $request->file('image');
        unset($data['image']);

        /*
         * الإنشاء كمسودة متاح لكل من يملك articles.create — المسودة ليست نشراً.
         * أما الإنشاء منشوراً فيلزمه articles.publish، وإلا لنشر الكاتبُ مقالاً
         * بإرسال status=published مباشرة إلى المسار متجاوزاً واجهةً لا تعرض له
         * الخيار أصلاً.
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
     * تعديل مقال.
     *
     * الصلاحية المطلوبة: `articles.update` (تعديل مقال). الحقول المرسَلة فقط هي
     * التي تُعدَّل — أي حقل غائب يبقى كما هو في المنصّة.
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
         * نقرأ المقال قبل تعديله لنعرف ما تغيّر فعلاً.
         *
         * الواجهة ترسل النموذج كاملاً في كل حفظ، فـarray_keys($data) تعني
         * «ما أُرسل» لا «ما تعدّل». وسجلّ يقول إن العنوان تغيّر وهو لم يتغيّر
         * سجلٌّ كاذب — ولو سُئل «مَن غيّر عنوان هذا المقال؟» لاتّهم بريئاً.
         *
         * والمقارنة هنا لا في React: لو تركناها للعميل لصار صدق السجلّ رهناً
         * به، وأي عميل آخر — أو curl — يُفسده.
         */
        $before = null;

        try {
            $before = $this->platform->getArticle($slug)->json('data');
        } catch (\Throwable) {
            // أفضل جهد: تعذّر القراءة لا يمنع التعديل
        }

        $this->assertMayPublish($request, $data['status'] ?? null, $before['status'] ?? null);

        $response = $this->platform->updateArticle($slug, $data, $image);

        $this->audit->record(
            $request,
            'articles.update',
            AuditLogger::SUBJECT_ARTICLE,
            $slug,
            [
                // العنوان بعد التعديل كما أعادته المنصّة، وإلا المرسَل، وإلا السابق
                'title' => $response->json('data.title')
                    ?? ($data['title'] ?? null)
                    ?? ($before['title'] ?? null),
                /*
                 * الصورة تُضاف إلى قائمة ما تغيّر يدوياً: لا يمكن مقارنة ملف
                 * مرفوع بعنوان صورة قديمة، ووجود ملف جديد يعني التغيير بذاته.
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

    /* --------------------------- النشر والسحب --------------------------- */

    /**
     * نشر مقال.
     *
     * الصلاحية المطلوبة: `articles.publish` (نشر مقال). إجراء مستقل لا تعديلٌ
     * عام: زرّ واحد في القائمة، وحارس على المسار نفسه، وسطر صريح في السجلّ —
     * فلا يختفي «نشر مقال» داخل «تعديل مقال».
     */
    #[ApiResponse(403, description: 'لا تملك صلاحية نشر المقالات.')]
    #[ApiResponse(502, description: 'منصّة المعرفة غير متاحة حالياً.')]
    public function publish(Request $request, string $slug): JsonResponse
    {
        return $this->changeStatus($request, $slug, 'published', 'articles.publish');
    }

    /**
     * سحب النشر — إرجاع المقال إلى مسودة.
     *
     * الصلاحية المطلوبة: `articles.draft` (سحب النشر). المقال يختفي عن زوّار
     * المنصّة فوراً ولا تُحذف بياناته، ويمكن نشره من جديد في أي وقت.
     */
    #[ApiResponse(403, description: 'لا تملك صلاحية سحب النشر.')]
    #[ApiResponse(502, description: 'منصّة المعرفة غير متاحة حالياً.')]
    public function draft(Request $request, string $slug): JsonResponse
    {
        return $this->changeStatus($request, $slug, 'draft', 'articles.draft');
    }

    /**
     * حذف مقال من منصّة المعرفة.
     *
     * الصلاحية المطلوبة: `articles.delete` (حذف مقال). ومحاولة الحذف بلا هذي
     * الصلاحية تُرفض بالرمز 403 وتُسجَّل في سجلّ العمليات كمحاولة فاشلة.
     */
    #[ApiResponse(403, description: 'لا تملك الصلاحية المطلوبة لهذي العملية.')]
    #[ApiResponse(502, description: 'منصّة المعرفة غير متاحة حالياً.')]
    public function destroy(Request $request, string $slug): JsonResponse
    {
        /*
         * نقرأ العنوان قبل الحذف لا بعده: بعد الحذف يصير غير قابل للاسترجاع،
         * فيبقى السجلّ يقول «حُذف mynaaa-alkhbr» بلا معنى لقارئه.
         *
         * والقراءة أفضل جهد لا شرط: لو تعذّرت، نمضي في الحذف ونسجّل بلا عنوان.
         * فشل القراءة لا يجوز أن يمنع عملية طلبها صاحب الصلاحية.
         */
        $title = null;

        try {
            $title = $this->platform->getArticle($slug)->json('data.title');
        } catch (\Throwable) {
            // نتجاهل: العنوان تحسين للسجلّ لا شرط لصحّة الحذف
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
     * يرفض تغيير حالة النشر لمن لا يملك صلاحيته.
     *
     * قاعدتان مختلفتان لأن العمليتين مختلفتان:
     *
     *  - إظهار المقال للعامة (published / scheduled) يلزمه `articles.publish`.
     *  - سحبُ منشورٍ إلى مسودة يلزمه `articles.draft`.
     *
     * وحفظ مقال جديد كمسودة ليس سحباً — لا يلزمه شيء زائد على articles.create.
     * ولهذا نشترط أن يكون المقال منشوراً قبلها ($previous) لا مجرّد أن الحالة
     * المطلوبة مسودة، وإلا لمُنع المحرّرُ من كتابة مسودة أصلاً.
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
     * تغيير حالة المقال كإجراء مستقل.
     *
     * العنوان يُقرأ قبل التغيير لا بعده: السجلّ يجب أن يقول «نُشر ميناء الخبر»
     * لا «نُشر mynaaa-alkhbr». والقراءة أفضل جهد — فشلها لا يمنع الإجراء.
     */
    private function changeStatus(Request $request, string $slug, string $status, string $action): JsonResponse
    {
        $title = null;

        try {
            $title = $this->platform->getArticle($slug)->json('data.title');
        } catch (\Throwable) {
            // نتجاهل: العنوان تحسين للسجلّ لا شرط لصحّة الإجراء
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
     * أسماء الحقول التي اختلفت قيمتها فعلاً عن المقال قبل التعديل.
     *
     * إن تعذّرت قراءة الحالة السابقة نعود إلى ما أُرسل — أفضل تقدير متاح، ولا
     * نُسقط السطر كاملاً فيفقد السجلّ معناه.
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
             * التصنيفات تصل معرّفاتٍ مختصرة وتعود من المنصّة كائناتٍ، والترتيب
             * فيها لا يعني شيئاً — فنقارن مجموعتين مرتّبتين لا مصفوفتين.
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
