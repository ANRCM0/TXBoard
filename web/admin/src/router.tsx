import { lazy } from 'react'
import { Navigate, createBrowserRouter } from 'react-router-dom'
import { resolveBasePath } from './lib/basePath'
import { AdminLayout } from './components/layout/AdminLayout'
import { AuthGuard } from './components/layout/AuthGuard'
import { coreNavigationGroups } from './navigation/core'
import { availableAdminPaths } from './native/routes'

const SignIn = lazy(() => import('./pages/SignInPage').then(m => ({ default: m.SignInPage })))
const Overview = lazy(() => import('./native/OverviewPage').then(m => ({ default: m.OverviewPage })))
const Nodes = lazy(() => import('./native/NodesPage').then(m => ({ default: m.NodesPage })))
const Machines = lazy(() => import('./native/MachinesPage').then(m => ({ default: m.MachinesPage })))
const Settings = lazy(() => import('./native/SiteSettingsPage').then(m => ({ default: m.SiteSettingsPage })))
const Tickets = lazy(() => import('./native/TicketsPage').then(m => ({ default: m.TicketsPage })))
const Content = lazy(() => import('./native/ContentPage').then(m => ({ default: m.ContentPage })))
const Coupons = lazy(() => import('./native/CouponsPage').then(m => ({ default: m.CouponsPage })))
const Commissions = lazy(() => import('./native/CommissionsPage').then(m => ({ default: m.CommissionsPage })))
const GiftCards = lazy(() => import('./native/GiftCardsPage').then(m => ({ default: m.GiftCardsPage })))
const Sessions = lazy(() => import('./native/SessionsPage').then(m => ({ default: m.SessionsPage })))
const Pending = lazy(() => import('./native/PendingPage').then(m => ({ default: m.PendingPage })))
const NotFound = lazy(() => import('./pages/NotFoundPage').then(m => ({ default: m.NotFoundPage })))
const Users = lazy(() => import('./pages/users/UsersPage').then(m => ({ default: m.UsersPage })))
const UserDetail = lazy(() => import('./pages/users/UserDetailPage').then(m => ({ default: m.UserDetailPage })))
const Orders = lazy(() => import('./pages/finance/OrdersPage').then(m => ({ default: m.OrdersPage })))

const active = availableAdminPaths
const pendingPaths = new Set([
  ...coreNavigationGroups.flatMap(group => group.items.map(([path]) => path)),
  '/config/system/features', '/config/system/safe', '/config/system/subscribe', '/config/system/invite',
  '/config/server', '/config/email', '/config/telegram',
])

export const router = createBrowserRouter([
  { path: '/sign-in', element: <SignIn /> },
  { element: <AuthGuard />, children: [
    { path: '/', element: <AdminLayout />, children: [
      { index: true, element: <Overview /> },
      { path: 'server/manage', element: <Nodes /> },
      { path: 'server/machine', element: <Machines /> },
      { path: 'config/system', element: <Settings /> },
      { path: 'system/sessions', element: <Sessions /> },
      { path: 'user/ticket', element: <Tickets /> },
      { path: 'config/notice', element: <Content key="notices" kind="notices" /> },
      { path: 'config/knowledge', element: <Content key="knowledge" kind="knowledge" /> },
      { path: 'user/manage', element: <Users /> },
      { path: 'finance/order', element: <Orders /> },
      { path: 'finance/coupon', element: <Coupons /> },
      { path: 'finance/gift-card', element: <GiftCards /> },
      { path: 'finance/commission', element: <Commissions /> },
      ...[...pendingPaths].filter(path => !active.includes(path)).map(path => ({ path: path.slice(1), element: <Pending /> })),
      { path: 'server/node/:nodeId', element: <Pending /> },
      { path: 'server/machine/:machineId', element: <Pending /> },
      { path: 'user/:userId', element: <UserDetail /> },
      { path: 'plugins/:pluginCode/*', element: <Pending /> },
      { path: 'config/frontend', element: <Navigate to="/config/theme" replace /> },
      { path: 'config/APP', element: <Navigate to="/config/system" replace /> },
      { path: 'user', element: <Navigate to="/user/manage" replace /> },
      { path: 'coupon', element: <Navigate to="/finance/coupon" replace /> },
      { path: 'gift-card', element: <Navigate to="/finance/gift-card" replace /> },
      { path: 'ticket', element: <Navigate to="/user/ticket" replace /> },
      { path: 'traffic-reset', element: <Navigate to="/user/traffic-reset" replace /> },
      { path: '*', element: <NotFound /> },
    ] },
  ] },
], { basename: resolveBasePath() })
