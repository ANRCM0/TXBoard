import { nativeApi, nativeRequest } from './client'

export type UserInfo = {
  id: number
  email: string
  telegram_id: number | null
}

export async function fetchUserInfo(): Promise<UserInfo> {
  const item = await nativeRequest<UserInfo>(nativeApi.get('/me'))
  if (!item || !Number.isSafeInteger(item.id) || item.id < 1 || typeof item.email !== 'string' ||
      (item.telegram_id !== null && !Number.isSafeInteger(item.telegram_id))) {
    throw new Error('Invalid TXAPI account response')
  }
  return { id: item.id, email: item.email, telegram_id: item.telegram_id }
}

type DashboardStats = {
  unpaid_orders: number
  open_tickets: number
  invited_users: number
}
export async function fetchUserStat(): Promise<number[]> {
  const stats = await nativeRequest<DashboardStats>(nativeApi.get('/me/dashboard-stats'))
  if (!stats || ![stats.unpaid_orders, stats.open_tickets, stats.invited_users]
    .every(value => Number.isSafeInteger(value) && value >= 0)) {
    throw new Error('Invalid TXAPI dashboard stats')
  }
  // Existing dashboard UI expects [orders, tickets, invited users].
  return [stats.unpaid_orders, stats.open_tickets, stats.invited_users]
}
