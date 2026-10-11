/** Returns an immutable rearrangement, or null when the move is not meaningful. */
export function movePlan<T extends { id: number }>(
  rows: readonly T[],
  planId: number,
  direction: -1 | 1,
): T[] | null {
  const index = rows.findIndex(row => row.id === planId)
  const target = index + direction
  if (index < 0 || target < 0 || target >= rows.length) return null
  return moveTo(rows, index, target)
}

/** Same ordering model for drag-and-drop and accessible up/down controls. */
export function dropPlan<T extends { id: number }>(
  rows: readonly T[],
  draggedId: number | null,
  targetId: number,
): T[] | null {
  if (draggedId === null) return null
  const from = rows.findIndex(row => row.id === draggedId)
  const to = rows.findIndex(row => row.id === targetId)
  if (from < 0 || to < 0 || from === to) return null
  return moveTo(rows, from, to)
}

function moveTo<T>(rows: readonly T[], from: number, to: number): T[] {
  const next = [...rows]
  const [item] = next.splice(from, 1)
  next.splice(to, 0, item)
  return next
}
