import { lazy } from 'react'
import { Navigate, createBrowserRouter, createHashRouter } from 'react-router-dom'
import { resolveBasePath } from './lib/basePath'
import { AdminLayout } from './components/layout/AdminLayout'
import { AuthGuard } from './components/layout/AuthGuard'

const SignInPage = lazy(() => import('./pages/SignInPage').then(module => ({ default: module.SignInPage })))
const DashboardPage = lazy(() => import('./pages/DashboardPage').then(module => ({ default: module.DashboardPage })))
const AuditLogPage = lazy(() => import('./pages/system/AuditLogPage').then(module => ({ default: module.AuditLogPage })))
const AgentOpsPage = lazy(() => import('./pages/system/AgentOpsPage').then(module => ({ default: module.AgentOpsPage })))
const SystemSettingsPage = lazy(() => import('./pages/config/SystemSettingsPage').then(module => ({ default: module.SystemSettingsPage })))
const SafeSettingsPage = lazy(() => import('./pages/config/SafeSettingsPage').then(module => ({ default: module.SafeSettingsPage })))
const SubscribeSettingsPage = lazy(() => import('./pages/config/SubscribeSettingsPage').then(module => ({ default: module.SubscribeSettingsPage })))
const InviteSettingsPage = lazy(() => import('./pages/config/InviteSettingsPage').then(module => ({ default: module.InviteSettingsPage })))
const FrontendSettingsPage = lazy(() => import('./pages/config/FrontendSettingsPage').then(module => ({ default: module.FrontendSettingsPage })))
const ServerSettingsPage = lazy(() => import('./pages/config/ServerSettingsPage').then(module => ({ default: module.ServerSettingsPage })))
const GenericSettingsPage = lazy(() => import('./pages/config/GenericSettingsPage').then(module => ({ default: module.GenericSettingsPage })))
const EmailSettingsPage = lazy(() => import('./pages/config/EmailSettingsPage').then(module => ({ default: module.EmailSettingsPage })))
const TelegramSettingsPage = lazy(() => import('./pages/config/TelegramSettingsPage').then(module => ({ default: module.TelegramSettingsPage })))
const AppSettingsPage = lazy(() => import('./pages/config/AppSettingsPage').then(module => ({ default: module.AppSettingsPage })))
const ThemeSettingsPage = lazy(() => import('./pages/config/ThemeSettingsPage').then(module => ({ default: module.ThemeSettingsPage })))
const PaymentSettingsPage = lazy(() => import('./pages/config/PaymentSettingsPage').then(module => ({ default: module.PaymentSettingsPage })))
const NoticeSettingsPage = lazy(() => import('./pages/config/NoticeSettingsPage').then(module => ({ default: module.NoticeSettingsPage })))
const KnowledgeSettingsPage = lazy(() => import('./pages/config/KnowledgeSettingsPage').then(module => ({ default: module.KnowledgeSettingsPage })))
const NodesPage = lazy(() => import('./pages/server/NodesPage').then(module => ({ default: module.NodesPage })))
const NodeDetailPage = lazy(() => import('./pages/server/NodeDetailPage').then(module => ({ default: module.NodeDetailPage })))
const MachinesPage = lazy(() => import('./pages/server/MachinesPage').then(module => ({ default: module.MachinesPage })))
const MachineDetailPage = lazy(() => import('./pages/server/MachineDetailPage').then(module => ({ default: module.MachineDetailPage })))
const GroupsPage = lazy(() => import('./pages/server/GroupsPage').then(module => ({ default: module.GroupsPage })))
const RoutesPage = lazy(() => import('./pages/server/RoutesPage').then(module => ({ default: module.RoutesPage })))
const PlansPage = lazy(() => import('./pages/finance/PlansPage').then(module => ({ default: module.PlansPage })))
const OrdersPage = lazy(() => import('./pages/finance/OrdersPage').then(module => ({ default: module.OrdersPage })))
const GiftCardPage = lazy(() => import('./pages/finance/GiftCardPage').then(module => ({ default: module.GiftCardPage })))
const CouponPage = lazy(() => import('./pages/finance/CouponPage').then(module => ({ default: module.CouponPage })))
const PluginsPage = lazy(() => import('./pages/plugins/PluginsPage').then(module => ({ default: module.PluginsPage })))
const PluginRoutePage = lazy(() => import('./pages/plugins/PluginRoutePage').then(module => ({ default: module.PluginRoutePage })))
const UsersPage = lazy(() => import('./pages/users/UsersPage').then(module => ({ default: module.UsersPage })))
const UserDetailPage = lazy(() => import('./pages/users/UserDetailPage').then(module => ({ default: module.UserDetailPage })))
const TicketsPage = lazy(() => import('./pages/users/TicketsPage').then(module => ({ default: module.TicketsPage })))
const TrafficResetPage = lazy(() => import('./pages/users/TrafficResetPage').then(module => ({ default: module.TrafficResetPage })))
const NotFoundPage = lazy(() => import('./pages/NotFoundPage').then(module => ({ default: module.NotFoundPage })))

// The GitHub Pages preview uses hash routing because a static host cannot
// rewrite deep links back to index.html.
const staticPreview = import.meta.env.VITE_STATIC_PREVIEW === '1'
const createAppRouter = staticPreview ? createHashRouter : createBrowserRouter

// `basename` keeps every nested route, redirect and absolute link inside the
// mount point the bundle is served from (for example `/admin`).
//
// It must only be applied to the browser router: a hash router strips basename
// from the URL *fragment* path, not from `window.location.pathname`. The
// preview mounts the bundle under `/TXBoard/admin/` via VITE_BASE_PATH (which
// only rewrites asset URLs) and routes through `#/`, so passing the mount
// prefix here made every route miss and rendered a blank page.
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
          { path: 'system/agent-ops', element: <AgentOpsPage /> },
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
          { path: 'server/node/:nodeId', element: <NodeDetailPage /> },
          { path: 'server/machine', element: <MachinesPage /> },
          { path: 'server/machine/:machineId', element: <MachineDetailPage /> },
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
          { path: 'user/:userId', element: <UserDetailPage /> },
          { path: 'user/ticket', element: <TicketsPage /> },
          { path: 'user/traffic-reset', element: <TrafficResetPage /> },
          { path: 'traffic-reset', element: <Navigate to="/user/traffic-reset" replace /> },
          { path: 'ticket', element: <Navigate to="/user/ticket" replace /> },
          // Lowest-priority catch-all: any unmatched admin URL renders an
          // explicit 404 inside the layout instead of the router's default
          // error screen (or the old placeholder skeleton).
          { path: '*', element: <NotFoundPage /> },
        ],
      },
    ],
  },
], staticPreview ? undefined : { basename: resolveBasePath() })
