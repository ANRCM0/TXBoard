import { describe, expect, it } from 'vitest'
import type { MachineItem } from '../../api/server'
import { machineFleetSummary, machineHeartbeat, machinePercentage, machinePresence, machineUtilization } from './machineListPresentation'

describe('machine page presentation', () => {
  const now = new Date('2026-10-11T10:00:00.000Z').getTime()

  it('distinguishes active/disabled/offline machines from machines without heartbeats', () => {
    expect(machinePresence({id:1,is_active:false,last_seen_at:now},now).label).toBe('已停用')
    expect(machinePresence({id:2},now).label).toBe('未连接')
    expect(machinePresence({id:3,last_seen_at:now-60_000},now).label).toBe('在线')
    expect(machinePresence({id:4,last_seen_at:now-500_000},now).label).toBe('离线')
  })

  it('does not invent zero percentages from missing or invalid measurements', () => {
    expect(machinePercentage(null)).toBeNull()
    expect(machinePercentage('')).toBeNull()
    expect(machinePercentage(false)).toBeNull()
    expect(machinePercentage(-12)).toBeNull()
    expect(machinePercentage(0)).toBe(0)
    expect(machineUtilization({used:null,total:100})).toBeNull()
    expect(machineUtilization({used:0,total:100})).toBe(0)
    expect(machineUtilization({used:90,total:100})).toBe(90)
    expect(machineUtilization({used:50,total:0})).toBeNull()
  })

  it('keeps fleet attention separate from high load and does not fake node counts', () => {
    const rows:MachineItem[] = [
      {id:1,is_active:true,last_seen_at:now-10_000,servers_count:3,load_status:{cpu:92}},
      {id:2,is_active:true,servers_count:undefined},
      {id:3,is_active:false,last_seen_at:now-20_000,servers_count:2},
    ]
    expect(machineFleetSummary(rows,now)).toEqual({
      total:3,online:1,attention:2,highLoad:1,nodes:5,
    })
    expect(machineHeartbeat(null)).toBe('未上报')
    expect(machineHeartbeat('invalid-date')).toBe('未上报')
  })
})
