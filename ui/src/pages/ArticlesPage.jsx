import { useCallback, useEffect, useState } from 'react'
import { api } from '../lib/api'
import { useAuth } from '../auth/AuthContext'

const EMPTY_FORM = { title: '', content: '' }

export default function ArticlesPage() {
  const { can } = useAuth()

  const [articles, setArticles] = useState([])
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [notice, setNotice] = useState(null)

  const [form, setForm] = useState(EMPTY_FORM)
  const [editingSlug, setEditingSlug] = useState(null)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async (term = '') => {
    setLoading(true)
    try {
      const response = await api('/articles', { params: { search: term, per_page: 10 } })
      setArticles(response.data ?? [])
    } catch (err) {
      setNotice({ tone: 'error', text: err.message })
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  // بحث مؤجّل: لا نرسل طلباً مع كل حرف.
  useEffect(() => {
    const timer = setTimeout(() => load(search), 350)
    return () => clearTimeout(timer)
  }, [search, load])

  async function submitForm(event) {
    event.preventDefault()
    setBusy(true)
    setNotice(null)

    try {
      if (editingSlug) {
        await api(`/articles/${editingSlug}`, { method: 'PUT', body: form })
        setNotice({ tone: 'ok', text: 'تم تعديل المقال.' })
      } else {
        await api('/articles', { method: 'POST', body: form })
        setNotice({ tone: 'ok', text: 'تم إنشاء المقال في منصّة المعرفة.' })
      }

      setForm(EMPTY_FORM)
      setEditingSlug(null)
      await load(search)
    } catch (err) {
      setNotice({ tone: 'error', text: describe(err) })
    } finally {
      setBusy(false)
    }
  }

  async function remove(slug) {
    if (!window.confirm('حذف هذا المقال من منصّة المعرفة؟')) return

    setNotice(null)
    try {
      await api(`/articles/${slug}`, { method: 'DELETE' })
      setNotice({ tone: 'ok', text: 'تم حذف المقال.' })
      await load(search)
    } catch (err) {
      setNotice({ tone: 'error', text: describe(err) })
    }
  }

  /**
   * زر العرض في المناقشة: يستدعي الحذف رغم أن الزر العادي مخفيّ.
   * يُثبت أن الإخفاء تجميلي وأن الرفض يقع في الخادم برمز 403.
   */
  async function probe(slug) {
    setNotice(null)
    try {
      await api(`/articles/${slug}`, { method: 'DELETE' })
      setNotice({ tone: 'error', text: 'تحذير: نجح الحذف — الخادم لم يمنع!' })
      await load(search)
    } catch (err) {
      setNotice({
        tone: 'ok',
        text: `الخادم رفض العملية برمز ${err.status} — الحماية ليست في إخفاء الزر.`,
      })
    }
  }

  return (
    <div>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-xl font-bold text-ink-900">المقالات</h2>
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="ابحث بالعنوان أو المحتوى..."
          className="w-64 rounded-xl border border-ink-200 bg-white px-3 py-2 text-sm outline-none focus:border-brand-500"
        />
      </div>

      {notice && (
        <p
          className={`mb-4 rounded-xl px-3 py-2 text-sm ${
            notice.tone === 'ok' ? 'bg-brand-50 text-brand-700' : 'bg-red-50 text-red-700'
          }`}
        >
          {notice.text}
        </p>
      )}

      {(can('articles.create') || editingSlug) && (
        <form onSubmit={submitForm} className="mb-6 rounded-2xl border border-ink-200 bg-white p-5">
          <h3 className="mb-3 text-sm font-bold text-ink-700">
            {editingSlug ? `تعديل: ${editingSlug}` : 'مقال جديد'}
          </h3>

          <input
            value={form.title}
            onChange={(e) => setForm({ ...form, title: e.target.value })}
            placeholder="العنوان"
            required
            className="w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500"
          />

          <textarea
            value={form.content}
            onChange={(e) => setForm({ ...form, content: e.target.value })}
            placeholder="المحتوى"
            required
            rows={4}
            className="mt-3 w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500"
          />

          <div className="mt-3 flex gap-2">
            <button
              type="submit"
              disabled={busy}
              className="rounded-xl bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700 disabled:opacity-60"
            >
              {editingSlug ? 'حفظ التعديل' : 'إضافة المقال'}
            </button>

            {editingSlug && (
              <button
                type="button"
                onClick={() => { setEditingSlug(null); setForm(EMPTY_FORM) }}
                className="rounded-xl border border-ink-200 px-4 py-2 text-sm font-semibold text-ink-700 hover:bg-ink-50"
              >
                إلغاء
              </button>
            )}
          </div>
        </form>
      )}

      {loading ? (
        <p className="text-sm text-ink-400">جارِ التحميل...</p>
      ) : articles.length === 0 ? (
        <p className="text-sm text-ink-400">لا توجد مقالات مطابقة.</p>
      ) : (
        <div className="flex flex-col gap-3">
          {articles.map((article) => (
            <article key={article.id} className="rounded-2xl border border-ink-200 bg-white p-4">
              <h3 className="font-bold text-ink-900">{article.title}</h3>
              <p className="mt-0.5 text-xs text-ink-400">{article.slug}</p>
              <p className="mt-2 line-clamp-2 text-sm text-ink-500">{article.content}</p>

              <div className="mt-3 flex flex-wrap gap-2">
                {can('articles.update') && (
                  <button
                    onClick={() => {
                      setEditingSlug(article.slug)
                      setForm({ title: article.title, content: article.content })
                      window.scrollTo({ top: 0, behavior: 'smooth' })
                    }}
                    className="rounded-lg border border-ink-200 px-3 py-1.5 text-xs font-semibold text-ink-700 hover:bg-ink-50"
                  >
                    تعديل
                  </button>
                )}

                {can('articles.delete') ? (
                  <button
                    onClick={() => remove(article.slug)}
                    className="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50"
                  >
                    حذف
                  </button>
                ) : (
                  <button
                    onClick={() => probe(article.slug)}
                    title="يستدعي مسار الحذف رغم عدم امتلاك الصلاحية"
                    className="rounded-lg border border-dashed border-ink-200 px-3 py-1.5 text-xs text-ink-400 hover:bg-ink-50"
                  >
                    اختبار الحماية: حاول الحذف
                  </button>
                )}
              </div>
            </article>
          ))}
        </div>
      )}
    </div>
  )
}

/** رسالة مفهومة حسب رمز الحالة، بدل نصّ الخطأ الخام. */
function describe(err) {
  if (err.status === 403) return 'رُفضت العملية: لا تملك الصلاحية المطلوبة (403).'
  if (err.status === 422) return `بيانات غير صالحة: ${err.message}`
  if (err.status === 502) return 'منصّة المعرفة غير متاحة حالياً (502).'
  return err.message
}
