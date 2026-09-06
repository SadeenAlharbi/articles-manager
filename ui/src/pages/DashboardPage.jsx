import { useEffect, useState } from 'react'
import { api } from '../lib/api'
import { useAuth } from '../auth/AuthContext'
import { Alert, EmptyState, Spinner } from '../components/ui'
import { IconArticles, IconExternal } from '../components/icons'
import { actionLabel, statusTone } from '../lib/labels'

/*
 * لوحة المعلومات.
 *
 * كل رقم هنا استعلام حقيقي — من منصّة المعرفة عبر واجهتها، ومن سجلّ العمليات
 * في قاعدتنا. ولا يوجد رقم ثابت ولا بيانات وهمية: إن غاب المصدر تظهر حالة
 * فراغ صريحة بدل صفرٍ يوهم بأن لا شيء هناك.
 *
 * لا رسوم «عبر الزمن» — بند صريح في المواصفة.
 */

export default function DashboardPage() {
  const { platformUrl } = useAuth()

  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    api('/dashboard')
      .then(setData)
      .catch((err) =>
        setError(
          err.status === 403
            ? 'لا تملك صلاحية عرض الإحصائيات (403).'
            : err.status === 502
              ? 'منصّة المعرفة غير متاحة حالياً، فتعذّر جلب الأرقام.'
              : err.message
        )
      )
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <Spinner label="جارِ جمع الأرقام من المنصّة..." />
  if (error) return <Alert tone="error">{error}</Alert>
  if (!data) return null

  const { counts } = data
  const latest = data.latest_articles ?? []
  const viewed = data.most_viewed ?? []
  const categories = data.top_categories ?? []
  const operations = data.latest_operations ?? []

  return (
    <div className="flex flex-col gap-6">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Stat label="إجمالي المقالات" value={counts.all} tone="brand" />
        <Stat label="منشورة" value={counts.published} />
        <Stat label="مسودات" value={counts.draft} />
        <Stat label="مجدولة" value={counts.scheduled} />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Panel title="آخر المقالات">
          {latest.length === 0 ? (
            <EmptyState title="لا مقالات بعد" description="أنشئ أول مقال من صفحة المقالات." />
          ) : (
            <ul className="flex flex-col divide-y divide-ink-50">
              {latest.map((article) => (
                <li key={article.slug} className="flex items-start justify-between gap-3 py-2.5">
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-semibold text-ink-800">
                      {article.title}
                    </span>
                    <span className="text-[11px] text-ink-400">
                      {formatDate(article.published_at)}
                    </span>
                  </span>

                  <span
                    className={`shrink-0 rounded-full px-2 py-0.5 text-[10px] font-semibold
                      ${statusTone(article.status)}`}
                  >
                    {article.status_label}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Panel>

        <Panel title="الأكثر قراءة">
          {viewed.length === 0 ? (
            <EmptyState title="لا مشاهدات بعد" description="ستظهر هنا المقالات الأكثر قراءة." />
          ) : (
            <ul className="flex flex-col divide-y divide-ink-50">
              {viewed.map((article) => (
                <li key={article.slug} className="flex items-center justify-between gap-3 py-2.5">
                  <a
                    href={platformUrl ? `${platformUrl}/posts/${article.slug}` : '#'}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex min-w-0 items-center gap-1.5 text-sm font-semibold
                      text-ink-800 transition-colors hover:text-brand-600"
                  >
                    <span className="truncate">{article.title}</span>
                    <span className="shrink-0 text-ink-300">
                      <IconExternal />
                    </span>
                  </a>

                  <span className="shrink-0 text-xs tabular-nums text-ink-500">
                    {article.views_count ?? 0} مشاهدة
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Panel>

        <Panel title="أكثر التصنيفات نشراً">
          {categories.length === 0 ? (
            <EmptyState
              title="لا تصنيفات بمقالات"
              description="ستظهر هنا التصنيفات التي نُشر فيها مقال على الأقل."
            />
          ) : (
            <ul className="flex flex-col divide-y divide-ink-50">
              {categories.map((category) => (
                <li key={category.slug} className="flex items-center justify-between gap-3 py-2.5">
                  <span className="truncate text-sm text-ink-800">{category.name}</span>
                  <span className="shrink-0 text-xs tabular-nums text-ink-500">
                    {category.posts_count} مقال
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Panel>

        <Panel title="آخر العمليات">
          {operations.length === 0 ? (
            <EmptyState
              title="لا عمليات مسجّلة بعد"
              description="سيظهر هنا كل إنشاء أو تعديل أو حذف."
            />
          ) : (
            <ul className="flex flex-col divide-y divide-ink-50">
              {operations.map((log) => (
                <li key={log.id} className="flex items-start justify-between gap-3 py-2.5">
                  <span className="min-w-0">
                    <span className="block truncate text-sm text-ink-800">
                      {actionLabel(log.action)}
                      {log.payload?.title && (
                        <span className="text-ink-500"> — {log.payload.title}</span>
                      )}
                      {log.payload?.name && (
                        <span className="text-ink-500"> — {log.payload.name}</span>
                      )}
                    </span>
                    <span className="text-[11px] text-ink-400">
                      {log.user?.name ?? 'حساب محذوف'}
                    </span>
                  </span>

                  <span className="shrink-0 text-[11px] text-ink-400">
                    {formatMoment(log.created_at)}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>
    </div>
  )
}

/* -------------------------------------------------------------------------- */

/**
 * بطاقة رقم.
 *
 * null تعني «تعذّر جلب الرقم» لا صفراً: الصفر يقول «لا مقالات»، والغياب يقول
 * «لا نعرف». عرضهما متطابقَين يكذب على القارئ.
 */
function Stat({ label, value, tone }) {
  return (
    <div
      className={`rounded-2xl border p-4 ${
        tone === 'brand' ? 'border-brand-100 bg-brand-50' : 'border-ink-200 bg-white shadow-card'
      }`}
    >
      <p className="flex items-center gap-1.5 text-xs font-semibold text-ink-500">
        <IconArticles width={14} height={14} />
        {label}
      </p>

      <p
        className={`mt-1.5 text-2xl font-bold tabular-nums ${
          value === null ? 'text-ink-300' : tone === 'brand' ? 'text-brand-700' : 'text-ink-900'
        }`}
      >
        {value === null ? '—' : value}
      </p>
    </div>
  )
}

function Panel({ title, children }) {
  return (
    <section className="rounded-2xl border border-ink-200 bg-white p-4 shadow-card">
      <h3 className="mb-1 text-sm font-bold text-ink-900">{title}</h3>
      {children}
    </section>
  )
}

/** التاريخ بصيغة منصّة المعرفة: ميلادي بأرقام لاتينية. */
function formatDate(value) {
  if (!value) return 'غير منشور'

  const at = new Date(value)
  if (Number.isNaN(at.getTime())) return '—'

  const pad = (n) => String(n).padStart(2, '0')

  return `${at.getFullYear()}/${pad(at.getMonth() + 1)}/${pad(at.getDate())}`
}

function formatMoment(value) {
  const at = new Date(value)
  if (Number.isNaN(at.getTime())) return ''

  const pad = (n) => String(n).padStart(2, '0')

  return `${formatDate(value)} — ${pad(at.getHours())}:${pad(at.getMinutes())}`
}
