import { lazy } from 'react'
import { Navigate, createBrowserRouter } from 'react-router-dom'
import { resolveBasePath } from './lib/basePath'
import { AdminLayout } from './components/layout/AdminLayout'
import { AuthGuard } from './components/layout/AuthGuard'

const SignIn = lazy(() => import('./pages/SignInPage').then(m => ({ default: m.SignInPage })))
const Overview = lazy(() => import('./native/OverviewPage').then(m => ({ default: m.OverviewPage })))
const Nodes = lazy(() => import('./native/NodesPage').then(m => ({ default: m.NodesPage })))
const Machines = lazy(() => import('./native/MachinesPage').then(m => ({ default: m.MachinesPage })))
const Settings = lazy(() => import('./native/SettingsPage').then(m => ({ default: m.SettingsPage })))
const Appearance = lazy(() => import('./native/AppearancePage').then(m => ({ default: m.AppearancePage })))
const Tickets = lazy(() => import('./native/TicketsPage').then(m => ({ default: m.TicketsPage })))
const Content = lazy(() => import('./native/ContentPage').then(m => ({ default: m.ContentPage })))
const Coupons = lazy(() => import('./native/CouponsPage').then(m => ({ default: m.CouponsPage })))
const Commissions = lazy(() => import('./native/CommissionsPage').then(m => ({ default: m.CommissionsPage })))
const GiftCards = lazy(() => import('./native/GiftCardsPage').then(m => ({ default: m.GiftCardsPage })))
const Catalog = lazy(() => import('./native/CatalogPage').then(m => ({ default: m.CatalogPage })))
const Groups = lazy(() => import('./native/GroupsPage').then(m => ({ default: m.GroupsPage })))
const NetworkRoutes = lazy(() => import('./native/RoutesPage').then(m => ({ default: m.RoutesPage })))
const AccessAudit = lazy(() => import('./native/AccessAuditPage').then(m => ({ default: m.AccessAuditPage })))
const Sessions = lazy(() => import('./native/SessionsPage').then(m => ({ default: m.SessionsPage })))
const Audit = lazy(() => import('./native/AuditLogsPage').then(m => ({ default: m.AuditLogsPage })))
const Modules = lazy(() => import('./native/ModulesPage').then(m => ({ default: m.ModulesPage })))
const Queue = lazy(() => import('./native/QueuePage').then(m => ({ default: m.QueuePage })))
const Payments = lazy(() => import('./native/PaymentMethodsPage').then(m => ({ default: m.PaymentMethodsPage })))
const MailTemplates = lazy(() => import('./native/MailTemplatesPage').then(m => ({ default: m.MailTemplatesPage })))
const SubscriptionTemplates = lazy(() => import('./native/SubscriptionTemplatesPage').then(m => ({ default: m.SubscriptionTemplatesPage })))
const NodeDetail = lazy(() => import('./native/NodeDetailPage').then(m => ({ default: m.NodeDetailPage })))
const MachineDetail = lazy(() => import('./native/MachineDetailPage').then(m => ({ default: m.MachineDetailPage })))
const TrafficReset = lazy(() => import('./native/TrafficResetPage').then(m => ({ default: m.TrafficResetPage })))
const NotFound = lazy(() => import('./pages/NotFoundPage').then(m => ({ default: m.NotFoundPage })))
const Users = lazy(() => import('./pages/users/UsersPage').then(m => ({ default: m.UsersPage })))
const UserDetail = lazy(() => import('./pages/users/UserDetailPage').then(m => ({ default: m.UserDetailPage })))
const Orders = lazy(() => import('./pages/finance/OrdersPage').then(m => ({ default: m.OrdersPage })))

export const router = createBrowserRouter([
  { path: '/sign-in', element: <SignIn /> },
  { element: <AuthGuard />, children: [
    { path: '/', element: <AdminLayout />, children: [
      { index: true, element: <Overview /> },
      { path: 'server/manage', element: <Nodes /> },
      { path: 'server/machine', element: <Machines /> },
      { path: 'server/group', element: <Groups /> },
      { path: 'server/route', element: <NetworkRoutes /> },
      { path: 'server/access-audit', element: <AccessAudit /> },
      { path: 'config/system', element: <Settings /> },
      { path: 'config/system/features', element: <Settings group="features" /> },
      { path: 'config/system/safe', element: <Settings group="safe" /> },
      { path: 'config/system/subscribe', element: <Settings group="subscribe" /> },
      { path: 'config/system/subscribe-template', element: <SubscriptionTemplates /> },
      { path: 'config/system/invite', element: <Commissions /> },
      { path: 'config/server', element: <Settings group="server" /> },
      { path: 'config/email', element: <Settings group="email" /> },
      { path: 'config/telegram', element: <Settings group="telegram" /> },
      { path: 'config/app', element: <Settings group="app" /> },
      { path: 'config/email/templates', element: <MailTemplates /> },
      { path: 'config/payment', element: <Payments /> },
      { path: 'system/audit-log', element: <Audit /> },
      { path: 'system/modules', element: <Modules /> },
      { path: 'config/plugin', element: <Modules kind="plugin" /> },
      { path: 'config/theme', element: <Modules kind="theme" /> },
      { path: 'config/appearance', element: <Appearance /> },
      { path: 'system/queue', element: <Queue /> },
      { path: 'system/sessions', element: <Sessions /> },
      { path: 'user/ticket', element: <Tickets /> },
      { path: 'config/notice', element: <Content key="notices" kind="notices" /> },
      { path: 'config/knowledge', element: <Content key="knowledge" kind="knowledge" /> },
      { path: 'user/manage', element: <Users /> },
      { path: 'user/traffic-reset', element: <TrafficReset /> },
      { path: 'user/traffic-reset-logs', element: <TrafficReset /> },
      { path: '404', element: <NotFound /> },
      { path: '500', element: <NotFound status={500} /> },
      { path: '503', element: <NotFound status={503} /> },
      { path: 'finance/order', element: <Orders /> },
      { path: 'finance/plan', element: <Catalog /> },
      { path: 'finance/coupon', element: <Coupons /> },
      { path: 'finance/gift-card', element: <GiftCards /> },
      { path: 'finance/commission', element: <Commissions /> },
      { path: 'server/node/:nodeId', element: <NodeDetail /> },
      { path: 'server/machine/:machineId', element: <MachineDetail /> },
      { path: 'user/:userId', element: <UserDetail /> },
      { path: 'plugins/:pluginCode/*', element: <Navigate to="/config/plugin" replace /> },
      { path: 'config/frontend', element: <Navigate to="/config/appearance" replace /> },
      { path: 'config/system/frontend', element: <Navigate to="/config/appearance" replace /> },
      { path: 'config/APP', element: <Navigate to="/config/app" replace /> },
      { path: 'config/system/server', element: <Navigate to="/config/server" replace /> },
      { path: 'config/system/email', element: <Navigate to="/config/email" replace /> },
      { path: 'config/system/telegram', element: <Navigate to="/config/telegram" replace /> },
      { path: 'config/system/app', element: <Navigate to="/config/app" replace /> },
      { path: 'user', element: <Navigate to="/user/manage" replace /> },
      { path: 'coupon', element: <Navigate to="/finance/coupon" replace /> },
      { path: 'gift-card', element: <Navigate to="/finance/gift-card" replace /> },
      { path: 'ticket', element: <Navigate to="/user/ticket" replace /> },
      { path: 'traffic-reset', element: <Navigate to="/user/traffic-reset" replace /> },
      { path: '*', element: <NotFound /> },
    ] },
  ] },
], { basename: resolveBasePath() })
