import { describe, expect, it } from 'vitest'
import { dropPlan, movePlan } from './planReorder'

const rows = [{ id: 1 }, { id: 2 }, { id: 3 }]

describe('plan ordering', () => {
  it('moves one position in either direction without mutating original rows', () => {
    expect(movePlan(rows, 2, -1)?.map(row => row.id)).toEqual([2, 1, 3])
    expect(movePlan(rows, 2, 1)?.map(row => row.id)).toEqual([1, 3, 2])
    expect(rows.map(row => row.id)).toEqual([1, 2, 3])
  })

  it('ignores boundary, missing or unchanged drag targets', () => {
    expect(movePlan(rows, 1, -1)).toBeNull()
    expect(movePlan(rows, 3, 1)).toBeNull()
    expect(movePlan(rows, 99, 1)).toBeNull()
    expect(dropPlan(rows, null, 2)).toBeNull()
    expect(dropPlan(rows, 1, 1)).toBeNull()
    expect(dropPlan(rows, 5, 2)).toBeNull()
  })

  it('preserves drag drop ordering semantics', () => {
    expect(dropPlan(rows, 3, 1)?.map(row => row.id)).toEqual([3, 1, 2])
    expect(dropPlan(rows, 1, 3)?.map(row => row.id)).toEqual([2, 3, 1])
  })
})
