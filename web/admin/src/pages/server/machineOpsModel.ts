import type { MachineItem } from '../../api/server'

export function machineTimestamp(value: unknown) {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value < 10_000_000_000 ? value * 1000 : value
  }
  if (typeof value === 'string' && value) {
    const parsed = Date.parse(value)
    if (Number.isFinite(parsed)) return parsed
  }
  return null
}

export function isMachineOnline(machine: MachineItem, now = Date.now()) {
  if (machine.is_active === false) return false
  const last = machineTimestamp(machine.last_seen_at)
  return last !== null && now - last < 180_000
}

export function machineRatio(value?: { total?: number; used?: number }) {
  const total = Number(value?.total)
  const used = Number(value?.used)
  if (!Number.isFinite(total) || !Number.isFinite(used) || total <= 0) return null
  return (used / total) * 100
}

export function isMachineHighLoad(machine: MachineItem) {
  const cpu = Number(machine.load_status?.cpu)
  const memory = machineRatio(machine.load_status?.mem)
  const disk = machineRatio(machine.load_status?.disk)
  return (
    (Number.isFinite(cpu) && cpu >= 85) ||
    (memory !== null && memory >= 85) ||
    (disk !== null && disk >= 90)
  )
}

export function summarizeMachines(machines: readonly MachineItem[], now = Date.now()) {
  const online = machines.filter(machine => isMachineOnline(machine, now)).length
  return {
    total: machines.length,
    online,
    offline: machines.length - online,
    highLoad: machines.filter(isMachineHighLoad).length,
    nodes: machines.reduce((sum, machine) => sum + Number(machine.servers_count || 0), 0),
  }
}
