// usePlans hook: public plan list (GW-505). Guest-read only, zero Bearer.
import { useCallback, useEffect, useState } from 'react'
import type { NativeOffer } from '@txboard/theme-sdk'
import { api } from '../api'

export function usePlans() {
  const [plans, setPlans] = useState<NativeOffer[]>([])
  const [error, setError] = useState<Error | null>(null)
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      setPlans(await api.plans.list())
    } catch (err) {
      setError(err as Error)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { void load() }, [load])

  return { plans, error, loading, reload: load }
}
