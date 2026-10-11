import {describe,expect,it} from 'vitest'
import {
  machineActionSummary,machineDetailAddress,machineDetailPercent,
  machineDetailPresence,machineDetailRate,machineDetailRatio,
  machineDetailSize,machineDetailTime,
} from './machineDetailPresentation'
describe('machine details v3 presentation',()=>{
  const now=Date.UTC(2026,9,11,10,0,0)
  it('keeps missing heartbeat separate from offline and disabled',()=>{
    expect(machineDetailPresence({},now).label).toBe('未连接')
    expect(machineDetailPresence({is_active:false},now).label).toBe('已停用')
    expect(machineDetailPresence({last_seen_at:now-10000},now).label).toBe('在线')
    expect(machineDetailPresence({last_seen_at:now-400000},now).label).toBe('离线')
  })
  it('does not invent zero percent, rate or memory size from missing values',()=>{
    expect(machineDetailPercent(null)).toBeNull()
    expect(machineDetailPercent('')).toBeNull()
    expect(machineDetailPercent(false)).toBeNull()
    expect(machineDetailPercent(0)).toBe(0)
    expect(machineDetailRatio({used:null,total:100})).toBeNull()
    expect(machineDetailRatio({used:0,total:100})).toBe(0)
    expect(machineDetailRatio({used:90,total:100})).toBe(90)
    expect(machineDetailRatio({used:5,total:0})).toBeNull()
    expect(machineDetailSize(null)).toBe('—')
    expect(machineDetailSize(0)).toBe('0 B')
    expect(machineDetailSize(1048576)).toBe('1 MB')
    expect(machineDetailRate(null)).toBe('—')
    expect(machineDetailRate(0)).toBe('0 B/s')
    expect(machineDetailTime(null)).toBe('未上报')
  })
  it('handles IPv6 correctly and redacts secret-bearing action payload keys',()=>{
    expect(machineDetailAddress({host:'2001:db8::1',port:443})).toBe('[2001:db8::1]:443')
    expect(machineDetailAddress({host:'host.example',port:443})).toBe('host.example:443')
    const summary=machineActionSummary({token:'SHOULD_NOT_APPEAR',nested:{api_key:'ANOTHER_SECRET'},ok:true})
    expect(summary).not.toContain('SHOULD_NOT_APPEAR')
    expect(summary).not.toContain('ANOTHER_SECRET')
    expect(summary).toContain('true')
  })
})
