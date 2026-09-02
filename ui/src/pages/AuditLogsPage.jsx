import { useEffect, useState } from 'react'
import { api } from '../lib/api'

/** سجلّ التدقيق — متاح للمشرف وحده (role:admin على الخادم). */
export default function AuditLogsPage() {
  const [logs, setLogs] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  useEffect(() => {
    api('/audit-logs')
      .then((response) => setLogs(response.data ?? []))
      .catch((err) => setError(err.status === 403 ? 'هذي الشاشة للمشرف وحده (403).' : err.message))
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <p className="text-sm text-ink-400">جارِ التحميل...</p>
  if (error) return <p className="rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>

  return (
    <div>
      <h2 className="mb-1 text-xl font-bold text-ink-900">سجلّ التدقيق</h2>
      <p className="mb-5 text-sm text-ink-500">
        كل عملية تغيير — ناجحة كانت أو مرفوضة — بصاحبها ووقتها.
      </p>

      <div className="overflow-x-auto rounded-2xl border border-ink-200 bg-white">
        <table className="w-full text-right text-sm">
          <thead className="border-b border-ink-100 bg-ink-50 text-xs text-ink-500">
            <tr>
              <th className="px-4 py-2 font-semibold">مَن</th>
              <th className="px-4 py-2 font-semibold">العملية</th>
              <th className="px-4 py-2 font-semibold">المقال</th>
              <th className="px-4 py-2 font-semibold">النتيجة</th>
              <th className="px-4 py-2 font-semibold">الوقت</th>
            </tr>
          </thead>
          <tbody>
            {logs.map((log) => (
              <tr key={log.id} className="border-b border-ink-50 last:border-0">
                <td className="px-4 py-2.5">{log.user?.name ?? '—'}</td>
                <td className="px-4 py-2.5 font-mono text-xs">{log.action}</td>
                <td className="px-4 py-2.5 text-xs text-ink-500">{log.subject_id ?? '—'}</td>
                <td className="px-4 py-2.5">
                  <span
                    className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
                      log.succeeded ? 'bg-brand-50 text-brand-700' : 'bg-red-50 text-red-700'
                    }`}
                  >
                    {log.succeeded ? 'نجحت' : 'فشلت'}
                  </span>
                </td>
                <td className="px-4 py-2.5 text-xs text-ink-400">
                  {new Date(log.created_at).toLocaleString('ar-SA')}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {logs.length === 0 && (
        <p className="mt-3 text-sm text-ink-400">لا توجد عمليات مسجّلة بعد.</p>
      )}
    </div>
  )
}
