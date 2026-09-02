import { useEffect, useState } from 'react'
import { useAuth } from './auth/AuthContext'
import LoginPage from './pages/LoginPage'
import ArticlesPage from './pages/ArticlesPage'
import AuditLogsPage from './pages/AuditLogsPage'

export default function App() {
  const { user, loading, logout, hasRole } = useAuth()
  const [tab, setTab] = useState('articles')

  // عند تبديل الحساب نعود للمقالات: تبويب المشرف قد يبقى مفتوحاً
  // لمستخدم لا يملكه، فيصطدم بـ403 بلا داعٍ.
  useEffect(() => { setTab('articles') }, [user?.id])

  if (loading) {
    return <div className="grid min-h-screen place-items-center text-sm text-ink-400">جارِ التحميل...</div>
  }

  if (!user) {
    return <LoginPage />
  }

  return (
    <div className="min-h-screen">
      <header className="border-b border-ink-200 bg-white">
        <div className="mx-auto flex max-w-4xl flex-wrap items-center justify-between gap-3 px-6 py-4">
          <div>
            <h1 className="font-bold text-ink-900">لوحة إدارة المقالات</h1>
            <p className="text-xs text-ink-400">
              {user.name} — {user.roles.join('، ')}
            </p>
          </div>

          <div className="flex items-center gap-2">
            <button
              onClick={() => setTab('articles')}
              className={`rounded-lg px-3 py-1.5 text-sm font-semibold ${
                tab === 'articles' ? 'bg-brand-600 text-white' : 'text-ink-700 hover:bg-ink-50'
              }`}
            >
              المقالات
            </button>

            {/* الشاشة تظهر للمشرف فقط — والخادم يفرض ذلك أيضاً بـrole:admin */}
            {hasRole('admin') && (
              <button
                onClick={() => setTab('audit')}
                className={`rounded-lg px-3 py-1.5 text-sm font-semibold ${
                  tab === 'audit' ? 'bg-brand-600 text-white' : 'text-ink-700 hover:bg-ink-50'
                }`}
              >
                سجلّ التدقيق
              </button>
            )}

            <button
              onClick={logout}
              className="rounded-lg border border-ink-200 px-3 py-1.5 text-sm font-semibold text-ink-700 hover:bg-ink-50"
            >
              خروج
            </button>
          </div>
        </div>

        {/* شريط الصلاحيات — مفيد جداً أثناء العرض في المناقشة */}
        <div className="mx-auto max-w-4xl px-6 pb-3">
          <div className="flex flex-wrap gap-1.5">
            {user.permissions.map((permission) => (
              <span
                key={permission}
                className="rounded-full bg-ink-50 px-2 py-0.5 font-mono text-[11px] text-ink-500"
              >
                {permission}
              </span>
            ))}
          </div>
        </div>
      </header>

      <main className="mx-auto max-w-4xl px-6 py-8">
        {tab === 'articles' ? <ArticlesPage /> : <AuditLogsPage />}
      </main>
    </div>
  )
}
