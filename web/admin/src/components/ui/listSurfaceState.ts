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

/** A one-page or genuinely empty result should not reserve a pagination footer. */
export function listHasPages(total: unknown, pageSize: number, currentPage: number) {
  const count = Number(total)
  return (Number.isFinite(count) && pageSize > 0 && count > pageSize) || currentPage > 1
}
