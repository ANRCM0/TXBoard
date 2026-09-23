import { describe, expect, it } from 'vitest'
import {
  isMachineHighLoad,
  isMachineOnline,
  summarizeMachines,
} from './machineOpsModel'
import type { MachineItem } from '../../api/server'

// Use a realistic epoch-millisecond value so the production timestamp
// normalizer does not intentionally interpret the fixture as epoch seconds.
const now = 2_000_000_000_000

describe('machine operations model', () => {
  it('derives online state from active status and recent heartbeat', () => {
    expect(isMachineOnline({ id: 1, last_seen_at: now - 60_000 }, now)).toBe(true)
    expect(isMachineOnline({ id: 2, last_seen_at: now - 240_000 }, now)).toBe(false)
    expect(isMachineOnline({ id: 3, is_active: false, last_seen_at: now }, now)).toBe(false)
  })

  it('marks sustained resource pressure as high load', () => {
    expect(isMachineHighLoad({
      id: 1,
      load_status: { mem: { used: 90, total: 100 } },
    })).toBe(true)
    expect(isMachineHighLoad({
      id: 2,
      load_status: { cpu: 25, mem: { used: 30, total: 100 }, disk: { used: 50, total: 100 } },
    })).toBe(false)
  })

  it('summarizes fleet state without adding a second source of truth', () => {
    const rows: MachineItem[] = [
      { id: 1, last_seen_at: now - 10_000, servers_count: 2, load_status: { cpu: 10 } },
      { id: 2, last_seen_at: now - 400_000, servers_count: 3, load_status: { cpu: 95 } },
      { id: 3, is_active: false, last_seen_at: now, servers_count: 1 },
    ]

    expect(summarizeMachines(rows, now)).toEqual({
      total: 3,
      online: 1,
      offline: 2,
      highLoad: 1,
      nodes: 6,
    })
  })
})
