import type { MachineItem, NodeItem } from '../../api/server'
import { machineTimestamp } from './machineOpsModel'
import { formatTraffic } from '../../lib/dashboardVisuals'

export function machineDetailPresence(machine: Pick<MachineItem,'is_active'|'last_seen_at'>, now=Date.now()) {
  if (machine.is_active === false) return {label:'已停用',tone:'neutral'} as const
  const seen = machineTimestamp(machine.last_seen_at)
  if (seen === null) return {label:'未连接',tone:'neutral'} as const
  if (seen <= now && now-seen < 180_000) return {label:'在线',tone:'success'} as const
  return {label:'离线',tone:'warning'} as const
}

function measurement(value: unknown): number|null {
  if (value === undefined || value === null || typeof value === 'boolean' ||
    (typeof value === 'string' && !value.trim())) return null
  const number = Number(value)
  return Number.isFinite(number) && number >= 0 ? number : null
}
export function machineDetailPercent(value: unknown) {
  return measurement(value)
}
export function machineDetailRatio(input: unknown) {
  if (!input || typeof input!=='object') return null
  const entry=input as Record<string,unknown>
  const total=measurement(entry.total),used=measurement(entry.used)
  return total===null || used===null || total===0 ? null : used/total*100
}
export function machineDetailSize(value:unknown) {
  const number=measurement(value)
  return number===null ? '—' : formatTraffic(number)
}
export function machineDetailRate(value:unknown) {
  const number=measurement(value)
  return number===null ? '—' : formatTraffic(number)+'/s'
}
export function machineDetailTime(value:unknown) {
  const time=machineTimestamp(value)
  if (time===null) return '未上报'
  const date=new Date(time)
  return Number.isFinite(date.getTime()) ? date.toLocaleString('zh-CN') : '未上报'
}
export function machineDetailAddress(node:Pick<NodeItem,'host'|'port'>) {
  if (!node.host) return '未配置'
  const host=node.host.includes(':') && !node.host.startsWith('[') ? '['+node.host+']' : node.host
  return node.port ? host+':'+node.port : host
}

/** Action result snippets should not disclose arbitrary nested key material. */
export function machineActionSummary(value:unknown) {
  if (value == null) return '—'
  const seen=new WeakSet<object>()
  const visit=(input:unknown,depth:number):unknown => {
    if (depth>5) return '…'
    if (!input || typeof input!=='object') return input
    if (seen.has(input)) return '[循环引用]'
    seen.add(input)
    if (Array.isArray(input)) return input.slice(0,12).map(x=>visit(x,depth+1))
    return Object.fromEntries(Object.entries(input).slice(0,24).map(([key,v])=>[
      key,/(token|secret|password|private.?key|api.?key|credential|authorization|cert|install.?command)/i.test(key)
        ? '[已隐藏]' : visit(v,depth+1)]))
  }
  try {
    const text=JSON.stringify(visit(value,0))
    return text.length>90 ? text.slice(0,87)+'…' : text
  } catch {return '—'}
}
