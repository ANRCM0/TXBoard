import type { MachineItem } from '../../api/server'
import { isMachineHighLoad, isMachineOnline, machineTimestamp } from './machineOpsModel'

export type MachinePresence = { label: string; tone: 'success' | 'warning' | 'neutral' }

export function machinePresence(machine: MachineItem, now = Date.now()): MachinePresence {
  if (machine.is_active === false) return { label: '已停用', tone: 'neutral' }
  if (machineTimestamp(machine.last_seen_at) === null) return { label: '未连接', tone: 'neutral' }
  if (isMachineOnline(machine, now)) return { label: '在线', tone: 'success' }
  return { label: '离线', tone: 'warning' }
}

export function machinePercentage(value: unknown): number | null {
  if (value === null || value === undefined || typeof value === 'boolean' ||
    (typeof value === 'string' && !value.trim())) return null
  const amount = Number(value)
  return Number.isFinite(amount) && amount >= 0 ? amount : null
}

export function machineUtilization(resource: MachineItem['load_status'] extends never ? never : unknown): number | null {
  if (!resource || typeof resource !== 'object' || Array.isArray(resource)) return null
  const input = resource as Record<string, unknown>
  const used = machinePercentage(input.used)
  const total = machinePercentage(input.total)
  if (used === null || total === null || total <= 0) return null
  return used / total * 100
}

export function machineFleetSummary(machines: readonly MachineItem[], now = Date.now()) {
  const online = machines.filter(machine => machinePresence(machine, now).label === '在线').length
  return {
    total: machines.length,
    online,
    attention: machines.length - online,
    highLoad: machines.filter(isMachineHighLoad).length,
    nodes: machines.reduce((sum, machine) => {
      const n = machinePercentage(machine.servers_count)
      return sum + (n ?? 0)
    }, 0),
  }
}

export function machineHeartbeat(value: unknown): string {
  const timestamp = machineTimestamp(value)
  if (timestamp === null) return '未上报'
  return new Date(timestamp).toLocaleString('zh-CN')
}
