import type { MachineItem, NodeItem } from '../../api/server'
import { formatTraffic } from '../../lib/dashboardVisuals'
import { machineTimestamp } from './machineOpsModel'

export function nodeDetailPresence(node: Pick<NodeItem,'enabled'|'online'>) {
  if (node.enabled === false) return {label:'已停用', tone:'neutral'} as const
  if (node.online === true || node.online === 1) return {label:'在线', tone:'success'} as const
  if (node.online === false || node.online === 0) return {label:'离线', tone:'warning'} as const
  return {label:'状态未知', tone:'neutral'} as const
}

export function nodeDetailMachineStatus(machine: Pick<MachineItem,'last_seen_at'|'is_active'> | undefined, now=Date.now()) {
  if (!machine) return '未关联机器'
  if (machine.is_active === false) return '机器已停用'
  const seen = machineTimestamp(machine.last_seen_at)
  if (seen === null) return '机器未连接'
  return now-seen < 180_000 ? '机器在线' : '机器离线'
}

export function nodeDetailAddress(node: Pick<NodeItem,'host'|'port'>) {
  const host = typeof node.host === 'string' ? node.host.trim() : ''
  if (!host) return '未配置'
  const h = host.includes(':') && !host.startsWith('[') ? '['+host+']' : host
  return node.port == null || node.port === 0 ? h : h+':'+node.port
}

function validTraffic(value: unknown): number | null {
  if (value === null || value === undefined || typeof value === 'boolean' ||
    (typeof value === 'string' && !value.trim())) return null
  const n = Number(value)
  return Number.isFinite(n) && n>=0 ? n : null
}
export function nodeDetailTraffic(node: NodeItem) {
  const upload = validTraffic(node.u)
  const download = validTraffic(node.d)
  const limit = validTraffic(node.transfer_enable)
  const used = upload === null && download === null ? null : (upload ?? 0)+(download ?? 0)
  if (used === null) return {value:'未上报',sub:limit !== null && limit>0 ? '额度 '+formatTraffic(limit) : '无流量记录'}
  return {
    value: limit !== null && limit>0 ? formatTraffic(used)+' / '+formatTraffic(limit) : formatTraffic(used),
    sub:limit !== null && limit>0 ? '剩余 '+formatTraffic(Math.max(0,limit-used)) : '未设置流量额度',
  }
}

/** Avoid untrusted diagnostic JSON leaking credentials through the activity table. */
export function redactActionResult(value: unknown): string {
  if (value === null || value === undefined) return '—'
  const seen = new WeakSet<object>()
  const scrub = (input: unknown, depth: number): unknown => {
    if (depth>6) return '…'
    if (input && typeof input==='object') {
      if (seen.has(input)) return '[循环引用]'
      seen.add(input)
      if (Array.isArray(input)) return input.slice(0,15).map(item=>scrub(item,depth+1))
      return Object.fromEntries(Object.entries(input).slice(0,25).map(([key,val]) =>
        [key,/(token|secret|password|private.?key|api.?key|credential|authorization|cert|install.?command)/i.test(key)
          ? '[已隐藏]' : scrub(val,depth+1)]))
    }
    return input
  }
  try {
    const serialized=JSON.stringify(scrub(value,0))
    return serialized.length>90 ? serialized.slice(0,87)+'…' : serialized
  } catch {
    return '—'
  }
}
