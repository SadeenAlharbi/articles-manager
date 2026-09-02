import { useState } from 'react'
import { useAuth } from '../auth/AuthContext'

/** حسابات العرض — تسهّل التنقّل بين الأدوار أثناء المناقشة. */
const DEMO = [
  ['admin@demo.test', 'مشرفة — كل الصلاحيات'],
  ['moderator@demo.test', 'مراقِبة — تحذف وتنشر'],
  ['editor@demo.test', 'محرِّرة — تعدّل ولا تحذف'],
  ['author@demo.test', 'كاتبة — تضيف فقط'],
  ['author.plus@demo.test', 'كاتبة + منح مباشر للحذف'],
  ['viewer@demo.test', 'قارئة — عرض فقط'],
]

export default function LoginPage() {
  const { login } = useAuth()
  const [email, setEmail] = useState('admin@demo.test')
  const [password, setPassword] = useState('password')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  async function handleSubmit(event) {
    event.preventDefault()
    setError('')
    setBusy(true)

    try {
      await login(email, password)
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="min-h-screen flex items-center justify-center p-6">
      <div className="w-full max-w-sm">
        <div className="mb-6 text-center">
          <h1 className="text-2xl font-bold text-ink-900">لوحة إدارة المقالات</h1>
          <p className="mt-1 text-sm text-ink-500">تدير محتوى منصّة المعرفة عبر الـAPI</p>
        </div>

        <form onSubmit={handleSubmit} className="rounded-2xl border border-ink-200 bg-white p-6">
          <label className="block text-sm font-semibold text-ink-700">البريد الإلكتروني</label>
          <input
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
            className="mt-1.5 w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500"
          />

          <label className="mt-4 block text-sm font-semibold text-ink-700">كلمة المرور</label>
          <input
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
            className="mt-1.5 w-full rounded-xl border border-ink-200 px-3 py-2 text-sm outline-none focus:border-brand-500"
          />

          {error && (
            <p className="mt-4 rounded-xl bg-red-50 px-3 py-2 text-sm text-red-700">{error}</p>
          )}

          <button
            type="submit"
            disabled={busy}
            className="mt-5 w-full rounded-xl bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white transition-colors hover:bg-brand-700 disabled:opacity-60"
          >
            {busy ? 'جارِ الدخول...' : 'تسجيل الدخول'}
          </button>
        </form>

        <div className="mt-5 rounded-2xl border border-ink-200 bg-white p-4">
          <p className="mb-2 text-xs font-semibold text-ink-500">حسابات العرض (كلمة المرور: password)</p>
          <div className="flex flex-col gap-1">
            {DEMO.map(([mail, label]) => (
              <button
                key={mail}
                type="button"
                onClick={() => setEmail(mail)}
                className="rounded-lg px-2 py-1.5 text-right text-xs text-ink-700 transition-colors hover:bg-ink-50"
              >
                <span className="font-semibold">{label}</span>
                <span className="block text-ink-400">{mail}</span>
              </button>
            ))}
          </div>
        </div>
      </div>
    </div>
  )
}
