import { Navigate, Outlet } from 'react-router-dom'
import { currentRouterTarget } from '../../lib/basePath'
import { getAccessToken } from '../../lib/storage'

export function AuthGuard() {
  if (import.meta.env.VITE_STATIC_PREVIEW === '1') return <Outlet />
  if (!getAccessToken()) {
    const redirect = encodeURIComponent(currentRouterTarget())
    return <Navigate to={`/sign-in?redirect=${redirect}`} replace />
  }
  return <Outlet />
}
