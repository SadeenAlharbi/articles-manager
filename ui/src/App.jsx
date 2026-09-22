import { useEffect, useState } from 'react'
import { useAuth } from './auth/AuthContext'
import Layout from './components/Layout'
import { IconArticles, IconAudit, IconChart, IconUsers } from './components/icons'
import LoginPage from './pages/LoginPage'
import DashboardPage from './pages/DashboardPage'
import ArticlesPage from './pages/ArticlesPage'
import UsersPage from './pages/UsersPage'
import AuditLogsPage from './pages/AuditLogsPage'

/*
 * Tabs are shown by permission, not by role — someone granted audit.view
 * directly sees the audit tab without being an administrator at all. This
 * mirrors exactly what the server guards each route with.
 *
 * `group` is the section heading in the sidebar, in the style of the main
 * platform. A tab with no group stands alone at the top with no heading —
 * which is where "Home" sits over there.
 */
const TABS = [
  {
    key: 'dashboard',
    label: 'لوحة المعلومات',
    icon: IconChart,
    permission: 'analytics.view',
    // No title: the welcome card inside the page is its heading
    Page: DashboardPage,
  },
  {
    key: 'articles',
    label: 'المقالات',
    group: 'المحتوى',
    icon: IconArticles,
    permission: 'articles.view',
    title: 'المقالات',
    subtitle: 'تُدار في منصّة المعرفة عبر واجهتها البرمجية',
    Page: ArticlesPage,
  },
  {
    key: 'users',
    label: 'المستخدمون',
    group: 'النظام',
    icon: IconUsers,
    permission: 'users.manage',
    title: 'المستخدمون',
    subtitle: 'حسابات نظام الإدارة وأدوارها وصلاحياتها الفردية',
    Page: UsersPage,
  },
  {
    key: 'audit',
    label: 'سجلّ العمليات',
    group: 'النظام',
    icon: IconAudit,
    permission: 'audit.view',
    title: 'سجلّ العمليات',
    Page: AuditLogsPage,
  },
]

export default function App() {
  const { user, loading, can } = useAuth()
  const [tab, setTab] = useState(null)

  /*
   * When the account changes we fall back to the first available tab rather
   * than to one particular tab: someone without analytics.view never sees the
   * dashboard, so we must not open it for them and then drop off it.
   */
  useEffect(() => {
    setTab(null)
  }, [user?.id])

  if (loading) {
    return (
      <div className="grid min-h-screen place-items-center">
        <span className="h-6 w-6 animate-spin rounded-full border-2 border-ink-200 border-t-brand-600" />
      </div>
    )
  }

  if (!user) {
    return <LoginPage />
  }

  const visibleTabs = TABS.filter((item) => can(item.permission))
  const active = visibleTabs.find((item) => item.key === tab) ?? visibleTabs[0]

  if (!active) {
    return (
      <div className="grid min-h-screen place-items-center px-6">
        <p className="rounded-2xl border border-ink-200 bg-white px-5 py-4 text-center text-sm text-ink-500">
          لا توجد أقسام متاحة لحسابك. راجع إدارة النظام.
        </p>
      </div>
    )
  }

  const Page = active.Page

  return (
    <Layout
      tabs={visibleTabs}
      activeTab={active.key}
      onTabChange={setTab}
      title={active.title}
      subtitle={active.subtitle}
    >
      <Page onNavigate={setTab} />
    </Layout>
  )
}
