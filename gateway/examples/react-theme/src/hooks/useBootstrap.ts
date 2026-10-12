// useBootstrap hook: site metadata, theme appearance, CAPTCHA and capabilities.
import { useCallback, useEffect, useState } from 'react'
import type { BootstrapData } from '@txboard/theme-sdk'
import { api } from '../api'

export function useBootstrap() {
  const [data, setData] = useState<BootstrapData | null>(null)
  const [error, setError] = useState<Error | null>(null)
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      setData(await api.bootstrap())
    } catch (err) {
      setError(err as Error)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => { void load() }, [load])

  return { data, error, loading, reload: load }
}
