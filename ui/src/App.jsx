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
 * التبويبات تظهر حسب الصلاحيات لا حسب الأدوار — فمن مُنح audit.view
 * منحاً مباشراً يرى تبويب السجلّ دون أن يكون مشرفاً. وهذا يطابق ما
 * يحرس به الخادم كل مسار بالضبط.
 */
const TABS = [
  {
    key: 'dashboard',
    label: 'لوحة المعلومات',
    icon: IconChart,
    permission: 'analytics.view',
    title: 'لوحة المعلومات',
    subtitle: 'أرقام المنصّة وآخر العمليات',
    Page: DashboardPage,
  },
  {
    key: 'articles',
    label: 'المقالات',
    icon: IconArticles,
    permission: 'articles.view',
    title: 'المقالات',
    subtitle: 'تُدار في منصّة المعرفة عبر واجهتها البرمجية',
    Page: ArticlesPage,
  },
  {
    key: 'users',
    label: 'المستخدمون',
    icon: IconUsers,
    permission: 'users.manage',
    title: 'المستخدمون',
    subtitle: 'حسابات نظام الإدارة وأدوارها وصلاحياتها الفردية',
    Page: UsersPage,
  },
  {
    key: 'audit',
    label: 'سجلّ العمليات',
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
   * عند تبديل الحساب نعود إلى أول تبويب متاح لا إلى تبويب بعينه: مَن لا يملك
   * analytics.view لا يرى لوحة المعلومات، فلا نفتحها له ثم نسقط عنها.
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
      <Page />
    </Layout>
  )
}
