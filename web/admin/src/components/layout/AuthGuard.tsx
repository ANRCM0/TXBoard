import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAdminSession } from '../../native/session'

export function AuthGuard() {
  const authenticated = useAdminSession()
  const location = useLocation()
  if (!authenticated) {
    const redirect = encodeURIComponent(location.pathname + location.search)
    return <Navigate to={'/sign-in?redirect=' + redirect} replace />
  }
  return <Outlet />
}
