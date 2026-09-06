import { useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import Logo from './Logo'
import { IconLogout, IconMenu } from './icons'

/*
 * هيكل لوحة الإدارة: شريط جانبي ثابت على اليمين ومحتوى على اليسار.
 *
 * الشريط على اليمين لأن الواجهة عربية (RTL) — وهو موضع البداية البصرية
 * الذي تقع عليه العين أولاً، تماماً كما يقع الشريط يساراً في الواجهات
 * الإنجليزية. اللون الأخضر العميق مأخوذ من هوية منصّة المعرفة.
 *
 * على الشاشات الصغيرة يتحوّل إلى درج ينزلق فوق المحتوى، ويُغلق بمفتاح
 * Escape وبالضغط خارجه — فلا يبتلع الشريط ثلث شاشة الجوال.
 */
export default function Layout({ tabs, activeTab, onTabChange, title, subtitle, children }) {
  const { user, logout } = useAuth()
  const [drawerOpen, setDrawerOpen] = useState(false)

  // إغلاق الدرج عند تبديل القسم أو ضغط Escape
  useEffect(() => {
    setDrawerOpen(false)
  }, [activeTab])

  useEffect(() => {
    if (!drawerOpen) return undefined

    const onKeyDown = (event) => event.key === 'Escape' && setDrawerOpen(false)
    document.addEventListener('keydown', onKeyDown)

    return () => document.removeEventListener('keydown', onKeyDown)
  }, [drawerOpen])

  const sidebar = (
    <div className="flex h-full flex-col bg-flag-900 text-white">
      {/* العلامة */}
      <div className="flex items-center gap-3 border-b border-white/10 px-5 py-5">
        <Logo size={38} />
        <div className="min-w-0">
          <p className="truncate text-sm font-bold">لوحة إدارة المقالات</p>
          <p className="truncate text-[11px] text-white/50">منصّة المعرفة السعودية</p>
        </div>
      </div>

      {/* التبويبات */}
      <nav className="flex-1 space-y-1 px-3 py-4" aria-label="أقسام اللوحة">
        {tabs.map((tab) => {
          const isActive = tab.key === activeTab
          const Icon = tab.icon

          return (
            <button
              key={tab.key}
              onClick={() => onTabChange(tab.key)}
              aria-current={isActive ? 'page' : undefined}
              className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold
                transition-colors ${
                  isActive
                    ? 'bg-brand-500 text-white'
                    : 'text-white/70 hover:bg-white/10 hover:text-white'
                }`}
            >
              <Icon />
              <span>{tab.label}</span>
            </button>
          )
        })}
      </nav>

      {/* بطاقة المستخدم */}
      <div className="border-t border-white/10 p-3">
        <div className="rounded-xl bg-white/5 px-3 py-2.5">
          <p className="truncate text-sm font-semibold">{user.name}</p>
          <p className="truncate text-[11px] text-white/50">{user.role_label ?? user.roles.join('، ')}</p>
        </div>

        <button
          onClick={logout}
          className="mt-2 flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-sm
            font-semibold text-white/70 transition-colors hover:bg-white/10 hover:text-white"
        >
          <IconLogout />
          تسجيل الخروج
        </button>
      </div>
    </div>
  )

  return (
    <div className="min-h-screen">
      {/* الشريط الثابت — الشاشات المتوسطة فأكبر */}
      <aside className="fixed inset-y-0 right-0 hidden w-64 lg:block">{sidebar}</aside>

      {/* الدرج — الشاشات الصغيرة */}
      {drawerOpen && (
        <>
          <div
            className="fixed inset-0 z-40 bg-ink-900/40 lg:hidden"
            onClick={() => setDrawerOpen(false)}
            aria-hidden="true"
          />
          <aside className="fixed inset-y-0 right-0 z-50 w-64 lg:hidden">{sidebar}</aside>
        </>
      )}

      <div className="lg:mr-64">
        {/* الشريط العلوي */}
        <header className="sticky top-0 z-30 border-b border-ink-200 bg-white/85 backdrop-blur">
          <div className="flex items-center gap-3 px-5 py-4 sm:px-8">
            <button
              onClick={() => setDrawerOpen(true)}
              aria-label="فتح القائمة"
              className="rounded-lg p-1.5 text-ink-500 transition-colors hover:bg-ink-50 lg:hidden"
            >
              <IconMenu />
            </button>

            <div className="min-w-0 flex-1">
              <h1 className="truncate text-lg font-bold text-ink-900">{title}</h1>
              {subtitle && <p className="truncate text-xs text-ink-400">{subtitle}</p>}
            </div>
          </div>
        </header>

        <main className="px-5 py-7 sm:px-8">
          <div className="mx-auto max-w-5xl">{children}</div>
        </main>
      </div>
    </div>
  )
}
