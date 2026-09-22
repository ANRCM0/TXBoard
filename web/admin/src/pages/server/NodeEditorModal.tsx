import { ChevronDown, Info } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { toast } from 'sonner'
import type {
  GroupItem,
  MachineItem,
  NodeItem,
  NodeProtocolType,
  ProtocolDefinitionMeta,
  RouteItem,
} from '../../api/server'
import { Modal } from '../../components/ui/Modal'
import { ProtocolSchemaForm } from './ProtocolSchemaForm'

type JsonValue = Record<string, unknown> | unknown[]
const GB = 1024 ** 3

const protocolColors: Record<string, string> = {
  shadowsocks: '#46a758',
  vmess: '#d63384',
  trojan: '#e9ad31',
  hysteria: '#4f7edb',
  vless: '#202124',
  tuic: '#08b45b',
  socks: '#2696df',
  naive: '#9c2bb3',
  http: '#ef5423',
  mieru: '#46a758',
  anytls: '#7654c9',
}

type Draft = {
  name: string
  type: NodeProtocolType
  code: string
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

function canonicalType(value: unknown, definitions: ProtocolDefinitionMeta[]): NodeProtocolType {
  const raw = String(value || '').toLowerCase()
  const normalized = raw === 'hysteria2' ? 'hysteria' : raw === 'v2ray' ? 'vmess' : raw
  if (normalized) return normalized
  return definitions[0]?.type || 'shadowsocks'
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

function protocolDefinition(type: NodeProtocolType, definitions: ProtocolDefinitionMeta[]) {
  return definitions.find((definition) => definition.type === type)
}

function protocolDefaults(type: NodeProtocolType, definitions: ProtocolDefinitionMeta[]): Record<string, unknown> {
  const managed = protocolDefinition(type, definitions)
  return managed && isRecord(managed.defaults) ? managed.defaults : {}
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

function transferToGb(value: unknown) {
  const bytes = Number(value || 0)
  if (!Number.isFinite(bytes) || bytes <= 0) return '0'
  return String(Number((bytes / GB).toFixed(2)))
}

function draftFromNode(node?: NodeItem | null, definitions: ProtocolDefinitionMeta[] = []): Draft {
  const type = node?.type ? canonicalType(node.type, definitions) : ''
  return {
    name: stringValue(node?.name),
    type,
    code: stringValue(node?.code),
    host: stringValue(node?.host),
    port: stringValue(node?.port ?? ''),
    serverPort: stringValue(node?.server_port ?? ''),
    rate: stringValue(node?.rate ?? 1),
    transferEnable: transferToGb(node?.transfer_enable),
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
    protocolSettings: mergeDeep(protocolDefaults(type, definitions), node?.protocol_settings),
  }
}

/**
 * Lets a JSON editor report whether it currently holds unparsable text, so a
 * submit can refuse to silently drop what the operator just typed.
 */
type JsonValidityReporter = (key: string, report: (() => boolean) | null) => void

function JsonField({
  label,
  value,
  onChange,
  arrayOnly = false,
  reportJsonValidity,
}: {
  label: string
  value: JsonValue | unknown
  onChange: (value: JsonValue) => void
  arrayOnly?: boolean
  reportJsonValidity?: JsonValidityReporter
}) {
  const format = (input: unknown) => JSON.stringify(input ?? (arrayOnly ? [] : {}), null, 2)
  const [text, setText] = useState(format(value))
  const [invalid, setInvalid] = useState(false)

  const parse = (raw: string): { ok: boolean; value?: unknown } => {
    try {
      const parsed = raw.trim() ? JSON.parse(raw) : (arrayOnly ? [] : {})
      if (arrayOnly && !Array.isArray(parsed)) return { ok: false }
      if (!arrayOnly && !Array.isArray(parsed) && !isRecord(parsed)) return { ok: false }
      return { ok: true, value: parsed }
    } catch {
      return { ok: false }
    }
  }

  // Re-format only when the value arrives from outside this editor (node load,
  // protocol switch). A value echoed back from our own commit keeps the text
  // exactly as typed, so typing never fights a pretty-printer.
  useEffect(() => {
    setText(previous => {
      const parsed = parse(previous)
      return parsed.ok && JSON.stringify(parsed.value) === JSON.stringify(value)
        ? previous
        : format(value)
    })
    setInvalid(false)
  }, [value])

  const commit = (raw: string, announce: boolean) => {
    setText(raw)
    const parsed = parse(raw)
    if (!parsed.ok) {
      setInvalid(true)
      if (announce) toast.error(label + ' JSON 格式不正确，请修正后再提交')
      return
    }
    setInvalid(false)
    onChange(parsed.value as JsonValue)
  }

  const invalidRef = useRef(invalid)
  invalidRef.current = invalid
  useEffect(() => {
    reportJsonValidity?.(label, () => invalidRef.current)
    return () => reportJsonValidity?.(label, null)
  }, [reportJsonValidity, label])

  return <label className="field full">
    <span>{label}</span>
    <textarea
      value={text}
      onChange={(event) => commit(event.target.value, false)}
      onBlur={() => commit(text, true)}
      spellCheck={false}
      aria-invalid={invalid || undefined}
      className={invalid ? 'is-invalid' : undefined}
    />
  </label>
}

function ProtocolPicker({
  value,
  definitions,
  onChange,
}: {
  value: NodeProtocolType
  definitions: ProtocolDefinitionMeta[]
  onChange: (type: NodeProtocolType) => void
}) {
  const selected = protocolDefinition(value, definitions)
  return <details className="node-protocol-picker">
    <summary>
      <span>{selected?.label || (definitions.length ? '选择协议类型' : '加载协议类型…')}</span>
      <ChevronDown size={16}/>
    </summary>
    {definitions.length ? <div className="node-protocol-options">
      {definitions.map((definition) => <button
        type="button"
        key={definition.type}
        className={definition.type === value ? 'active' : ''}
        onClick={(event) => {
          onChange(definition.type)
          event.currentTarget.closest('details')?.removeAttribute('open')
        }}
      >
        <i style={{ background: protocolColors[definition.type] || '#64748b' }}/>
        <span>{definition.label}</span>
      </button>)}
    </div> : null}
  </details>
}

function MultiPicker({
  label,
  placeholder,
  value,
  options,
  onChange,
}: {
  label: string
  placeholder: string
  value: number[]
  options: Array<{ value: number; label: string }>
  onChange: (value: number[]) => void
}) {
  const selected = options.filter((option) => value.includes(option.value))
  const toggle = (optionValue: number) => {
    onChange(value.includes(optionValue)
      ? value.filter((item) => item !== optionValue)
      : [...value, optionValue])
  }

  return <div className="node-editor-field node-editor-field-full">
    <div className="node-editor-label-row"><span>{label}</span><small>可多选</small></div>
    <details className="node-multi-picker">
      <summary>
        <span className={selected.length ? '' : 'placeholder'}>
          {selected.length ? selected.map((option) => option.label).join('、') : placeholder}
        </span>
        <ChevronDown size={16}/>
      </summary>
      <div className="node-multi-options">
        {options.length ? options.map((option) => <label key={option.value}>
          <input
            type="checkbox"
            checked={value.includes(option.value)}
            onChange={() => toggle(option.value)}
          />
          <span>{option.label}</span>
        </label>) : <p>暂无可选项</p>}
      </div>
    </details>
  </div>
}

export function NodeEditorModal({
  open,
  node,
  nodes,
  machines,
  groups,
  routes,
  protocolDefinitions,
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
  protocolDefinitions: ProtocolDefinitionMeta[]
  saving: boolean
  onClose: () => void
  onSubmit: (payload: Partial<NodeItem>) => void
}) {
  const [draft, setDraft] = useState<Draft>(() => draftFromNode(node, protocolDefinitions))
  const jsonValidity = useRef(new Map<string, () => boolean>())

  const reportJsonValidity = useCallback<JsonValidityReporter>((key, report) => {
    if (report) jsonValidity.current.set(key, report)
    else jsonValidity.current.delete(key)
  }, [])

  // Fields register on mount and unregister on unmount (the portal content is
  // torn down while closed), so reopening always starts from a clean registry.
  useEffect(() => {
    if (!open) return
    setDraft(draftFromNode(node, protocolDefinitions))
  }, [open, node?.id])

  useEffect(() => {
    if (!open || protocolDefinitions.length === 0) return

    setDraft((current) => {
      if (Object.keys(current.protocolSettings).length > 0) return current
      if (!node?.id && !current.type) return current

      const type = canonicalType(node?.type || current.type, protocolDefinitions)
      return {
        ...current,
        type,
        protocolSettings: mergeDeep(protocolDefaults(type, protocolDefinitions), node?.protocol_settings),
      }
    })
  }, [open, node?.id, node?.type, node?.protocol_settings, protocolDefinitions])

  const updateProtocol = (path: string, value: unknown) => {
    setDraft((current) => ({
      ...current,
      protocolSettings: setAt(current.protocolSettings, path, value),
    }))
  }

  const setType = (type: NodeProtocolType) => {
    setDraft((current) => ({
      ...current,
      type,
      protocolSettings: protocolDefaults(type, protocolDefinitions),
    }))
  }

  const submit = () => {
    const port = Number(draft.port)
    const serverPort = Number(draft.serverPort)
    const rate = Number(draft.rate)
    const transferLimitGb = Number(draft.transferEnable || 0)

    if (!draft.name.trim()) return toast.error('请输入节点名称')
    if (!draft.type) return toast.error('请选择协议类型')
    if (!draft.host.trim()) return toast.error('请输入节点地址')
    if (!Number.isInteger(port) || port < 1 || port > 65535) return toast.error('连接端口必须在 1-65535 之间')
    if (!Number.isInteger(serverPort) || serverPort < 1 || serverPort > 65535) return toast.error('后端服务端口必须在 1-65535 之间')
    if (!Number.isFinite(rate) || rate < 0) return toast.error('倍率必须是大于等于 0 的数字')
    if (!Number.isFinite(transferLimitGb) || transferLimitGb < 0) return toast.error('流量上限必须是大于等于 0 的数字')

    // Never discard freshly typed JSON: values are committed on blur, so an
    // unparsable field means the payload below would silently use stale data.
    const invalidJson = Array.from(jsonValidity.current.entries())
      .filter(([, isInvalid]) => isInvalid())
      .map(([label]) => label)
    if (invalidJson.length) {
      return toast.error('JSON 格式不正确：' + invalidJson.join('、') + '，请先修正后再提交')
    }

    const transferEnable = Math.round(transferLimitGb * GB)
    const payload: Partial<NodeItem> = {
      ...(node?.id ? { id: node.id } : {}),
      name: draft.name.trim(),
      type: draft.type,
      code: draft.code.trim() || null,
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

  const renderProtocolFields = () => {
    if (!draft.type) {
      return <div className="node-protocol-empty">
        <Info size={30}/>
        <span>请先选择协议类型</span>
      </div>
    }

    const managed = protocolDefinition(draft.type, protocolDefinitions)

    if (managed?.form_schema?.length) {
      return <ProtocolSchemaForm
        fields={managed.form_schema}
        value={draft.protocolSettings}
        onChange={updateProtocol}
        generatorContext={{ host: draft.host.trim() }}
        reportJsonValidity={reportJsonValidity}
      />
    }

    return <JsonField
      label="Protocol Settings（协议模型不可用时的兼容编辑）"
      value={draft.protocolSettings}
      onChange={(value) => setDraft((current) => ({
        ...current,
        protocolSettings: isRecord(value) ? value : {},
      }))}
      reportJsonValidity={reportJsonValidity}
    />
  }

  return <Modal
    open={open}
    title={node ? '编辑节点' : '新建节点'}
    subtitle="管理节点连接、协议参数与访问范围。"
    onClose={onClose}
    wide
    className="node-editor-modal"
    bodyClassName="node-editor-body"
    headerAction={<ProtocolPicker value={draft.type} definitions={protocolDefinitions} onChange={setType}/>}
    footer={<div className="node-editor-actions">
      <button className="button node-editor-cancel" onClick={onClose}>取消</button>
      <button className="button primary" disabled={saving} onClick={submit}>{saving ? '提交中…' : '提交'}</button>
    </div>}
  >
    <div className="node-editor-form">
      <div className="node-editor-grid">
        <label className="node-editor-field">
          <span>节点名称</span>
          <input value={draft.name} onChange={(event) => setDraft({ ...draft, name: event.target.value })} placeholder="请输入节点名称"/>
        </label>
        <label className="node-editor-field">
          <span>基础倍率</span>
          <div className="node-input-suffix">
            <input type="number" min={0} step="0.01" value={draft.rate} onChange={(event) => setDraft({ ...draft, rate: event.target.value })}/>
            <b>x</b>
          </div>
        </label>
      </div>

      <div className="node-toggle-row">
        <div>
          <strong>启用动态倍率</strong>
          <small>根据时间段设置不同的倍率乘数</small>
        </div>
        <button
          type="button"
          role="switch"
          aria-checked={draft.rateTimeEnable}
          className={'node-editor-switch' + (draft.rateTimeEnable ? ' active' : '')}
          onClick={() => setDraft({ ...draft, rateTimeEnable: !draft.rateTimeEnable })}
        ><span/></button>
      </div>

      <div className="node-editor-grid">
        <label className="node-editor-field">
          <span>流量限制 <small>（GB）</small></span>
          <input type="number" min={0} step="0.01" value={draft.transferEnable} onChange={(event) => setDraft({ ...draft, transferEnable: event.target.value })} placeholder="0 表示不限制"/>
        </label>
        <label className="node-editor-field">
          <span>自定义节点 ID <small>（选填）</small></span>
          <input value={draft.code} onChange={(event) => setDraft({ ...draft, code: event.target.value })} placeholder="请输入自定义节点 ID"/>
        </label>
      </div>

      <label className="node-editor-field node-editor-field-full">
        <span>节点标签</span>
        <input value={draft.tags} onChange={(event) => setDraft({ ...draft, tags: event.target.value })} placeholder="输入标签，多个标签使用逗号分隔"/>
      </label>

      <MultiPicker
        label="权限组"
        placeholder="请选择权限组"
        value={draft.groupIds}
        options={groups.map((group) => ({ value: group.id, label: group.name || 'Group ' + group.id }))}
        onChange={(groupIds) => setDraft({ ...draft, groupIds })}
      />

      <label className="node-editor-field node-editor-field-full">
        <span>节点地址</span>
        <input value={draft.host} onChange={(event) => setDraft({ ...draft, host: event.target.value })} placeholder="请输入节点域名或者 IP"/>
      </label>

      <div className="node-port-grid">
        <label className="node-editor-field">
          <span>连接端口</span>
          <input type="number" min={1} max={65535} value={draft.port} onChange={(event) => setDraft({ ...draft, port: event.target.value })} placeholder="用户连接端口"/>
        </label>
        <span className="node-port-arrow">→</span>
        <label className="node-editor-field">
          <span>服务端口</span>
          <input type="number" min={1} max={65535} value={draft.serverPort} onChange={(event) => setDraft({ ...draft, serverPort: event.target.value })} placeholder="请输入服务端口"/>
        </label>
      </div>

      <section className={'node-protocol-stage' + (draft.type ? ' has-protocol' : '')}>
        {draft.type ? <div className="node-editor-section-title">
          <strong>协议配置</strong>
          <span>{protocolDefinition(draft.type, protocolDefinitions)?.label || draft.type}</span>
        </div> : null}
        {renderProtocolFields()}
      </section>

      <div className="node-editor-grid">
        <label className="node-editor-field">
          <span>父级节点</span>
          <select value={draft.parentId} onChange={(event) => setDraft({ ...draft, parentId: event.target.value })}>
            <option value="">无</option>
            {nodes.filter((item) => item.id !== node?.id).map((item) => <option key={item.id} value={item.id}>{item.name || 'Node ' + item.id}</option>)}
          </select>
        </label>
        <label className="node-editor-field">
          <span>绑定服务器</span>
          <select value={draft.machineId} onChange={(event) => setDraft({ ...draft, machineId: event.target.value })}>
            <option value="">独立部署</option>
            {machines.map((machine) => <option key={machine.id} value={machine.id}>{machine.name || 'Machine ' + machine.id}</option>)}
          </select>
        </label>
      </div>

      <MultiPicker
        label="路由组"
        placeholder="请选择路由组"
        value={draft.routeIds}
        options={routes.map((route) => ({ value: route.id, label: route.remarks || 'Route ' + route.id }))}
        onChange={(routeIds) => setDraft({ ...draft, routeIds })}
      />

      <div className="node-toggle-pair">
        <div className="node-toggle-row">
          <div><strong>在订阅中显示</strong><small>允许用户订阅获取该节点</small></div>
          <button type="button" role="switch" aria-checked={draft.show} className={'node-editor-switch' + (draft.show ? ' active' : '')} onClick={() => setDraft({ ...draft, show: !draft.show })}><span/></button>
        </div>
        <div className="node-toggle-row">
          <div><strong>启用节点</strong><small>关闭后节点停止参与服务</small></div>
          <button type="button" role="switch" aria-checked={draft.enabled} className={'node-editor-switch' + (draft.enabled ? ' active' : '')} onClick={() => setDraft({ ...draft, enabled: !draft.enabled })}><span/></button>
        </div>
      </div>

      <details className="node-editor-advanced">
        <summary><strong>高级节点模型</strong><span>IP、排除规则、自定义出站、路由与证书</span><ChevronDown size={17}/></summary>
        <div className="node-editor-advanced-body">
          {draft.rateTimeEnable && <JsonField label="分时倍率 rate_time_ranges" value={draft.rateTimeRanges} arrayOnly onChange={(value) => setDraft({ ...draft, rateTimeRanges: value })} reportJsonValidity={reportJsonValidity}/>}
          <JsonField label="Excludes" value={draft.excludes} arrayOnly onChange={(value) => setDraft({ ...draft, excludes: value })} reportJsonValidity={reportJsonValidity}/>
          <JsonField label="IPs" value={draft.ips} arrayOnly onChange={(value) => setDraft({ ...draft, ips: value })} reportJsonValidity={reportJsonValidity}/>
          <JsonField label="Custom Outbounds" value={draft.customOutbounds} arrayOnly onChange={(value) => setDraft({ ...draft, customOutbounds: value })} reportJsonValidity={reportJsonValidity}/>
          <JsonField label="Custom Routes" value={draft.customRoutes} arrayOnly onChange={(value) => setDraft({ ...draft, customRoutes: value })} reportJsonValidity={reportJsonValidity}/>
          <JsonField label="Certificate Config" value={draft.certConfig} onChange={(value) => setDraft({ ...draft, certConfig: value })} reportJsonValidity={reportJsonValidity}/>
        </div>
      </details>
    </div>
  </Modal>
}
