import { Navigate, createBrowserRouter, createHashRouter } from 'react-router-dom'
import { resolveBasePath } from './lib/basePath'
import { AdminLayout } from './components/layout/AdminLayout'
import { AuthGuard } from './components/layout/AuthGuard'
import { SignInPage } from './pages/SignInPage'
import { DashboardPage } from './pages/DashboardPage'
import { AuditLogPage } from './pages/system/AuditLogPage'
import { SystemSettingsPage } from './pages/config/SystemSettingsPage'
import { SafeSettingsPage } from './pages/config/SafeSettingsPage'
import { SubscribeSettingsPage } from './pages/config/SubscribeSettingsPage'
import { InviteSettingsPage } from './pages/config/InviteSettingsPage'
import { FrontendSettingsPage } from './pages/config/FrontendSettingsPage'
import { ServerSettingsPage } from './pages/config/ServerSettingsPage'
import { GenericSettingsPage } from './pages/config/GenericSettingsPage'
import { EmailSettingsPage } from './pages/config/EmailSettingsPage'
import { TelegramSettingsPage } from './pages/config/TelegramSettingsPage'
import { AppSettingsPage } from './pages/config/AppSettingsPage'
import { ThemeSettingsPage } from './pages/config/ThemeSettingsPage'
import { PaymentSettingsPage } from './pages/config/PaymentSettingsPage'
import { NoticeSettingsPage } from './pages/config/NoticeSettingsPage'
import { KnowledgeSettingsPage } from './pages/config/KnowledgeSettingsPage'
import { NodesPage } from './pages/server/NodesPage'
import { MachinesPage } from './pages/server/MachinesPage'
import { GroupsPage } from './pages/server/GroupsPage'
import { RoutesPage } from './pages/server/RoutesPage'
import { PlansPage } from './pages/finance/PlansPage'
import { OrdersPage } from './pages/finance/OrdersPage'
import { GiftCardPage } from './pages/finance/GiftCardPage'
import { CouponPage } from './pages/finance/CouponPage'
import { PluginsPage } from './pages/plugins/PluginsPage'
import { PluginRoutePage } from './pages/plugins/PluginRoutePage'
import { UsersPage } from './pages/users/UsersPage'
import { TicketsPage } from './pages/users/TicketsPage'
import { TrafficResetPage } from './pages/users/TrafficResetPage'
import { PlaceholderPage } from './pages/PlaceholderPage'

const createAppRouter = import.meta.env.VITE_STATIC_PREVIEW === '1' ? createHashRouter : createBrowserRouter

// `basename` keeps every nested route, redirect and absolute link inside the
// mount point the bundle is served from (for example `/admin`).
export const router = createAppRouter([
  { path: '/sign-in', element: <SignInPage /> },
  {
    element: <AuthGuard />,
    children: [
      {
        path: '/',
        element: <AdminLayout />,
        children: [
          { index: true, element: <DashboardPage /> },
          { path: 'system/audit-log', element: <AuditLogPage /> },
          { path: 'config/system', element: <SystemSettingsPage /> },
          { path: 'config/system/safe', element: <SafeSettingsPage /> },
          { path: 'config/system/subscribe', element: <SubscribeSettingsPage /> },
          { path: 'config/system/invite', element: <InviteSettingsPage /> },
          { path: 'config/frontend', element: <FrontendSettingsPage /> },
          { path: 'config/server', element: <ServerSettingsPage /> },
          { path: 'config/email', element: <EmailSettingsPage /> },
          { path: 'config/telegram', element: <TelegramSettingsPage /> },
          { path: 'config/APP', element: <AppSettingsPage /> },
          { path: 'config/payment', element: <PaymentSettingsPage /> },
          { path: 'config/theme', element: <ThemeSettingsPage /> },
          { path: 'config/notice', element: <NoticeSettingsPage /> },
          { path: 'config/knowledge', element: <KnowledgeSettingsPage /> },
          { path: 'config/plugin', element: <PluginsPage /> },
          { path: 'config/subscribe-template', element: <GenericSettingsPage settingKey="subscribe_template" title="订阅模板" /> },
          { path: 'server/manage', element: <NodesPage /> },
          { path: 'server/machine', element: <MachinesPage /> },
          { path: 'server/group', element: <GroupsPage /> },
          { path: 'server/route', element: <RoutesPage /> },
          { path: 'finance/plan', element: <PlansPage /> },
          { path: 'finance/order', element: <OrdersPage /> },
          { path: 'finance/gift-card', element: <GiftCardPage /> },
          { path: 'finance/coupon', element: <CouponPage /> },
          { path: 'coupon', element: <Navigate to="/finance/coupon" replace /> },
          { path: 'gift-card', element: <Navigate to="/finance/gift-card" replace /> },
          { path: 'plugins/:pluginCode/*', element: <PluginRoutePage /> },
          { path: 'user', element: <Navigate to="/user/manage" replace /> },
          { path: 'user/manage', element: <UsersPage /> },
          { path: 'user/ticket', element: <TicketsPage /> },
          { path: 'user/traffic-reset', element: <TrafficResetPage /> },
          { path: 'traffic-reset', element: <Navigate to="/user/traffic-reset" replace /> },
          { path: 'user/*', element: <PlaceholderPage title="用户扩展" description="流量重置、邀请关系等高级用户工具继续补充中。" /> },
          { path: 'ticket', element: <Navigate to="/user/ticket" replace /> },
        ],
      },
    ],
  },
], { basename: resolveBasePath() })
