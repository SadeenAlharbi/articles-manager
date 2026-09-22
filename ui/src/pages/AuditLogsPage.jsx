import { Fragment, useEffect, useState } from 'react'
import { api } from '../lib/api'
import { Alert, Badge, EmptyState, SelectControl, Spinner } from '../components/ui'
import { actionLabel } from '../lib/labels'

/*
 * سجلّ العمليات.
 *
 * يعرض ما وقع فعلاً — لا بيانات وهمية لملء الجدول. إن لم توجد عمليات
 * بعد، تظهر حالة فراغ صريحة.
 *
 * لكل صف تفاصيل مطويّة تشرح ما تغيّر بالضبط. التفاصيل مخزَّنة في عمود
 * payload من نوع JSONB، وتُترجم هنا إلى عربية مفهومة: لا تظهر أسماء
 * الصلاحيات التقنية (articles.create) في أي موضع من الواجهة.
 */

const SUBJECTS = { article: 'مقال', user: 'مستخدم' }

/**
 * أسماء حقول النماذج كما يراها المستخدم.
 *
 * أي حقل غير مذكور هنا يظهر بمعرّفه التقني — وهذا ما جعل «tags» تظهر
 * إنجليزية في السجلّ. تُضاف الحقول هنا كلّما اتّسع نموذج المقال.
 */
const FIELDS = {
  name: 'الاسم',
  email: 'البريد الإلكتروني',
  title: 'العنوان',
  content: 'المحتوى',
  tags: 'التصنيفات',
  status: 'حالة النشر',
  image: 'الصورة',
  published_at: 'تاريخ النشر',
}

export default function AuditLogsPage() {
  const [logs, setLogs] = useState([])
  const [actors, setActors] = useState([])
  const [labels, setLabels] = useState({ permissions: {}, roles: {} })
  const [actorId, setActorId] = useState('')
  const [expanded, setExpanded] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  /*
   * الفلترة تقع في الخادم لا في الواجهة: السجلّ مُرقَّم بعشرين صفاً، وتصفية
   * الصفحة المعروضة وحدها تُخفي عمليات الشخص الواقعة في الصفحات الأخرى —
   * أي تعطي إجابة ناقصة تبدو كاملة.
   */
  useEffect(() => {
    setLoading(true)
    setExpanded(null)

    api('/audit-logs', { params: { user_id: actorId } })
      .then((response) => {
        setLogs(response.data ?? [])
        setActors(response.actors ?? [])
        setLabels(response.labels ?? { permissions: {}, roles: {} })
        setError('')
      })
      .catch((err) =>
        setError(err.status === 403 ? 'لا تملك صلاحية عرض سجلّ العمليات (403).' : err.message)
      )
      .finally(() => setLoading(false))
  }, [actorId])

  if (error) return <Alert tone="error">{error}</Alert>

  const filter = (
    <div className="mb-4 flex flex-wrap items-center gap-2">
      <label htmlFor="actor" className="text-sm font-semibold text-ink-700">
        مَن نفّذ العملية
      </label>

      <SelectControl
        id="actor"
        value={actorId}
        onChange={(event) => setActorId(event.target.value)}
      >
        <option value="">الجميع</option>
        {actors.map((actor) => (
          <option key={actor.id} value={actor.id}>
            {actor.name}
          </option>
        ))}
      </SelectControl>

      {actorId && (
        <button
          type="button"
          onClick={() => setActorId('')}
          className="inline-flex h-10 items-center rounded-lg px-2.5 text-xs font-semibold
            text-ink-500 transition-colors hover:bg-ink-50"
        >
          إلغاء التصفية
        </button>
      )}
    </div>
  )

  if (loading) {
    return (
      <div>
        {filter}
        <Spinner />
      </div>
    )
  }

  if (logs.length === 0) {
    return (
      <div>
        {filter}
        <EmptyState
          title={actorId ? 'لا توجد عمليات لهذا المستخدم' : 'لا توجد عمليات مسجّلة بعد'}
          description={
            actorId
              ? 'جرّب اختيار مستخدم آخر أو اعرض الجميع.'
              : 'سيظهر هنا كل إنشاء أو تعديل أو حذف يقوم به مستخدمو النظام.'
          }
        />
      </div>
    )
  }

  return (
    <div>
      {filter}

      <div className="overflow-x-auto rounded-2xl border border-ink-100 bg-white">
        <table className="w-full text-right text-sm">
          <thead className="border-b border-ink-100 bg-ink-50 text-xs text-ink-500">
            <tr>
              <th className="px-4 py-2.5 font-semibold">مَن</th>
              <th className="px-4 py-2.5 font-semibold">العملية</th>
              <th className="px-4 py-2.5 font-semibold">العنصر</th>
              <th className="px-4 py-2.5 font-semibold">النتيجة</th>
              <th className="px-4 py-2.5 font-semibold">الوقت</th>
              <th className="px-4 py-2.5 font-semibold">التفاصيل</th>
            </tr>
          </thead>

          <tbody>
            {logs.map((log) => {
              const rows = describe(log, labels)
              const isOpen = expanded === log.id

              return (
                <Fragment key={log.id}>
                  <tr className="border-b border-ink-50 last:border-0 hover:bg-ink-25">
                    <td className="px-4 py-3">
                      <span className="font-semibold text-ink-800">
                        {log.user?.name ?? 'حساب محذوف'}
                      </span>
                      {log.user?.email && (
                        <span className="block text-[11px] text-ink-400">{log.user.email}</span>
                      )}
                    </td>

                    <td className="px-4 py-3 text-ink-700">{actionLabel(log.action)}</td>

                    <td className="px-4 py-3">
                      <span className="text-ink-600">
                        {SUBJECTS[log.subject_type] ?? log.subject_type}
                      </span>
                      {subjectName(log) && (
                        <span className="block max-w-[16rem] truncate text-[11px] text-ink-400">
                          {subjectName(log)}
                        </span>
                      )}

                      {/* المعرّف المختصر مرجعٌ ثانوي، ولا يُعرض إن كان هو الاسم */}
                      {log.subject_type === 'article'
                        && log.payload?.title
                        && log.subject_id && (
                        <span className="block max-w-[16rem] truncate font-mono text-[10px] text-ink-300">
                          {log.subject_id}
                        </span>
                      )}
                    </td>

                    <td className="px-4 py-3">
                      <Badge tone={log.succeeded ? 'success' : 'danger'}>
                        {log.succeeded ? 'نجحت' : 'فشلت'}
                      </Badge>
                    </td>

                    <td className="px-4 py-3 text-xs text-ink-400">
                      {formatMoment(log.created_at)}
                    </td>

                    <td className="px-4 py-3">
                      {rows.length > 0 ? (
                        <button
                          type="button"
                          onClick={() => setExpanded(isOpen ? null : log.id)}
                          aria-expanded={isOpen}
                          className="rounded-lg border border-ink-200 px-2.5 py-1 text-xs
                            font-semibold text-ink-600 transition-colors hover:bg-ink-50"
                        >
                          {isOpen ? 'إخفاء' : 'عرض'}
                        </button>
                      ) : (
                        <span className="text-xs text-ink-300">—</span>
                      )}
                    </td>
                  </tr>

                  {isOpen && (
                    <tr className="border-b border-ink-50 bg-ink-25">
                      <td colSpan={6} className="px-4 py-3">
                        <dl className="grid gap-x-6 gap-y-1.5 sm:grid-cols-[max-content_1fr]">
                          {rows.map(([term, value]) => (
                            <Fragment key={term}>
                              <dt className="text-xs font-semibold text-ink-500">{term}</dt>
                              <dd className="text-sm text-ink-800">{value}</dd>
                            </Fragment>
                          ))}
                        </dl>
                      </td>
                    </tr>
                  )}
                </Fragment>
              )
            })}
          </tbody>
        </table>
      </div>
    </div>
  )
}

/* -------------------------------------------------------------------------- */

/**
 * اسم العنصر المتأثّر كما كان لحظة العملية.
 *
 * للمستخدمين نعرض الاسم المحفوظ في الحمولة لا رقم المعرّف: الرقم لا يعني
 * شيئاً لقارئ السجلّ، والاسم المحفوظ يبقى صحيحاً حتى لو أُعيدت تسمية
 * الحساب بعد ذلك — وهذا ما يجب أن يفعله سجلّ تدقيق.
 */
function subjectName(log) {
  if (log.subject_type === 'user') return log.payload?.name ?? log.subject_id

  return log.payload?.title ?? log.subject_id
}

/**
 * ترجمة حمولة العملية إلى أسطر «مصطلح ← قيمة».
 *
 * كل صلاحية تُعرض بمسمّاها العربي القادم من الخادم، فلا يظهر معرّف تقني
 * مثل articles.create في الواجهة إطلاقاً.
 *
 * @returns {Array<[string, string]>}
 */
function describe(log, labels) {
  const payload = log.payload ?? {}
  const permission = (name) => labels.permissions?.[name] ?? name
  const role = (name) => labels.roles?.[name] ?? name
  const listOf = (names) => (names?.length ? names.map(permission).join('، ') : 'لا شيء')
  const fieldsOf = (names) => (names?.length ? names.map((f) => FIELDS[f] ?? f).join('، ') : 'لا شيء')

  const rows = []

  switch (log.action) {
    case 'users.permissions': {
      /*
        نذكر ما أُضيف وما أُزيل صراحةً بدل «قبل ← بعد». في واجهة عربية
        يقرأها المستخدم من اليمين، السهم يقلب المعنى في ذهن القارئ: يبدو
        أن «لا شيء» هي النتيجة. والصياغة الصريحة لا تحتمل اللبس أصلاً.
      */
      const addedGrants = missingFrom(payload.granted_to, payload.granted_from)
      const removedGrants = missingFrom(payload.granted_from, payload.granted_to)
      const addedDenials = missingFrom(payload.denied_to, payload.denied_from)
      const removedDenials = missingFrom(payload.denied_from, payload.denied_to)

      if (addedGrants.length) rows.push(['صلاحيات مُنحت', listOf(addedGrants)])
      if (removedGrants.length) rows.push(['منح سُحبت', listOf(removedGrants)])
      if (addedDenials.length) rows.push(['صلاحيات حُجبت', listOf(addedDenials)])
      if (removedDenials.length) rows.push(['حجب رُفع', listOf(removedDenials)])

      if (rows.length === 0) rows.push(['النتيجة', 'حُفظت بلا تغيير فعلي'])
      break
    }

    case 'users.role':
      rows.push([
        'الدور',
        payload.from ? 'من ' + role(payload.from) + ' إلى ' + role(payload.to) : role(payload.to),
      ])
      break

    case 'users.create':
      rows.push(['البريد الإلكتروني', payload.email])
      rows.push(['الدور', role(payload.role)])
      if (payload.direct_permissions?.length) {
        rows.push(['منح فردي عند الإنشاء', listOf(payload.direct_permissions)])
      }
      break

    case 'users.update': {
      const changed = (payload.fields ?? []).map((f) => FIELDS[f] ?? f)
      // كلمة المرور يُذكر أنها تغيّرت ولا تُخزَّن قيمتها أبداً
      if (payload.password_changed) changed.push('كلمة المرور')
      rows.push(['الحقول المعدَّلة', changed.length ? changed.join('، ') : 'لا شيء'])
      break
    }

    case 'articles.update':
      // القائمة تحمل ما اختلفت قيمته فعلاً — يحسبها الخادم بمقارنة قبل/بعد
      rows.push([
        'الحقول المعدَّلة',
        payload.fields?.length ? fieldsOf(payload.fields) : 'حُفظ بلا تغيير فعلي',
      ])
      break

    default:
      break
  }

  /*
   * العمليات على المقالات تمرّ عبر منصّة المعرفة، فرمز استجابتها هو سبب
   * الفشل الحقيقي. لا يُعرض عند النجاح — 201 لا تفيد قارئ السجلّ.
   */
  if (!log.succeeded && payload.status) {
    rows.push(['سبب الفشل', 'رفضت منصّة المعرفة الطلب برمز ' + payload.status])
  }

  return rows
}

/** عناصر القائمة الأولى غير الموجودة في الثانية. */
function missingFrom(list = [], other = []) {
  return (list ?? []).filter((item) => !(other ?? []).includes(item))
}

/**
 * التاريخ والوقت بصيغة منصّة المعرفة نفسها.
 *
 * toLocaleString('ar-SA') يُخرج تاريخاً هجرياً بأرقام عربية-هندية، بينما
 * المنصّة الأولى تعرض ميلادياً بأرقام لاتينية (Y/m/d). اختلاف الصيغتين بين
 * نظامين يعرضهما المستخدم جنباً إلى جنب يبدو خللاً لا خياراً.
 */
function formatMoment(value) {
  const at = new Date(value)
  if (Number.isNaN(at.getTime())) return ''

  const pad = (n) => String(n).padStart(2, '0')
  const date = at.getFullYear() + '/' + pad(at.getMonth() + 1) + '/' + pad(at.getDate())

  return date + ' — ' + pad(at.getHours()) + ':' + pad(at.getMinutes())
}
