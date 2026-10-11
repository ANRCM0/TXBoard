import type { NodeItem } from '../../api/server'

export function nodeStatus(node: Pick<NodeItem,'enabled'|'online'>): {
  label: string
  tone: 'success'|'warning'|'neutral'
} {
  if (node.enabled === false) return {label:'已禁用',tone:'neutral'}
  if (node.online === true || node.online === 1) return {label:'在线',tone:'success'}
  if (node.online === false || node.online === 0) return {label:'离线',tone:'warning'}
  return {label:'未知',tone:'neutral'}
}

/** Node hostnames may contain IPv6; display brackets before appending a port. */
export function nodeConnectionLabel(node: Pick<NodeItem,'host'|'port'>): string {
  const host = typeof node.host === 'string' ? node.host.trim() : ''
  if (!host) return '未设置地址'
  const formattedHost = host.includes(':') && !host.startsWith('[') ? '[' + host + ']' : host
  return node.port == null || node.port === 0 ? formattedHost : formattedHost + ':' + node.port
}
