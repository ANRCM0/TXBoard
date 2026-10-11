export type ListSurfaceState = 'rows' | 'empty' | 'loading' | 'error'

/** Stale rows stay visible during background refresh and retry failures. */
export function listSurfaceState({ count, loading, error, placeholder = false }: {
  count: number
  loading?: boolean
  error?: boolean
  placeholder?: boolean
}): ListSurfaceState {
  if (count > 0) return 'rows'
  if (error) return 'error'
  if (loading || placeholder) return 'loading'
  return 'empty'
}
