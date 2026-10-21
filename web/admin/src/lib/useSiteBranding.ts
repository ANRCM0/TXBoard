import { useEffect } from 'react'
import { useQuery } from '@tanstack/react-query'
import { fetchGuestConfig } from '../api/comm'

export function useSiteBranding(title?: string) {
  const query = useQuery({ queryKey: ['admin', 'guest-config'], queryFn: fetchGuestConfig, staleTime: 300_000, retry: false })
  const site = query.data?.site ?? { name: 'TXBoard', description: '', logo: null }
  useEffect(() => {
    if (title) document.title = `${title} · ${site.name}`
  }, [site.name, title])
  return site
}
