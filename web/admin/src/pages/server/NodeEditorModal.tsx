import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import type {
  GroupItem,
  MachineItem,
  NodeItem,
  NodeProtocolType,
  RouteItem,
} from '../../api/server'
import { Modal } from '../../components/ui/Modal'

const PROTOCOLS: Array<{ value: NodeProtocolType; label: string }> = [
  { value: 'shadowsocks', label: 'Shadowsocks' },
  { value: 'vless', label: 'VLESS' },
  { value: 'vmess', label: 'VMess' },
  { value: 'trojan', label: 'Trojan' },
  { value: 'hysteria', label: 'Hysteria 2' },
  { value: 'tuic', label: 'TUIC v5' },
  { value: 'anytls', label: 'AnyTLS' },
  { value: 'socks', label: 'SOCKS5' },
  { value: 'http', label: 'HTTP Proxy' },
  { value: 'naive', label: 'NaiveProxy' },
  { value: 'mieru', label: 'Mieru' },
]

const SS_CIPHERS = [
  'aes-128-gcm',
  'aes-256-gcm',
  'chacha20-ietf-poly1305',
  '2022-blake3-aes-128-gcm',
  '2022-blake3-aes-256-gcm',
  '2022-blake3-chacha20-poly1305',
]

const ANYTLS_PADDING = [
  'stop=8',
  '0=30-30',
  '1=100-400',
  '2=400-500,c,500-1000,c,500-1000,c,500-1000,c,500-1000',
  '3=9-9,500-1000',
  '4=500-1000',
  '5=500-1000',
  '6=500-1000',
  '7=500-1000',
]

type JsonValue = Record<string, unknown> | unknown[]

type Draft = {
  name: string
  type: NodeProtocolType
  host: string
  port: string
  serverPort: string
  rate: string
  transferEnable: string
  parentId: string
  machineId: string
  show: boolean
  enabled: boolean
  groupIds: number[]
  routeIds: number[]
  tags: string
  rateTimeEnable: boolean
  rateTimeRanges: JsonValue
  excludes: JsonValue
  ips: JsonValue
  customOutbounds: JsonValue
  customRoutes: JsonValue
  certConfig: JsonValue
  protocolSettings: Record<string, unknown>
}

function tlsObject() {
  return {
    server_name: '',
    allow_insecure: false,
    ech: {
      enabled: false,
      config: '',
      query_server_name: '',
      key: '',
      key_path: '',
      config_path: '',
    },
  }
}

function multiplexObject() {
  return {
    enabled: false,
    protocol: 'yamux',
    max_connections: null,
    min_streams: null,
    max_streams: null,
    padding: false,
    brutal: { enabled: false, up_mbps: null, down_mbps: null },
  }
}

function utlsObject() {
  return { enabled: false, fingerprint: 'chrome' }
}

function realityObject() {
  return {
    server_name: '',
    server_port: 443,
    public_key: '',
    private_key: '',
    short_id: '',
    allow_insecure: false,
  }
}

function defaultProtocolSettings(type: NodeProtocolType): Record<string, unknown> {
  switch (type) {
    case 'shadowsocks':
      return {
        cipher: 'aes-128-gcm',
        obfs: '',
        obfs_settings: { host: '', path: '' },
        plugin: '',
        plugin_opts: '',
      }
    case 'vmess':
      return {
        tls: 0,
        network: 'tcp',
        network_settings: {},
        rules: [],
        tls_settings: tlsObject(),
        multiplex: multiplexObject(),
        utls: utlsObject(),
      }
    case 'vless':
      return {
        tls: 0,
        network: 'tcp',
        network_settings: {},
        flow: '',
        encryption: { enabled: false, encryption: '', decryption: '' },
        tls_settings: tlsObject(),
        reality_settings: realityObject(),
        multiplex: multiplexObject(),
        utls: utlsObject(),
      }
    case 'trojan':
      return {
        tls: 1,
        network: 'tcp',
        network_settings: {},
        server_name: '',
        allow_insecure: false,
        tls_settings: tlsObject(),
        reality_settings: realityObject(),
        multiplex: multiplexObject(),
        utls: utlsObject(),
      }
    case 'hysteria':
      return {
        version: 2,
        alpn: 'h3',
        bandwidth: { up: null, down: null },
        obfs: { open: false, type: 'salamander', password: '' },
        tls: tlsObject(),
        hop_interval: null,
      }
    case 'tuic':
      return {
        version: 5,
        congestion_control: 'cubic',
        alpn: ['h3'],
        udp_relay_mode: 'native',
        tls: tlsObject(),
      }
    case 'anytls':
      return { alpn: 'h2,http/1.1', padding_scheme: ANYTLS_PADDING, tls: tlsObject() }
    case 'socks':
      return { tls: 0, tls_settings: tlsObject() }
    case 'naive':
    case 'http':
      return { tls: 1, tls_settings: tlsObject() }
    case 'mieru':
      return { transport: 'TCP', traffic_pattern: '', multiplex: multiplexObject() }
  }
}

function canonicalType(value: unknown): NodeProtocolType {
  const raw = String(value || 'shadowsocks').toLowerCase()
  if (raw === 'hysteria2') return 'hysteria'
  if (raw === 'v2ray') return 'vmess'
  return PROTOCOLS.some((item) => item.value === raw) ? (raw as NodeProtocolType) : 'shadowsocks'
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value)
}

function mergeDeep(base: Record<string, unknown>, extra: unknown): Record<string, unknown> {
  if (!isRecord(extra)) return base
  const result: Record<string, unknown> = { ...base }
  for (const [key, value] of Object.entries(extra)) {
    result[key] = isRecord(value) && isRecord(result[key])
      ? mergeDeep(result[key] as Record<string, unknown>, value)
      : value
  }
  return result
}

function getAt(source: Record<string, unknown>, path: string, fallback: unknown = ''): unknown {
  let current: unknown = source
  for (const key of path.split('.')) {
    if (!isRecord(current) || !(key in current)) return fallback
    current = current[key]
  }
  return current ?? fallback
}

function setAt(source: Record<string, unknown>, path: string, value: unknown): Record<string, unknown> {
  const keys = path.split('.')
  const result: Record<string, unknown> = { ...source }
  let cursor = result
  keys.forEach((key, index) => {
    if (index === keys.length - 1) {
      cursor[key] = value
      return
    }
    const next = isRecord(cursor[key]) ? { ...(cursor[key] as Record<string, unknown>) } : {}
    cursor[key] = next
    cursor = next
  })
  return result
}

function stringValue(value: unknown) {
  return value === null || value === undefined ? '' : String(value)
}

function numberOrNull(value: string) {
  if (value.trim() === '') return null
  const number = Number(value)
  return Number.isFinite(number) ? number : null
}

function draftFromNode(node?: NodeItem | null): Draft {
  const type = canonicalType(node?.type)
  return {
    name: stringValue(node?.name),
    type,
    host: stringValue(node?.host),
    port: stringValue(node?.port ?? ''),
    serverPort: stringValue(node?.server_port ?? ''),
    rate: stringValue(node?.rate ?? 1),
    transferEnable: stringValue(node?.transfer_enable ?? 0),
    parentId: stringValue(node?.parent_id ?? ''),
    machineId: stringValue(node?.machine_id ?? ''),
    show: node?.show === undefined ? true : Boolean(node.show),
    enabled: node?.enabled === undefined ? true : Boolean(node.enabled),
    groupIds: Array.isArray(node?.group_ids) ? node.group_ids.map(Number) : [],
    routeIds: Array.isArray(node?.route_ids) ? node.route_ids.map(Number) : [],
    tags: Array.isArray(node?.tags) ? node.tags.join(', ') : '',
    rateTimeEnable: Boolean(node?.rate_time_enable),
    rateTimeRanges: Array.isArray(node?.rate_time_ranges) ? node.rate_time_ranges : [],
    excludes: Array.isArray(node?.excludes) ? node.excludes : [],
    ips: Array.isArray(node?.ips) ? node.ips : [],
    customOutbounds: Array.isArray(node?.custom_outbounds) ? node.custom_outbounds : [],
    customRoutes: Array.isArray(node?.custom_routes) ? node.custom_routes : [],
    certConfig: (isRecord(node?.cert_config) || Array.isArray(node?.cert_config)) ? node.cert_config : [],
    protocolSettings: mergeDeep(defaultProtocolSettings(type), node?.protocol_settings),
  }
}

function JsonField({
  label,
  value,
  onChange,
  arrayOnly = false,
}: {
  label: string
  value: JsonValue | unknown
  onChange: (value: JsonValue) => void
  arrayOnly?: boolean
}) {
  const format = (input: unknown) => JSON.stringify(input ?? (arrayOnly ? [] : {}), null, 2)
  const [text, setText] = useState(format(value))

  useEffect(() => setText(format(value)), [value])

  const commit = () => {
    try {
      const parsed = text.trim() ? JSON.parse(text) : (arrayOnly ? [] : {})
      if (arrayOnly && !Array.isArray(parsed)) {
        toast.error(label + ' 必须是 JSON 数组')
        return
      }
      if (!arrayOnly && !Array.isArray(parsed) && !isRecord(parsed)) {
        toast.error(label + ' 必须是 JSON 对象或数组')
        return
      }
      onChange(parsed)
    } catch {
      toast.error(label + ' JSON 格式不正确')
    }
  }

  return <label className="field full">
    <span>{label}</span>
    <textarea value={text} onChange={(event) => setText(event.target.value)} onBlur={commit} spellCheck={false} />
  </label>
}

function selectedNumbers(event: React.ChangeEvent<HTMLSelectElement>) {
  return Array.from(event.target.selectedOptions).map((option) => Number(option.value))
}

export function NodeEditorModal({
  open,
  node,
  nodes,
  machines,
  groups,
  routes,
  saving,
  onClose,
  onSubmit,
}: {
  open: boolean
  node?: NodeItem | null
  nodes: NodeItem[]
  machines: MachineItem[]
  groups: GroupItem[]
  routes: RouteItem[]
  saving: boolean
  onClose: () => void
  onSubmit: (payload: Partial<NodeItem>) => void
}) {
  const [draft, setDraft] = useState<Draft>(() => draftFromNode(node))

  useEffect(() => {
    if (open) setDraft(draftFromNode(node))
  }, [open, node?.id])

  const updateProtocol = (path: string, value: unknown) => {
    setDraft((current) => ({
      ...current,
      protocolSettings: setAt(current.protocolSettings, path, value),
    }))
  }

  const p = (path: string, fallback: unknown = '') => getAt(draft.protocolSettings, path, fallback)
  const checked = (path: string) => Boolean(p(path, false))

  const setType = (type: NodeProtocolType) => {
    setDraft((current) => ({
      ...current,
      type,
      protocolSettings: defaultProtocolSettings(type),
    }))
  }

  const submit = () => {
    const port = Number(draft.port)
    const serverPort = Number(draft.serverPort)
    const rate = Number(draft.rate)
    const transferEnable = Number(draft.transferEnable || 0)

    if (!draft.name.trim()) return toast.error('请输入节点名称')
    if (!draft.host.trim()) return toast.error('请输入节点地址')
    if (!Number.isInteger(port) || port < 1 || port > 65535) return toast.error('连接端口必须在 1-65535 之间')
    if (!Number.isInteger(serverPort) || serverPort < 1 || serverPort > 65535) return toast.error('后端服务端口必须在 1-65535 之间')
    if (!Number.isFinite(rate) || rate < 0) return toast.error('倍率必须是大于等于 0 的数字')
    if (!Number.isInteger(transferEnable) || transferEnable < 0) return toast.error('流量上限必须是大于等于 0 的整数')
    if (draft.type === 'shadowsocks' && !stringValue(p('cipher')).trim()) return toast.error('请选择 Shadowsocks 加密方式')
    if (['vmess', 'vless', 'trojan'].includes(draft.type) && !stringValue(p('network')).trim()) return toast.error('请输入传输协议')

    const payload: Partial<NodeItem> = {
      ...(node?.id ? { id: node.id } : {}),
      name: draft.name.trim(),
      type: draft.type,
      host: draft.host.trim(),
      port,
      server_port: serverPort,
      rate,
      transfer_enable: transferEnable,
      parent_id: draft.parentId ? Number(draft.parentId) : null,
      machine_id: draft.machineId ? Number(draft.machineId) : null,
      show: draft.show ? 1 : 0,
      enabled: draft.enabled,
      group_ids: draft.groupIds,
      route_ids: draft.routeIds,
      tags: draft.tags.split(',').map((item) => item.trim()).filter(Boolean),
      rate_time_enable: draft.rateTimeEnable,
      rate_time_ranges: draft.rateTimeEnable && Array.isArray(draft.rateTimeRanges)
        ? draft.rateTimeRanges as Array<{ start: string; end: string; rate: number }>
        : [],
      excludes: Array.isArray(draft.excludes) ? draft.excludes : [],
      ips: Array.isArray(draft.ips) ? draft.ips : [],
      custom_outbounds: Array.isArray(draft.customOutbounds) ? draft.customOutbounds : [],
      custom_routes: Array.isArray(draft.customRoutes) ? draft.customRoutes : [],
      cert_config: draft.certConfig,
      protocol_settings: draft.protocolSettings,
    }

    onSubmit(payload)
  }

  const renderTlsSettings = (root: 'tls_settings' | 'tls') => <div className="node-form-grid">
    <label className="field">
      <span>SNI / Server Name</span>
      <input value={stringValue(p(root + '.server_name'))} onChange={(event) => updateProtocol(root + '.server_name', event.target.value)} placeholder="example.com" />
    </label>
    <label className="check-field">
      <input type="checkbox" checked={checked(root + '.allow_insecure')} onChange={(event) => updateProtocol(root + '.allow_insecure', event.target.checked)} />
      跳过证书验证
    </label>
    <label className="check-field full">
      <input type="checkbox" checked={checked(root + '.ech.enabled')} onChange={(event) => updateProtocol(root + '.ech.enabled', event.target.checked)} />
      启用 ECH
    </label>
    {checked(root + '.ech.enabled') && <>
      <label className="field full"><span>ECH Config</span><textarea value={stringValue(p(root + '.ech.config'))} onChange={(event) => updateProtocol(root + '.ech.config', event.target.value)} /></label>
      <label className="field"><span>ECH Query Server Name</span><input value={stringValue(p(root + '.ech.query_server_name'))} onChange={(event) => updateProtocol(root + '.ech.query_server_name', event.target.value)} /></label>
      <label className="field"><span>ECH Key Path</span><input value={stringValue(p(root + '.ech.key_path'))} onChange={(event) => updateProtocol(root + '.ech.key_path', event.target.value)} /></label>
      <label className="field full"><span>ECH Key</span><textarea value={stringValue(p(root + '.ech.key'))} onChange={(event) => updateProtocol(root + '.ech.key', event.target.value)} /></label>
      <label className="field full"><span>ECH Config Path</span><input value={stringValue(p(root + '.ech.config_path'))} onChange={(event) => updateProtocol(root + '.ech.config_path', event.target.value)} /></label>
    </>}
  </div>

  const renderReality = () => <div className="node-form-grid">
    <label className="field"><span>Reality SNI</span><input value={stringValue(p('reality_settings.server_name'))} onChange={(event) => updateProtocol('reality_settings.server_name', event.target.value)} /></label>
    <label className="field"><span>Reality 目标端口</span><input type="number" value={stringValue(p('reality_settings.server_port', 443))} onChange={(event) => updateProtocol('reality_settings.server_port', Number(event.target.value))} /></label>
    <label className="field full"><span>Reality Public Key</span><input value={stringValue(p('reality_settings.public_key'))} onChange={(event) => updateProtocol('reality_settings.public_key', event.target.value)} /></label>
    <label className="field full"><span>Reality Private Key</span><input value={stringValue(p('reality_settings.private_key'))} onChange={(event) => updateProtocol('reality_settings.private_key', event.target.value)} /></label>
    <label className="field"><span>Reality Short ID</span><input value={stringValue(p('reality_settings.short_id'))} onChange={(event) => updateProtocol('reality_settings.short_id', event.target.value)} /></label>
    <label className="check-field"><input type="checkbox" checked={checked('reality_settings.allow_insecure')} onChange={(event) => updateProtocol('reality_settings.allow_insecure', event.target.checked)} />跳过证书验证</label>
  </div>

  const renderUtls = () => <div className="node-form-grid">
    <label className="check-field"><input type="checkbox" checked={checked('utls.enabled')} onChange={(event) => updateProtocol('utls.enabled', event.target.checked)} />启用 uTLS</label>
    <label className="field"><span>uTLS 指纹</span><input value={stringValue(p('utls.fingerprint', 'chrome'))} onChange={(event) => updateProtocol('utls.fingerprint', event.target.value)} placeholder="chrome" /></label>
  </div>

  const renderMultiplex = () => <div className="node-form-grid">
    <label className="check-field"><input type="checkbox" checked={checked('multiplex.enabled')} onChange={(event) => updateProtocol('multiplex.enabled', event.target.checked)} />启用 Multiplex</label>
    <label className="field"><span>复用协议</span><input value={stringValue(p('multiplex.protocol', 'yamux'))} onChange={(event) => updateProtocol('multiplex.protocol', event.target.value)} /></label>
    <label className="field"><span>最大连接数</span><input type="number" value={stringValue(p('multiplex.max_connections'))} onChange={(event) => updateProtocol('multiplex.max_connections', numberOrNull(event.target.value))} /></label>
    <label className="field"><span>最小流数</span><input type="number" value={stringValue(p('multiplex.min_streams'))} onChange={(event) => updateProtocol('multiplex.min_streams', numberOrNull(event.target.value))} /></label>
    <label className="field"><span>最大流数</span><input type="number" value={stringValue(p('multiplex.max_streams'))} onChange={(event) => updateProtocol('multiplex.max_streams', numberOrNull(event.target.value))} /></label>
    <label className="check-field"><input type="checkbox" checked={checked('multiplex.padding')} onChange={(event) => updateProtocol('multiplex.padding', event.target.checked)} />Padding</label>
    <label className="check-field full"><input type="checkbox" checked={checked('multiplex.brutal.enabled')} onChange={(event) => updateProtocol('multiplex.brutal.enabled', event.target.checked)} />启用 Brutal</label>
    {checked('multiplex.brutal.enabled') && <>
      <label className="field"><span>Brutal 上行 Mbps</span><input type="number" value={stringValue(p('multiplex.brutal.up_mbps'))} onChange={(event) => updateProtocol('multiplex.brutal.up_mbps', numberOrNull(event.target.value))} /></label>
      <label className="field"><span>Brutal 下行 Mbps</span><input type="number" value={stringValue(p('multiplex.brutal.down_mbps'))} onChange={(event) => updateProtocol('multiplex.brutal.down_mbps', numberOrNull(event.target.value))} /></label>
    </>}
  </div>

  const renderXrayLike = () => {
    const supportsReality = draft.type === 'vless' || draft.type === 'trojan'
    const tlsMode = Number(p('tls', draft.type === 'trojan' ? 1 : 0))
    return <>
      <div className="node-form-grid">
        <label className="field">
          <span>TLS 模式</span>
          <select value={tlsMode} onChange={(event) => updateProtocol('tls', Number(event.target.value))}>
            <option value={0}>关闭</option>
            <option value={1}>TLS</option>
            {supportsReality && <option value={2}>Reality</option>}
          </select>
        </label>
        <label className="field">
          <span>传输协议</span>
          <input list="node-network-types" value={stringValue(p('network', 'tcp'))} onChange={(event) => updateProtocol('network', event.target.value)} />
          <datalist id="node-network-types">
            <option value="tcp" /><option value="ws" /><option value="grpc" /><option value="h2" /><option value="kcp" /><option value="httpupgrade" /><option value="xhttp" />
          </datalist>
        </label>
        {draft.type === 'vless' && <label className="field"><span>Flow</span><input value={stringValue(p('flow'))} onChange={(event) => updateProtocol('flow', event.target.value)} placeholder="xtls-rprx-vision" /></label>}
        <JsonField label="Network Settings" value={p('network_settings', {})} onChange={(value) => updateProtocol('network_settings', value)} />
        {draft.type === 'vmess' && <JsonField label="VMess Rules" value={p('rules', [])} arrayOnly onChange={(value) => updateProtocol('rules', value)} />}
      </div>
      {tlsMode === 1 && renderTlsSettings('tls_settings')}
      {tlsMode === 2 && supportsReality && renderReality()}
      {renderUtls()}
      {renderMultiplex()}
      {draft.type === 'vless' && <div className="node-form-grid">
        <label className="check-field full"><input type="checkbox" checked={checked('encryption.enabled')} onChange={(event) => updateProtocol('encryption.enabled', event.target.checked)} />启用 VLESS Encryption</label>
        {checked('encryption.enabled') && <>
          <label className="field"><span>Encryption / Client Public Key</span><input value={stringValue(p('encryption.encryption'))} onChange={(event) => updateProtocol('encryption.encryption', event.target.value)} /></label>
          <label className="field"><span>Decryption / Server Private Key</span><input value={stringValue(p('encryption.decryption'))} onChange={(event) => updateProtocol('encryption.decryption', event.target.value)} /></label>
        </>}
      </div>}
    </>
  }

  const renderProtocolFields = () => {
    switch (draft.type) {
      case 'shadowsocks':
        return <div className="node-form-grid">
          <label className="field"><span>加密方式</span><select value={stringValue(p('cipher'))} onChange={(event) => updateProtocol('cipher', event.target.value)}>{SS_CIPHERS.map((cipher) => <option key={cipher}>{cipher}</option>)}</select></label>
          <label className="field"><span>Obfs</span><input value={stringValue(p('obfs'))} onChange={(event) => updateProtocol('obfs', event.target.value)} placeholder="http / tls" /></label>
          <label className="field"><span>Obfs Host</span><input value={stringValue(p('obfs_settings.host'))} onChange={(event) => updateProtocol('obfs_settings.host', event.target.value)} /></label>
          <label className="field"><span>Obfs Path</span><input value={stringValue(p('obfs_settings.path'))} onChange={(event) => updateProtocol('obfs_settings.path', event.target.value)} /></label>
          <label className="field"><span>Plugin</span><input value={stringValue(p('plugin'))} onChange={(event) => updateProtocol('plugin', event.target.value)} placeholder="v2ray-plugin / shadow-tls / restls" /></label>
          <label className="field"><span>Plugin Options</span><input value={stringValue(p('plugin_opts'))} onChange={(event) => updateProtocol('plugin_opts', event.target.value)} /></label>
        </div>
      case 'vmess':
      case 'vless':
      case 'trojan':
        return renderXrayLike()
      case 'hysteria':
        return <>
          <div className="node-form-grid">
            <label className="field"><span>版本</span><input type="number" value={stringValue(p('version', 2))} onChange={(event) => updateProtocol('version', Number(event.target.value))} /></label>
            <label className="field"><span>ALPN</span><input value={stringValue(p('alpn', 'h3'))} onChange={(event) => updateProtocol('alpn', event.target.value)} /></label>
            <label className="field"><span>上行带宽 Mbps</span><input type="number" value={stringValue(p('bandwidth.up'))} onChange={(event) => updateProtocol('bandwidth.up', numberOrNull(event.target.value))} /></label>
            <label className="field"><span>下行带宽 Mbps</span><input type="number" value={stringValue(p('bandwidth.down'))} onChange={(event) => updateProtocol('bandwidth.down', numberOrNull(event.target.value))} /></label>
            <label className="field"><span>Hop Interval</span><input type="number" value={stringValue(p('hop_interval'))} onChange={(event) => updateProtocol('hop_interval', numberOrNull(event.target.value))} /></label>
            <label className="check-field"><input type="checkbox" checked={checked('obfs.open')} onChange={(event) => updateProtocol('obfs.open', event.target.checked)} />启用 Obfs</label>
            {checked('obfs.open') && <>
              <label className="field"><span>Obfs 类型</span><input value={stringValue(p('obfs.type', 'salamander'))} onChange={(event) => updateProtocol('obfs.type', event.target.value)} /></label>
              <label className="field"><span>Obfs 密码</span><input value={stringValue(p('obfs.password'))} onChange={(event) => updateProtocol('obfs.password', event.target.value)} /></label>
            </>}
          </div>
          {renderTlsSettings('tls')}
        </>
      case 'tuic':
        return <>
          <div className="node-form-grid">
            <label className="field"><span>版本</span><input type="number" value={stringValue(p('version', 5))} onChange={(event) => updateProtocol('version', Number(event.target.value))} /></label>
            <label className="field"><span>拥塞控制</span><select value={stringValue(p('congestion_control', 'cubic'))} onChange={(event) => updateProtocol('congestion_control', event.target.value)}><option value="cubic">cubic</option><option value="bbr">bbr</option><option value="new_reno">new_reno</option></select></label>
            <label className="field"><span>ALPN（逗号分隔）</span><input value={(Array.isArray(p('alpn')) ? p('alpn') as unknown[] : []).join(', ')} onChange={(event) => updateProtocol('alpn', event.target.value.split(',').map((item) => item.trim()).filter(Boolean))} /></label>
            <label className="field"><span>UDP Relay Mode</span><input value={stringValue(p('udp_relay_mode', 'native'))} onChange={(event) => updateProtocol('udp_relay_mode', event.target.value)} /></label>
          </div>
          {renderTlsSettings('tls')}
        </>
      case 'anytls':
        return <>
          <div className="node-form-grid">
            <label className="field full"><span>ALPN</span><input value={stringValue(p('alpn', 'h2,http/1.1'))} onChange={(event) => updateProtocol('alpn', event.target.value)} /></label>
            <label className="field full"><span>Padding Scheme（每行一项）</span><textarea value={(Array.isArray(p('padding_scheme')) ? p('padding_scheme') as unknown[] : []).join('\n')} onChange={(event) => updateProtocol('padding_scheme', event.target.value.split('\n').map((item) => item.trim()).filter(Boolean))} /></label>
          </div>
          {renderTlsSettings('tls')}
        </>
      case 'socks':
      case 'naive':
      case 'http': {
        const tlsMode = Number(p('tls', draft.type === 'socks' ? 0 : 1))
        return <>
          <div className="node-form-grid">
            <label className="field"><span>TLS</span><select value={tlsMode} onChange={(event) => updateProtocol('tls', Number(event.target.value))}><option value={0}>关闭</option><option value={1}>启用</option></select></label>
          </div>
          {tlsMode === 1 && renderTlsSettings('tls_settings')}
        </>
      }
      case 'mieru':
        return <>
          <div className="node-form-grid">
            <label className="field"><span>Transport</span><select value={stringValue(p('transport', 'TCP'))} onChange={(event) => updateProtocol('transport', event.target.value)}><option value="TCP">TCP</option><option value="UDP">UDP</option></select></label>
            <label className="field"><span>Traffic Pattern</span><input value={stringValue(p('traffic_pattern'))} onChange={(event) => updateProtocol('traffic_pattern', event.target.value)} /></label>
          </div>
          {renderMultiplex()}
        </>
    }
  }

  return <Modal open={open} title={node ? '编辑节点' : '添加节点'} onClose={onClose} wide>
    <div className="form-stack">
      <section className="node-form-section">
        <h4>基础配置 <span className="node-protocol-badge">连接与节点控制</span></h4>
        <div className="node-form-grid">
          <label className="field"><span>节点名称</span><input value={draft.name} onChange={(event) => setDraft({ ...draft, name: event.target.value })} /></label>
          <label className="field"><span>协议类型</span><select value={draft.type} onChange={(event) => setType(event.target.value as NodeProtocolType)}>{PROTOCOLS.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}</select></label>
          <label className="field"><span>连接地址</span><input value={draft.host} onChange={(event) => setDraft({ ...draft, host: event.target.value })} placeholder="node.example.com / IP" /></label>
          <label className="field"><span>连接端口</span><input type="number" min={1} max={65535} value={draft.port} onChange={(event) => setDraft({ ...draft, port: event.target.value })} /></label>
          <label className="field"><span>后端服务端口</span><input type="number" min={1} max={65535} value={draft.serverPort} onChange={(event) => setDraft({ ...draft, serverPort: event.target.value })} /></label>
          <label className="field"><span>倍率</span><input type="number" min={0} step="0.01" value={draft.rate} onChange={(event) => setDraft({ ...draft, rate: event.target.value })} /></label>
          <label className="field"><span>流量上限（Bytes，0 不限制）</span><input type="number" min={0} value={draft.transferEnable} onChange={(event) => setDraft({ ...draft, transferEnable: event.target.value })} /></label>
          <label className="field"><span>所属机器</span><select value={draft.machineId} onChange={(event) => setDraft({ ...draft, machineId: event.target.value })}><option value="">不绑定</option>{machines.map((machine) => <option key={machine.id} value={machine.id}>{machine.name || 'Machine ' + machine.id}</option>)}</select></label>
          <label className="field"><span>父节点</span><select value={draft.parentId} onChange={(event) => setDraft({ ...draft, parentId: event.target.value })}><option value="">无</option>{nodes.filter((item) => item.id !== node?.id).map((item) => <option key={item.id} value={item.id}>{item.name || 'Node ' + item.id}</option>)}</select></label>
        </div>
        <div className="node-form-checks">
          <label className="check-field"><input type="checkbox" checked={draft.show} onChange={(event) => setDraft({ ...draft, show: event.target.checked })} />在订阅中显示</label>
          <label className="check-field"><input type="checkbox" checked={draft.enabled} onChange={(event) => setDraft({ ...draft, enabled: event.target.checked })} />启用节点</label>
        </div>
      </section>

      <section className="node-form-section">
        <h4>权限与路由</h4>
        <div className="node-form-grid">
          <label className="field"><span>权限组（可多选）</span><select className="node-form-multiselect" multiple value={draft.groupIds.map(String)} onChange={(event) => setDraft({ ...draft, groupIds: selectedNumbers(event) })}>{groups.map((group) => <option key={group.id} value={group.id}>{group.name || 'Group ' + group.id}</option>)}</select></label>
          <label className="field"><span>路由组（可多选）</span><select className="node-form-multiselect" multiple value={draft.routeIds.map(String)} onChange={(event) => setDraft({ ...draft, routeIds: selectedNumbers(event) })}>{routes.map((route) => <option key={route.id} value={route.id}>{route.remarks || 'Route ' + route.id}</option>)}</select></label>
          <label className="field full"><span>标签（逗号分隔）</span><input value={draft.tags} onChange={(event) => setDraft({ ...draft, tags: event.target.value })} /></label>
        </div>
      </section>

      <section className="node-form-section">
        <h4>协议配置 <span className="node-protocol-badge">{PROTOCOLS.find((item) => item.value === draft.type)?.label}</span></h4>
        {renderProtocolFields()}
      </section>

      <details className="node-form-section">
        <summary><strong>高级节点模型</strong> <span className="node-form-hint">时间倍率、IP/排除规则、自定义出站与路由、证书配置</span></summary>
        <div className="node-form-checks">
          <label className="check-field"><input type="checkbox" checked={draft.rateTimeEnable} onChange={(event) => setDraft({ ...draft, rateTimeEnable: event.target.checked })} />启用分时倍率</label>
        </div>
        {draft.rateTimeEnable && <JsonField label="分时倍率 rate_time_ranges" value={draft.rateTimeRanges} arrayOnly onChange={(value) => setDraft({ ...draft, rateTimeRanges: value })} />}
        <JsonField label="Excludes" value={draft.excludes} arrayOnly onChange={(value) => setDraft({ ...draft, excludes: value })} />
        <JsonField label="IPs" value={draft.ips} arrayOnly onChange={(value) => setDraft({ ...draft, ips: value })} />
        <JsonField label="Custom Outbounds" value={draft.customOutbounds} arrayOnly onChange={(value) => setDraft({ ...draft, customOutbounds: value })} />
        <JsonField label="Custom Routes" value={draft.customRoutes} arrayOnly onChange={(value) => setDraft({ ...draft, customRoutes: value })} />
        <JsonField label="Certificate Config" value={draft.certConfig} onChange={(value) => setDraft({ ...draft, certConfig: value })} />
      </details>

      <div className="modal-actions">
        <button className="button" onClick={onClose}>取消</button>
        <button className="button primary" disabled={saving} onClick={submit}>{saving ? '保存中…' : node ? '保存修改' : '创建节点'}</button>
      </div>
    </div>
  </Modal>
}
