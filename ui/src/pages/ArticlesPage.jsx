import { useCallback, useEffect, useState } from 'react'
import { api } from '../lib/api'
import { useAuth } from '../auth/AuthContext'
import {
  Alert,
  Button,
  CONTROL_CLASS,
  ConfirmDialog,
  EmptyState,
  Input,
  Modal,
  SelectControl,
  Spinner,
} from '../components/ui'
import { IconExternal, IconSearch } from '../components/icons'
import { statusTone } from '../lib/labels'

/*
 * Articles.
 *
 * Never stored here — the knowledge platform owns them, and this page
 * manages them through its API. That's why clicking an article opens its
 * real page on the platform, not a fake preview page inside the dashboard.
 */

const EMPTY_FORM = { title: '', content: '', tags: [] }

/*
 * Article statuses as defined by the knowledge platform. The values match
 * the Post::statuses() constants there, and the Arabic label comes from
 * the server in status_label — so we don't translate here, and no label
 * is ever defined in two places.
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

  /** The article's real URL on the knowledge platform. */
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

  // Debounced search: we don't send a request on every keystroke
  useEffect(() => {
    const timer = setTimeout(() => load(search, status), 350)
    return () => clearTimeout(timer)
  }, [search, status, load])

  /*
   * Categories are fetched from the knowledge platform once, when the page
   * opens — there's no copy of them in this project's database. A failed
   * fetch doesn't break the page: the editor still works without
   * categories, since they're an optional field on the platform anyway.
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

  /* ------------------------------ Details ----------------------------- */

  /*
   * The card only carries an excerpt; the full details need a separate
   * request because the articles list doesn't load comments or views. We
   * open the modal immediately with what we have, then replace it fully
   * once it arrives — so the user isn't left staring at a blank screen.
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

  /* ------------------------------ Editing ------------------------------ */

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
      // The platform returns categories as objects; we send back short identifiers
      tags: (article.tags ?? []).map((tag) => tag.slug),
    })
    setImage(null)
    setCurrentImage(article.image_url ?? null)
    setErrors({})
    setEditorOpen(true)
  }

  /**
   * Saving.
   *
   * status is only sent on creation: on edit we never touch the publish
   * state at all — changing it is a separate action with its own button
   * and permission, so no one accidentally unpublishes an article while
   * fixing a typo in it.
   */
  async function submit(statusOnCreate) {
    setBusy(true)
    setErrors({})

    try {
      const payload = editingSlug ? form : { ...form, status: statusOnCreate }

      if (editingSlug) {
        /*
         * With an image we send POST plus _method=PUT.
         *
         * PHP only decodes multipart on POST, so PUT arrives with an empty
         * body. Method spoofing is a native Laravel mechanism: the router
         * sees the request as PUT and matches the existing route — no new
         * route, no change to the contract.
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
   * Publish and unpublish are separate paths, not a generic PUT: the
   * permission is checked on the route itself, and the action shows up in
   * the log under its own name instead of being merged into "edit article".
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

  /* ------------------------------- Display ------------------------------- */

  return (
    <div>
      {/* Search and filter together at the start side, the create button alone
          at the other end — same structure and sizing as the users toolbar,
          so the two rows never differ between pages. */}
      <div className="mb-5 flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap items-center gap-2">
          <div className="relative">
            <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-ink-300">
              <IconSearch />
            </span>
            <input
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="ابحث بالعنوان أو المحتوى..."
              aria-label="بحث في المقالات"
              className={`${CONTROL_CLASS} w-64 pl-3 pr-9`}
            />
          </div>

          {/* Filtering happens on the knowledge platform, not here: the list is
              paginated, and filtering only the displayed page would hide
              articles from the other pages. */}
          <SelectControl
            value={status}
            onChange={(event) => setStatus(event.target.value)}
            aria-label="تصفية حسب حالة المقال"
          >
            {STATUS_FILTERS.map((item) => (
              <option key={item.value} value={item.value}>
                {item.label}
              </option>
            ))}
          </SelectControl>
        </div>

        {can('articles.create') && <Button onClick={openCreate}>مقال جديد</Button>}
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
             * An unpublished article has no public page: the platform
             * deliberately returns 404 to a visitor. So we disable the
             * button and state the reason, instead of sending the user to
             * an error that looks broken but is actually correct behavior.
             */
            const isPublic = article.status === 'published'

            return (
              <article
                key={article.id}
                className="group rounded-2xl border border-ink-100 bg-white p-4
                  transition-all hover:border-ink-300 hover:shadow-lift"
              >
                <div className="flex gap-4">
                  {/* The image also opens the article on the platform */}
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
                      The article title is a real link to the article on the
                      first-party platform. An <a> element, not onClick: it
                      opens with a middle click, its destination can be
                      copied, and a screen reader announces it as a link —
                      a fake button loses all of that.
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

                      {/* The Arabic label comes from the platform — we don't translate it here */}
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
                          className="inline-flex h-8 items-center gap-1.5 rounded-lg border
                            border-ink-200 px-3 text-xs font-semibold text-ink-700 transition-colors
                            hover:bg-ink-50"
                        >
                          <IconExternal />
                          عرض في المنصّة
                        </a>
                      ) : (
                        <span
                          title="المقال غير منشور، فلا صفحة له في المنصّة بعد."
                          className="inline-flex h-8 cursor-not-allowed items-center gap-1.5
                            rounded-lg border border-ink-100 px-3 text-xs font-semibold text-ink-300"
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

                      {/* Publish shows for the unpublished, unpublish for the published — each gated by its own permission */}
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

      {/* ------------------------------ Editor ---------------------------- */}

      <Modal
        open={editorOpen}
        wide
        title={editingSlug ? `تعديل المقال` : 'مقال جديد'}
        onClose={() => setEditorOpen(false)}
        footer={
          <>
            {/*
              Two explicit buttons on creation instead of a status dropdown:
              "Publish" simply doesn't appear for someone without
              articles.publish, so they never see an option that would be
              refused. The server rejects it anyway if the route is called
              directly.
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
              {/* The current image is shown when editing, so the user knows what they're replacing */}
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
            Categories come from the knowledge platform and aren't managed
            from here — an explicit requirement in the spec. Adding or
            editing a category happens there, and shows up here immediately.
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

      {/* ---------------------------- Details ---------------------------- */}

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
                The author's name is hidden on admin-authored articles — the
                platform itself doesn't send it in that case. Its absence
                isn't a data gap, it's a decision made on the platform.
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

              {/* The counts arrive with the details, not the list, so they may be absent the moment it opens */}
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
 * Converts the form into FormData so the image can be sent with it.
 *
 * Arrays are written as `tags[0]` and `tags[1]`: FormData doesn't know
 * nested arrays, and PHP reassembles this shape back into an array on
 * receipt. Empty values are dropped because they'd otherwise arrive as
 * the literal string "undefined".
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

/** Date in the knowledge platform's format: Gregorian with Latin numerals. */
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
