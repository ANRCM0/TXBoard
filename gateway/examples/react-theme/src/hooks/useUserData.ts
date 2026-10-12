// useUserData hook: profile + subscription + dashboard stats (GW-505).
// All three are Gateway user-read routes; the Bearer is injected by the SDK.
import { useCallback, useEffect, useState } from 'react'
import type { DashboardStats, SubscriptionSummary, UserProfile } from '@txboard/theme-sdk'
import { api } from '../api'

export function useUserData(enabled: boolean) {
  const [profile, setProfile] = useState<UserProfile | null>(null)
  const [subscription, setSubscription] = useState<SubscriptionSummary | null>(null)
  const [stats, setStats] = useState<DashboardStats | null>(null)
  const [error, setError] = useState<Error | null>(null)
  const [loading, setLoading] = useState(false)

  const load = useCallback(async () => {
    if (!enabled) return
    setLoading(true)
    setError(null)
    try {
      const [p, s, d] = await Promise.all([
        api.user.profile(),
        api.user.subscription(),
        api.dashboard.stats(),
      ])
      setProfile(p); setSubscription(s); setStats(d)
    } catch (err) {
      setError(err as Error)
    } finally {
      setLoading(false)
    }
  }, [enabled])

  useEffect(() => { void load() }, [load])

  return { profile, subscription, stats, error, loading, reload: load }
}
