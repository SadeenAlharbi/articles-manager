import { useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import Logo from './Logo'
import { IconEye, IconLogout, IconMenu } from './icons'

/*
 * The shell of the admin panel — identical to the shell of the knowledge
 * platform's own admin panel.
 *
 * The central decision here is that the sidebar is **light** rather than dark,
 * and that the brand mark sits in the top bar rather than inside the sidebar.
 * That is what the main platform does, and the reason is substantive rather
 * than cosmetic: the Saudi green is an action and identity colour; covering a
 * whole column with it turns it into a background and it loses its ability to
 * point at anything — the active tab stops standing out. So the green stays
 * confined to a thin top bar, to the pill of the active tab, and to buttons.
 *
 * The sidebar sits in the layout flow rather than being fixed, exactly as on
 * the main platform. On small screens it becomes a drawer that slides in from
 * the right — the start side in RTL — and closes on Escape and on a click
 * outside it.
 */
export default function Layout({ tabs, activeTab, onTabChange, title, subtitle, children }) {
  const { user, platformUrl, logout } = useAuth()
  const [drawerOpen, setDrawerOpen] = useState(false)

  useEffect(() => {
    setDrawerOpen(false)
  }, [activeTab])

  useEffect(() => {
    if (!drawerOpen) return undefined

    const onKeyDown = (event) => event.key === 'Escape' && setDrawerOpen(false)
    document.addEventListener('keydown', onKeyDown)

    return () => document.removeEventListener('keydown', onKeyDown)
  }, [drawerOpen])

  const roleLabel = user.role_label ?? user.roles.join('، ')

  /* ------------------------------ Navigation ------------------------------ */

  const nav = (
    <div className="space-y-6 p-4 lg:py-6 lg:pe-6 lg:ps-0">
      {groupTabs(tabs).map((group) => (
        <div key={group.label ?? '—'}>
          {group.label && (
            <p className="mb-1.5 px-3 text-[11px] font-bold tracking-wide text-ink-400">
              {group.label}
            </p>
          )}

          <ul className="space-y-0.5">
            {group.items.map((tab) => {
              const isActive = tab.key === activeTab
              const Icon = tab.icon

              return (
                <li key={tab.key}>
                  <button
                    onClick={() => onTabChange(tab.key)}
                    aria-current={isActive ? 'page' : undefined}
                    className={`flex w-full items-center gap-2.5 rounded-xl px-3 py-2.5 text-sm
                      font-medium transition-colors ${
                        isActive
                          ? 'bg-brand-50 text-brand-700 ring-1 ring-brand-100'
                          : 'text-ink-600 hover:bg-ink-50 hover:text-ink-900'
                      }`}
                  >
                    <span className={isActive ? 'text-brand-600' : 'text-ink-400'}>
                      <Icon width={18} height={18} />
                    </span>
                    <span className="truncate">{tab.label}</span>
                  </button>
                </li>
              )
            })}
          </ul>
        </div>
      ))}
    </div>
  )

  return (
    <div className="min-h-screen bg-ink-25 text-ink-800">
      {/* ------------------------------ Top bar ------------------------------- */}

      <header className="sticky top-0 z-30 border-b border-ink-100 bg-white/95 backdrop-blur">
        {/* The thin green bar — the same visual signature as on the main platform */}
        <div className="h-1 w-full bg-gradient-to-l from-[#074D31] via-brand-500 to-brand-600" />

        <div className="mx-auto flex h-16 max-w-[1600px] items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
          <div className="flex shrink-0 items-center gap-2.5">
            <Logo size={38} />
            <span className="hidden leading-tight sm:block">
              <span className="block text-sm font-bold text-ink-900">لوحة إدارة المقالات</span>
              <span className="block text-[11px] font-semibold text-brand-600">
                منصّة المعرفة السعودية
              </span>
            </span>
          </div>

          <div className="flex items-center gap-2 sm:gap-3">
            {platformUrl && (
              <a
                href={platformUrl}
                target="_blank"
                rel="noopener noreferrer"
                title="عرض المنصّة"
                className="hidden items-center gap-1.5 rounded-lg border border-ink-200 px-3 py-1.5
                  text-xs font-medium text-ink-600 transition-colors hover:border-brand-300
                  hover:text-brand-700 sm:inline-flex"
              >
                <IconEye width={16} height={16} />
                عرض المنصّة
              </a>
            )}

            <div className="flex items-center gap-2.5 border-s border-ink-100 ps-2 sm:ps-3">
              <Avatar name={user.name} />
              <span className="hidden leading-tight md:block">
                <span className="block text-sm font-semibold text-ink-800">{user.name}</span>
                <span className="block text-[11px] text-brand-600">{roleLabel}</span>
              </span>
            </div>

            <button
              onClick={logout}
              title="تسجيل الخروج"
              aria-label="تسجيل الخروج"
              className="inline-flex h-10 w-10 items-center justify-center rounded-lg text-ink-500
                transition-colors hover:bg-red-50 hover:text-red-600"
            >
              <IconLogout />
            </button>

            <button
              onClick={() => setDrawerOpen(true)}
              aria-label="القائمة"
              aria-expanded={drawerOpen}
              className="inline-flex h-10 w-10 items-center justify-center rounded-lg text-ink-600
                transition-colors hover:bg-ink-50 lg:hidden"
            >
              <IconMenu />
            </button>
          </div>
        </div>
      </header>

      {/* ------------------------- Sidebar and content -------------------------- */}

      <div className="mx-auto flex max-w-[1600px]">
        <aside className="hidden w-64 shrink-0 lg:block lg:border-e lg:border-ink-100">{nav}</aside>

        {drawerOpen && (
          <>
            <div
              className="fixed inset-0 z-40 bg-ink-900/40 lg:hidden"
              onClick={() => setDrawerOpen(false)}
              aria-hidden="true"
            />
            <aside className="fixed inset-y-0 right-0 z-50 w-64 overflow-y-auto border-s border-ink-100 bg-white lg:hidden">
              {nav}
            </aside>
          </>
        )}

        <main className="min-w-0 flex-1 px-4 py-6 sm:px-6 lg:px-8">
          {/* The title is optional: a page that carries its own heading — like the
              welcome card on the dashboard — is not preceded by a line repeating
              what sits directly beneath it. */}
          {title && (
            <div className="mb-6">
              <h1 className="text-xl font-bold text-ink-900 sm:text-2xl">{title}</h1>
              {subtitle && <p className="mt-1 text-sm text-ink-500">{subtitle}</p>}
            </div>
          )}

          {children}
        </main>
      </div>

      <footer className="border-t border-ink-100 bg-white">
        <div className="mx-auto max-w-[1600px] px-4 py-4 text-center sm:px-6 lg:px-8">
          <p className="text-xs text-ink-400">
            © {new Date().getFullYear()} منصّة المعرفة السعودية — لوحة إدارة المقالات
          </p>
        </div>
      </footer>
    </div>
  )
}

/* -------------------------------------------------------------------------- */

/**
 * Group the tabs under section headings, in order of first appearance.
 *
 * Which tabs are visible changes with the account's permissions, so an entire
 * section may disappear — which is why the groups are built out of what is
 * actually being shown rather than out of a fixed list, so that no section
 * heading is ever left hanging with not one item beneath it.
 */
function groupTabs(tabs) {
  const groups = []

  for (const tab of tabs) {
    const label = tab.group ?? null
    const existing = groups.find((group) => group.label === label)

    if (existing) existing.items.push(tab)
    else groups.push({ label, items: [tab] })
  }

  return groups
}

/** The first letter in a green circle — the same treatment as on the main platform. */
function Avatar({ name }) {
  const initial = (name ?? '').trim().charAt(0) || 'م'

  return (
    <span
      aria-hidden="true"
      className="inline-flex h-[34px] w-[34px] shrink-0 select-none items-center justify-center
        rounded-full bg-brand-100 text-sm font-bold text-brand-700"
    >
      {initial}
    </span>
  )
}
