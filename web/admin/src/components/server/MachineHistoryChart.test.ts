import { describe, expect, it } from 'vitest'
import { normalizeMachineHistory } from './MachineHistoryChart'

describe('TX-Node load chart normalization', () => {
  it('does not turn missing telemetry into a plausible-looking zero', () => {
    const rows = normalizeMachineHistory([
      {recorded_at:1720000000,cpu:null,mem_used:null,mem_total:1024,disk_used:0,disk_total:100},
      {recorded_at:1720000060,cpu:'6.5',mem_used:512,mem_total:1024,disk_used:17,disk_total:100},
      {recorded_at:1720000120,cpu:'',mem_used:20,mem_total:0,disk_used:undefined,disk_total:100},
    ])
    expect(rows).toHaveLength(3)
    expect(rows[0]).toMatchObject({cpu:null,memory:null,disk:0})
    expect(rows[1]).toMatchObject({cpu:6.5,memory:50,disk:17})
    expect(rows[2]).toMatchObject({cpu:null,memory:null,disk:null})
  })
  it('handles invalid or missing history payloads without producing fake points', () => {
    expect(normalizeMachineHistory(undefined)).toEqual([])
    expect(normalizeMachineHistory({data:[]})).toEqual([])
    expect(normalizeMachineHistory('invalid')).toEqual([])
  })
})
