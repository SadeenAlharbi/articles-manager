import { useCallback, useEffect, useState } from 'react'
import { api } from '../lib/api'
import { useAuth } from '../auth/AuthContext'
import { Alert, Button, ConfirmDialog, EmptyState, Input, Modal, Spinner } from '../components/ui'
import { IconExternal, IconSearch } from '../components/icons'
import { statusTone } from '../lib/labels'

/*
 * المقالات.
 *
 * لا تُخزَّن هنا إطلاقاً — مالكها منصّة المعرفة، وهذي الصفحة تديرها عبر
 * واجهتها البرمجية. ولهذا فالضغط على المقال يفتح صفحته الحقيقية في
 * المنصّة، لا صفحة معاينة مقلّدة داخل اللوحة.
 */

const EMPTY_FORM = { title: '', content: '', tags: [] }

/*
 * حالات المقال كما تعرّفها منصّة المعرفة. القيم مطابقة لثوابت Post::statuses()
 * هناك، والمسمّى العربي يأتي من الخادم في status_label — فلا نترجم هنا ولا
 * يوجد مسمّى في مكانين.
 */
const STATUS_FILTERS = [
  { value: 'all', label: 'الكل' },
  { value: 'published', label: 'منشور' },
  { value: 'draft', label: 'مسودة' },
  { value: 'scheduled', label: 'مجدول' },
]

export default function ArticlesPage() {
  const { can, platformUrl } = useAuth()

  const [articles, setArticles] = useState([])
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('all')
  const [viewing, setViewing] = useState(null)
  const [viewingBusy, setViewingBusy] = useState(false)
  const [categories, setCategories] = useState([])
  const [image, setImage] = useState(null)
  const [currentImage, setCurrentImage] = useState(null)
  const [loading, setLoading] = useState(true)
  const [notice, setNotice] = useState(null)

  const [editorOpen, setEditorOpen] = useState(false)
  const [editingSlug, setEditingSlug] = useState(null)
  const [form, setForm] = useState(EMPTY_FORM)
  const [errors, setErrors] = useState({})
  const [confirming, setConfirming] = useState(null)
  const [busy, setBusy] = useState(false)

  /** رابط المقال الحقيقي في منصّة المعرفة. */
  const publicUrl = (slug) => (platformUrl ? `${platformUrl}/posts/${slug}` : null)

  const load = useCallback(async (term, statusFilter) => {
    setLoading(true)
    try {
      const response = await api('/articles', {
        params: { search: term, status: statusFilter, per_page: 12 },
      })
      setArticles(response.data ?? [])
    } catch (err) {
      setNotice({ tone: 'error', text: describe(err) })
    } finally {
      setLoading(false)
    }
  }, [])

  // بحث مؤجّل: لا نرسل طلباً مع كل حرف
  useEffect(() => {
    const timer = setTimeout(() => load(search, status), 350)
    return () => clearTimeout(timer)
  }, [search, status, load])

  /*
   * التصنيفات تُجلب من منصّة المعرفة مرة واحدة عند فتح الصفحة — لا نسخة منها
   * في قاعدة هذا المشروع. وفشل جلبها لا يُعطّل الصفحة: المحرّر يعمل بلا
   * تصنيفات، وهي حقل اختياري في المنصّة أصلاً.
   */
  useEffect(() => {
    api('/articles/categories')
      .then((response) => setCategories(response.data ?? []))
      .catch(() => setCategories([]))
  }, [])

  function toggleTag(slug) {
    setForm((current) => ({
      ...current,
      tags: current.tags.includes(slug)
        ? current.tags.filter((item) => item !== slug)
        : [...current.tags, slug],
    }))
  }

  /* ------------------------------ التفاصيل ----------------------------- */

  /*
   * البطاقة تحمل مقتطفاً فقط؛ التفاصيل الكاملة تحتاج طلباً مستقلاً لأن قائمة
   * المقالات لا تُحمّل التعليقات ولا المشاهدات. نفتح النافذة فوراً بما لدينا
   * ثم نستبدله بالكامل حين يصل — فلا ينتظر المستخدم شاشة فارغة.
   */
  async function openDetails(article) {
    setViewing(article)
    setViewingBusy(true)

    try {
      const response = await api(`/articles/${article.slug}`)
      setViewing(response.data ?? article)
    } catch (err) {
      setNotice({ tone: 'error', text: describe(err) })
    } finally {
      setViewingBusy(false)
    }
  }

  /* ------------------------------ التحرير ------------------------------ */

  function openCreate() {
    setEditingSlug(null)
    setForm(EMPTY_FORM)
    setImage(null)
    setCurrentImage(null)
    setErrors({})
    setEditorOpen(true)
  }

  function openEdit(article) {
    setEditingSlug(article.slug)
    setForm({
      title: article.title,
      content: article.content,
      // المنصّة تُرجع التصنيفات كائناتٍ، ونرسلها إليها معرّفات مختصرة
      tags: (article.tags ?? []).map((tag) => tag.slug),
    })
    setImage(null)
    setCurrentImage(article.image_url ?? null)
    setErrors({})
    setEditorOpen(true)
  }

  /**
   * الحفظ.
   *
   * status يُرسَل عند الإنشاء فقط: عند التعديل لا نمسّ حالة النشر إطلاقاً —
   * تغييرها إجراء مستقل له زرّه وصلاحيته، فلا يسحب أحدٌ نشر مقال وهو يصحّح
   * خطأً إملائياً فيه.
   */
  async function submit(statusOnCreate) {
    setBusy(true)
    setErrors({})

    try {
      const payload = editingSlug ? form : { ...form, status: statusOnCreate }

      if (editingSlug) {
        /*
         * مع صورة نرسل POST ومعه _method=PUT.
         *
         * PHP لا يفكّ ترميز multipart إلا في POST، فـPUT يصل بجسم فارغ. وانتحال
         * الطريقة آلية أصلية في Laravel: الموجّه يرى الطلب PUT فيطابق المسار
         * القائم — فلا مسار جديد ولا تغيير في العقد.
         */
        await (image
          ? api(`/articles/${editingSlug}`, {
              method: 'POST',
              body: toFormData({ ...payload, _method: 'PUT' }, image),
            })
          : api(`/articles/${editingSlug}`, { method: 'PUT', body: payload }))

        setNotice({ tone: 'success', text: 'تم حفظ التعديل في منصّة المعرفة.' })
      } else {
        await api('/articles', {
          method: 'POST',
          body: image ? toFormData(payload, image) : payload,
        })

        setNotice({
          tone: 'success',
          text: statusOnCreate === 'published'
            ? 'نُشر المقال في منصّة المعرفة.'
            : 'حُفظ المقال مسودةً — لا يراه زوّار المنصّة.',
        })
      }

      setEditorOpen(false)
      await load(search, status)
    } catch (err) {
      setErrors(flatten(err))
      if (!err.data?.errors) setNotice({ tone: 'error', text: describe(err) })
    } finally {
      setBusy(false)
    }
  }

  /*
   * النشر والسحب مساران مستقلّان لا PUT عام: الصلاحية تُفحص على المسار نفسه،
   * والعملية تظهر في السجلّ باسمها لا مندمجةً في «تعديل مقال».
   */
  async function changeStatus(article, action) {
    setBusy(true)
    setNotice(null)

    try {
      await api(`/articles/${article.slug}/${action}`, { method: 'POST' })
      setNotice({
        tone: 'success',
        text: action === 'publish'
          ? `نُشر «${article.title}» في منصّة المعرفة.`
          : `سُحب نشر «${article.title}» وصار مسودة. لم تُحذف بياناته.`,
      })
      await load(search, status)
    } catch (err) {
      setNotice({ tone: 'error', text: describe(err) })
    } finally {
      setBusy(false)
    }
  }

  async function confirmDelete() {
    setBusy(true)

    try {
      await api(`/articles/${confirming.slug}`, { method: 'DELETE' })
      setNotice({ tone: 'success', text: 'تم حذف المقال من منصّة المعرفة.' })
      setConfirming(null)
      await load(search, status)
    } catch (err) {
      setNotice({ tone: 'error', text: describe(err) })
      setConfirming(null)
    } finally {
      setBusy(false)
    }
  }

  /* ------------------------------- العرض ------------------------------- */

  return (
    <div>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div className="relative">
          <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-ink-300">
            <IconSearch />
          </span>
          <input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="ابحث بالعنوان أو المحتوى..."
            aria-label="بحث في المقالات"
            className="w-72 rounded-xl border border-ink-200 bg-white py-2 pr-9 pl-3 text-sm
              shadow-card outline-none transition-colors focus:border-brand-500"
          />
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {/* التصفية تُنفَّذ في منصّة المعرفة لا هنا: القائمة مُرقَّمة، وتصفية
              الصفحة المعروضة وحدها تُخفي مقالات الصفحات الأخرى. */}
          <select
            value={status}
            onChange={(event) => setStatus(event.target.value)}
            aria-label="تصفية حسب حالة المقال"
            className="rounded-xl border border-ink-200 bg-white px-3 py-2 text-sm
              shadow-card outline-none transition-colors focus:border-brand-500"
          >
            {STATUS_FILTERS.map((item) => (
              <option key={item.value} value={item.value}>
                {item.label}
              </option>
            ))}
          </select>

          {can('articles.create') && <Button onClick={openCreate}>مقال جديد</Button>}
        </div>
      </div>

      <Alert tone={notice?.tone} onDismiss={() => setNotice(null)}>
        {notice?.text}
      </Alert>

      {loading ? (
        <Spinner />
      ) : articles.length === 0 ? (
        <EmptyState
          title="لا توجد مقالات مطابقة"
          description={
            search
              ? 'جرّب كلمة بحث أخرى.'
              : status !== 'all'
                ? 'لا توجد مقالات بهذي الحالة. جرّب «الكل».'
                : 'لم يُنشأ أي مقال في المنصّة بعد.'
          }
          action={can('articles.create') ? <Button onClick={openCreate}>إضافة أول مقال</Button> : null}
        />
      ) : (
        <div className="flex flex-col gap-3">
          {articles.map((article) => {
            const url = publicUrl(article.slug)

            /*
             * غير المنشور ليس له صفحة عامة: المنصّة تُرجع 404 للزائر عمداً.
             * فنعطّل الزر ونقول السبب، بدل أن نرسل المستخدم إلى خطأ يبدو عطلاً
             * وهو سلوك صحيح.
             */
            const isPublic = article.status === 'published'

            return (
              <article
                key={article.id}
                className="group rounded-2xl border border-ink-200 bg-white p-4 shadow-card
                  transition-all hover:border-ink-300 hover:shadow-lift"
              >
                <div className="flex gap-4">
                  {/* الصورة تفتح المقال في المنصّة أيضاً */}
                  {article.image_url && (
                    <a
                      href={url ?? '#'}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="hidden h-20 w-28 shrink-0 overflow-hidden rounded-xl bg-ink-50 sm:block"
                      tabIndex={-1}
                      aria-hidden="true"
                    >
                      <img
                        src={article.image_url}
                        alt=""
                        loading="lazy"
                        className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                      />
                    </a>
                  )}

                  <div className="min-w-0 flex-1">
                    {/*
                      عنوان المقال رابط حقيقي للمقال في المنصّة الأولى.
                      عنصر <a> لا onClick: يُفتح بالنقر الأوسط، وتُنسخ وجهته،
                      ويقرأه قارئ الشاشة رابطاً — وهذا ما يفقده زر مزيّف.
                    */}
                    <div className="flex flex-wrap items-center gap-2">
                      {isPublic ? (
                        <a
                          href={url ?? '#'}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="inline-flex items-start gap-1.5 font-bold leading-snug text-ink-900
                            transition-colors hover:text-brand-600"
                        >
                          {article.title}
                          <span className="mt-0.5 shrink-0 text-ink-300 transition-colors group-hover:text-brand-500">
                            <IconExternal />
                          </span>
                        </a>
                      ) : (
                        <span className="font-bold leading-snug text-ink-900">{article.title}</span>
                      )}

                      {/* المسمّى العربي يأتي من المنصّة — لا نترجمه هنا */}
                      {article.status_label && (
                        <span
                          className={`shrink-0 rounded-full px-2.5 py-0.5 text-[11px] font-semibold
                            ${statusTone(article.status)}`}
                        >
                          {article.status_label}
                        </span>
                      )}
                    </div>

                    <p className="mt-2 line-clamp-2 text-sm leading-relaxed text-ink-500">
                      {article.content}
                    </p>

                    <div className="mt-3 flex flex-wrap items-center gap-1.5">
                      {isPublic ? (
                        <a
                          href={url ?? '#'}
                          target="_blank"
                          rel="noopener noreferrer"
                          className="inline-flex items-center gap-1.5 rounded-lg border border-ink-200
                            px-3 py-1.5 text-xs font-semibold text-ink-700 transition-colors hover:bg-ink-50"
                        >
                          <IconExternal />
                          عرض في المنصّة
                        </a>
                      ) : (
                        <span
                          title="المقال غير منشور، فلا صفحة له في المنصّة بعد."
                          className="inline-flex cursor-not-allowed items-center gap-1.5 rounded-lg
                            border border-ink-100 px-3 py-1.5 text-xs font-semibold text-ink-300"
                        >
                          <IconExternal />
                          غير منشور في المنصّة
                        </span>
                      )}

                      <Button variant="secondary" size="sm" onClick={() => openDetails(article)}>
                        التفاصيل
                      </Button>

                      {can('articles.update') && (
                        <Button variant="secondary" size="sm" onClick={() => openEdit(article)}>
                          تعديل
                        </Button>
                      )}

                      {/* النشر يظهر لغير المنشور، والسحب للمنشور — كلٌّ بصلاحيته */}
                      {article.status !== 'published' && can('articles.publish') && (
                        <Button
                          size="sm"
                          disabled={busy}
                          onClick={() => changeStatus(article, 'publish')}
                        >
                          نشر
                        </Button>
                      )}

                      {article.status === 'published' && can('articles.draft') && (
                        <Button
                          variant="secondary"
                          size="sm"
                          disabled={busy}
                          onClick={() => changeStatus(article, 'draft')}
                        >
                          سحب النشر
                        </Button>
                      )}

                      {can('articles.delete') && (
                        <Button variant="danger" size="sm" onClick={() => setConfirming(article)}>
                          حذف
                        </Button>
                      )}
                    </div>
                  </div>
                </div>
              </article>
            )
          })}
        </div>
      )}

      {/* ------------------------------ المحرّر ---------------------------- */}

      <Modal
        open={editorOpen}
        wide
        title={editingSlug ? `تعديل المقال` : 'مقال جديد'}
        onClose={() => setEditorOpen(false)}
        footer={
          <>
            {/*
              زرّان صريحان عند الإنشاء بدل قائمة حالة: «نشر» لا يظهر أصلاً لمن
              لا يملك articles.publish، فلا يرى خياراً سيُرفض. والخادم يرفضه
              على أي حال لو استُدعي المسار مباشرة.
            */}
            {!editingSlug && can('articles.publish') && (
              <Button onClick={() => submit('published')} disabled={busy}>
                {busy ? 'جارِ الحفظ...' : 'نشر المقال'}
              </Button>
            )}

            <Button
              variant={!editingSlug && can('articles.publish') ? 'secondary' : 'primary'}
              onClick={() => submit(editingSlug ? undefined : 'draft')}
              disabled={busy}
            >
              {busy
                ? 'جارِ الحفظ...'
                : editingSlug
                  ? 'حفظ التعديل'
                  : 'حفظ كمسودة'}
            </Button>

            <Button variant="secondary" onClick={() => setEditorOpen(false)} disabled={busy}>
              إلغاء
            </Button>
          </>
        }
      >
        <form onSubmit={(event) => event.preventDefault()} className="flex flex-col gap-3.5">
          <Input
            name="title"
            label="عنوان المقال"
            value={form.title}
            onChange={(event) => setForm({ ...form, title: event.target.value })}
            error={errors.title}
            required
          />

          <div>
            <label htmlFor="content" className="mb-1.5 block text-sm font-semibold text-ink-700">
              محتوى المقال
            </label>
            <textarea
              id="content"
              rows={9}
              value={form.content}
              onChange={(event) => setForm({ ...form, content: event.target.value })}
              required
              aria-invalid={Boolean(errors.content)}
              className={`w-full rounded-xl border bg-white px-3 py-2 text-sm leading-relaxed
                outline-none transition-colors focus:border-brand-500
                ${errors.content ? 'border-red-300' : 'border-ink-200'}`}
            />
            {errors.content && <p className="mt-1 text-xs text-red-600">{errors.content}</p>}
          </div>

          <div>
            <label htmlFor="image" className="mb-1.5 block text-sm font-semibold text-ink-700">
              صورة المقال
              <span className="mr-1.5 text-xs font-normal text-ink-400">(اختياري)</span>
            </label>

            <div className="flex items-start gap-3">
              {/* الصورة الحالية تُعرض عند التعديل، فيعرف المستخدم ما سيستبدله */}
              {(image || currentImage) && (
                <img
                  src={image ? URL.createObjectURL(image) : currentImage}
                  alt=""
                  className="h-20 w-28 shrink-0 rounded-xl border border-ink-200 object-cover"
                />
              )}

              <div className="min-w-0 flex-1">
                <input
                  id="image"
                  type="file"
                  accept="image/jpeg,image/png,image/webp"
                  onChange={(event) => setImage(event.target.files?.[0] ?? null)}
                  className="w-full text-sm text-ink-600 file:mr-0 file:ml-3 file:cursor-pointer
                    file:rounded-lg file:border-0 file:bg-ink-100 file:px-3 file:py-1.5
                    file:text-sm file:font-semibold file:text-ink-700 hover:file:bg-ink-200"
                />

                <p className="mt-1 text-xs text-ink-400">
                  JPEG أو PNG أو WEBP، بحدّ أقصى 5 ميجابايت.
                  {editingSlug && currentImage && ' اتركه فارغاً للإبقاء على الصورة الحالية.'}
                </p>

                {image && (
                  <button
                    type="button"
                    onClick={() => setImage(null)}
                    className="mt-1 text-xs font-semibold text-red-600 hover:underline"
                  >
                    إلغاء الصورة المختارة
                  </button>
                )}

                {errors.image && <p className="mt-1 text-xs text-red-600">{errors.image}</p>}
              </div>
            </div>
          </div>

          {/*
            التصنيفات تأتي من منصّة المعرفة ولا تُدار من هنا — بند صريح في
            المواصفة. فإضافة تصنيف جديد أو تعديله يقع هناك، ويظهر هنا فوراً.
          */}
          {categories.length > 0 && (
            <div>
              <label className="mb-1.5 block text-sm font-semibold text-ink-700">
                التصنيفات
                <span className="mr-1.5 text-xs font-normal text-ink-400">(اختياري)</span>
              </label>

              <div className="max-h-44 overflow-y-auto rounded-xl border border-ink-200 p-2">
                <div className="grid gap-1 sm:grid-cols-2">
                  {categories.map((category) => {
                    const chosen = form.tags.includes(category.slug)

                    return (
                      <label
                        key={category.slug}
                        className={`flex cursor-pointer items-center gap-2 rounded-lg px-2.5 py-1.5
                          text-sm transition-colors ${
                            chosen ? 'bg-brand-50 text-brand-700' : 'hover:bg-ink-50'
                          }`}
                      >
                        <input
                          type="checkbox"
                          className="accent-brand-600"
                          checked={chosen}
                          onChange={() => toggleTag(category.slug)}
                        />
                        <span>{category.name}</span>
                      </label>
                    )
                  })}
                </div>
              </div>

              {form.tags.length > 0 && (
                <p className="mt-1.5 text-xs text-ink-400">
                  {form.tags.length} تصنيف مختار
                </p>
              )}

              {errors.tags && <p className="mt-1 text-xs text-red-600">{errors.tags}</p>}
            </div>
          )}
        </form>
      </Modal>

      {/* ---------------------------- التفاصيل ---------------------------- */}

      <Modal
        open={Boolean(viewing)}
        wide
        title={viewing?.title ?? ''}
        onClose={() => setViewing(null)}
        footer={
          <>
            {viewing?.status === 'published' ? (
              <a
                href={publicUrl(viewing?.slug) ?? '#'}
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1.5 rounded-xl bg-brand-600 px-4 py-2
                  text-sm font-semibold text-white transition-colors hover:bg-brand-700"
              >
                <IconExternal />
                عرض في المنصّة
              </a>
            ) : (
              <span className="text-xs text-ink-400">
                لا صفحة عامة لهذا المقال — انشره أولاً ليظهر في المنصّة.
              </span>
            )}
            <Button variant="secondary" onClick={() => setViewing(null)}>
              إغلاق
            </Button>
          </>
        }
      >
        {viewing && (
          <div className="flex flex-col gap-4">
            <dl className="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[max-content_1fr]">
              <dt className="font-semibold text-ink-500">الحالة</dt>
              <dd>
                <span
                  className={`inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold
                    ${statusTone(viewing.status)}`}
                >
                  {viewing.status_label ?? '—'}
                </span>
              </dd>

              {/*
                اسم الكاتب يُخفى في مقالات الإدارة — المنصّة نفسها لا ترسله
                حينها. فغيابه ليس نقصاً في البيانات بل قرار في المنصّة.
              */}
              <dt className="font-semibold text-ink-500">الكاتب</dt>
              <dd className="text-ink-800">{viewing.author?.name ?? 'الإدارة'}</dd>

              <dt className="font-semibold text-ink-500">تاريخ النشر</dt>
              <dd className="text-ink-800">{formatDate(viewing.published_at)}</dd>

              {Array.isArray(viewing.tags) && viewing.tags.length > 0 && (
                <>
                  <dt className="font-semibold text-ink-500">التصنيفات</dt>
                  <dd className="flex flex-wrap gap-1.5">
                    {viewing.tags.map((tag) => (
                      <span
                        key={tag.id ?? tag.slug}
                        className="rounded-full bg-ink-50 px-2.5 py-0.5 text-xs text-ink-600"
                      >
                        {tag.name}
                      </span>
                    ))}
                  </dd>
                </>
              )}

              {/* الأرقام تصل مع التفاصيل لا مع القائمة، فقد تكون غائبة لحظة الفتح */}
              {viewing.views_count !== undefined && (
                <>
                  <dt className="font-semibold text-ink-500">المشاهدات</dt>
                  <dd className="text-ink-800">{viewing.views_count}</dd>
                </>
              )}

              {viewing.comments_count !== undefined && (
                <>
                  <dt className="font-semibold text-ink-500">التعليقات</dt>
                  <dd className="text-ink-800">{viewing.comments_count}</dd>
                </>
              )}
            </dl>

            <div>
              <p className="mb-1.5 text-sm font-semibold text-ink-500">المحتوى</p>
              <div className="max-h-72 overflow-y-auto rounded-xl border border-ink-100 bg-ink-25
                px-3.5 py-3 text-sm leading-loose whitespace-pre-line text-ink-700">
                {viewing.content}
              </div>
            </div>

            {viewingBusy && <p className="text-xs text-ink-400">جارِ تحميل التفاصيل الكاملة...</p>}
          </div>
        )}
      </Modal>

      <ConfirmDialog
        open={Boolean(confirming)}
        busy={busy}
        title="حذف المقال"
        confirmLabel="حذف"
        message={`سيُحذف «${confirming?.title}» من منصّة المعرفة. تُسجَّل العملية في سجلّ العمليات باسمك.`}
        onConfirm={confirmDelete}
        onClose={() => setConfirming(null)}
      />
    </div>
  )
}

/* -------------------------------------------------------------------------- */

function flatten(err) {
  const fields = err.data?.errors ?? {}

  return Object.fromEntries(
    Object.entries(fields).map(([field, messages]) => [field, messages[0]])
  )
}

/**
 * تحويل النموذج إلى FormData لإرسال الصورة معه.
 *
 * المصفوفات تُكتب `tags[0]` و`tags[1]`: FormData لا يعرف المصفوفات المتداخلة،
 * وهذي الصيغة يعيد PHP تجميعها مصفوفةً عند الاستقبال. والقيم الفارغة تُسقَط
 * لأنها تصل نصّاً «undefined» لو أُرسلت.
 */
function toFormData(payload, image) {
  const data = new FormData()

  Object.entries(payload).forEach(([key, value]) => {
    if (value === null || value === undefined) return

    if (Array.isArray(value)) {
      value.forEach((item, index) => data.append(`${key}[${index}]`, item))

      return
    }

    data.append(key, value)
  })

  if (image) data.append('image', image)

  return data
}

/** التاريخ بصيغة منصّة المعرفة: ميلادي بأرقام لاتينية. */
function formatDate(value) {
  if (! value) return '— (لم يُنشر بعد)'

  const at = new Date(value)
  if (Number.isNaN(at.getTime())) return '—'

  const pad = (n) => String(n).padStart(2, '0')

  return `${at.getFullYear()}/${pad(at.getMonth() + 1)}/${pad(at.getDate())}`
}

function describe(err) {
  if (err.status === 403) return 'رُفضت العملية: لا تملك الصلاحية المطلوبة (403).'
  if (err.status === 422) return `بيانات غير صالحة: ${err.message}`
  if (err.status === 502) return 'منصّة المعرفة غير متاحة حالياً (502).'
  if (err.status === 401) return 'انتهت الجلسة. سجّل الدخول من جديد.'
  return err.message
}
