import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { getAccessToken } from '../../lib/storage'

export function AuthGuard() {
  const location = useLocation()
  if (import.meta.env.VITE_STATIC_PREVIEW === '1') return <Outlet />
  if (!getAccessToken()) {
    const redirect = encodeURIComponent(location.pathname + location.search)
    return <Navigate to={`/sign-in?redirect=${redirect}`} replace />
  }
  return <Outlet />
}
