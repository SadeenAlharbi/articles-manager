import { useEffect, useState } from 'react'
import { api } from '../lib/api'
import { useAuth } from '../auth/AuthContext'
import { Alert, EmptyState, Spinner } from '../components/ui'
import { IconArticles, IconCheck, IconClock, IconExternal, IconPencil } from '../components/icons'
import { actionLabel, statusTone } from '../lib/labels'

/*
 * The dashboard.
 *
 * Every number here is a real query — from the knowledge platform through its
 * API, and from the audit log in our own database. There is not one fixed
 * figure and no mock data anywhere: when a source is missing, an explicit
 * empty state appears instead of a zero that would suggest there is nothing
 * there.
 *
 * No "over time" charts — an explicit clause in the specification.
 *
 * The form is taken from the main platform's own admin dashboard: a welcome
 * card with a faint green gradient, then a row of indicators whose icons sit
 * in a pale green square, then white panels with a hairline border.
 */

export default function DashboardPage({ onNavigate }) {
  const { user, platformUrl, can } = useAuth()

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

  const { counts, latest_articles: latest, most_viewed: viewed } = data
  const categories = data.top_categories ?? []
  const operations = data.latest_operations ?? []

  return (
    <div className="flex flex-col gap-6">
      {/* ------------------------------ Welcome ------------------------------ */}

      <section
        className="relative overflow-hidden rounded-2xl border border-ink-100"
        style={{
          background:
            'radial-gradient(circle at 88% 20%,rgba(11,127,91,0.08),transparent 42%),' +
            'linear-gradient(135deg,#f6faf8 0%,#f2f7f4 100%)',
        }}
      >
        <div className="flex flex-col justify-between gap-4 p-5 sm:flex-row sm:items-center sm:p-6">
          <div className="min-w-0">
            <p className="mb-1 text-xs font-semibold text-brand-700">لوحة إدارة المقالات</p>
            <h2 className="truncate text-xl font-bold text-ink-900 sm:text-2xl">
              حياك الله، {firstName(user.name)}
            </h2>
            <p className="mt-1 text-sm text-ink-500">
              هذه نظرة عامة على محتوى المنصّة وآخر ما جرى عليه.
            </p>
          </div>

          <div className="flex shrink-0 items-center gap-2">
            {can('articles.view') && (
              <button
                type="button"
                onClick={() => onNavigate?.('articles')}
                className="inline-flex items-center rounded-xl bg-brand-600 px-4 py-2.5 text-sm
                  font-semibold text-white transition-colors hover:bg-brand-700"
              >
                إدارة المقالات
              </button>
            )}

            {can('audit.view') && (
              <button
                type="button"
                onClick={() => onNavigate?.('audit')}
                className="inline-flex items-center rounded-xl border border-ink-200 bg-white px-4
                  py-2.5 text-sm font-semibold text-ink-700 transition-colors hover:border-brand-300
                  hover:text-brand-700"
              >
                سجلّ العمليات
              </button>
            )}
          </div>
        </div>
      </section>

      {/* ------------------------------ Numbers ------------------------------ */}

      <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <Stat label="إجمالي المقالات" value={counts.all} icon={IconArticles} highlight />
        <Stat label="منشورة" value={counts.published} icon={IconCheck} />
        <Stat label="مسودات" value={counts.draft} icon={IconPencil} />
        <Stat label="مجدولة" value={counts.scheduled} icon={IconClock} />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        {/* ------------------------- Latest articles -------------------------- */}

        <Panel title="آخر المقالات" hint="أحدث ما أُضيف إلى المنصّة">
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

        {/* ---------------------------- Most read ----------------------------- */}

        <Panel title="الأكثر قراءة" hint="بحسب عدد المشاهدات المسجّلة في المنصّة">
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

        {/* --------------------- Top publishing categories --------------------- */}

        <Panel title="أكثر التصنيفات نشراً" hint="التصنيفات التي نُشر فيها مقال على الأقل">
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

        {/* ------------------------ Latest operations ------------------------- */}

        <Panel title="آخر العمليات" hint="من سجلّ التدقيق في قاعدة اللوحة">
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
 * An indicator card — the very structure of the indicator cards on the main
 * platform's admin dashboard: an icon in a pale green square, then the number
 * in prominence, then its description.
 *
 * And null here means "the number could not be fetched", not zero: a zero says
 * "no articles", an absence says "we do not know". Rendering the two the same
 * way lies to the reader.
 */
function Stat({ label, value, icon: Icon, highlight = false }) {
  return (
    <div
      className={`rounded-2xl border bg-white p-4 transition-colors sm:p-5 ${
        highlight ? 'border-brand-200' : 'border-ink-100'
      }`}
    >
      <span
        className="mb-3 inline-flex h-9 w-9 items-center justify-center rounded-xl bg-brand-50
          text-brand-600 ring-1 ring-brand-100"
      >
        <Icon width={18} height={18} />
      </span>

      <p
        className={`text-2xl font-extrabold tabular-nums sm:text-3xl ${
          value === null ? 'text-ink-300' : 'text-ink-900'
        }`}
      >
        {value === null ? '—' : value}
      </p>

      <p className="mt-1 text-xs text-ink-500 sm:text-sm">{label}</p>
    </div>
  )
}

function Panel({ title, hint, children }) {
  return (
    <section className="rounded-2xl border border-ink-100 bg-white p-5">
      <div className="mb-3">
        <h3 className="font-bold text-ink-900">{title}</h3>
        {hint && <p className="mt-0.5 text-xs text-ink-400">{hint}</p>}
      </div>

      {children}
    </section>
  )
}

/** The first name on its own — greeting someone by their given name is warmer than the full four-part name. */
function firstName(name) {
  return (name ?? '').trim().split(/\s+/)[0] || 'بك'
}

/** Dates in the knowledge platform's format: Gregorian, in Latin numerals. */
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
