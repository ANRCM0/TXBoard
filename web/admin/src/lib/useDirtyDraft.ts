import { useLayoutEffect, useRef } from 'react'

const drafts = new Set<symbol>()
const listeners = new Set<() => void>()
const emit = () => { for (const listener of listeners) listener() }

export const dirtyDrafts = {
  subscribe(listener: () => void) { listeners.add(listener); return () => { listeners.delete(listener) } },
  snapshot: () => drafts.size > 0,
  set(owner: symbol, dirty: boolean) {
    const before = drafts.size > 0
    if (dirty) drafts.add(owner)
    else drafts.delete(owner)
    if (before !== (drafts.size > 0)) emit()
  },
}

/** Pages can register drafts under a MemoryRouter; only the layout needs a data router. */
export function useDirtyDraft(dirty: boolean) {
  const owner = useRef(Symbol('dirty-draft'))
  useLayoutEffect(() => {
    dirtyDrafts.set(owner.current, dirty)
    return () => dirtyDrafts.set(owner.current, false)
  }, [dirty])
}
