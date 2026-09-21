import { useEffect, useState, type ChangeEvent } from 'react'
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

function draftFromNode(node?: NodeItem | null, definitions: ProtocolDefinitionMeta[] = []): Draft {
  const type = canonicalType(node?.type, definitions)
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
    protocolSettings: mergeDeep(protocolDefaults(type, definitions), node?.protocol_settings),
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

function selectedNumbers(event: ChangeEvent<HTMLSelectElement>) {
  return Array.from(event.target.selectedOptions).map((option) => Number(option.value))
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

  useEffect(() => {
    if (open) setDraft(draftFromNode(node, protocolDefinitions))
    // Protocol definitions may refresh while the modal is open. Do not reset
    // in-progress input merely because query data received a new reference.
  }, [open, node?.id])

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
    const transferEnable = Number(draft.transferEnable || 0)

    if (!draft.name.trim()) return toast.error('请输入节点名称')
    if (!draft.host.trim()) return toast.error('请输入节点地址')
    if (!Number.isInteger(port) || port < 1 || port > 65535) return toast.error('连接端口必须在 1-65535 之间')
    if (!Number.isInteger(serverPort) || serverPort < 1 || serverPort > 65535) return toast.error('后端服务端口必须在 1-65535 之间')
    if (!Number.isFinite(rate) || rate < 0) return toast.error('倍率必须是大于等于 0 的数字')
    if (!Number.isInteger(transferEnable) || transferEnable < 0) return toast.error('流量上限必须是大于等于 0 的整数')
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

  const renderProtocolFields = () => {
    const managed = protocolDefinition(draft.type, protocolDefinitions)

    if (managed?.form_schema?.length) {
      return <ProtocolSchemaForm
        fields={managed.form_schema}
        value={draft.protocolSettings}
        onChange={updateProtocol}
      />
    }

    return <JsonField
      label="Protocol Settings（协议模型不可用时的兼容编辑）"
      value={draft.protocolSettings}
      onChange={(value) => setDraft((current) => ({
        ...current,
        protocolSettings: isRecord(value) ? value : {},
      }))}
    />
  }

  return <Modal open={open} title={node ? '编辑节点' : '添加节点'} onClose={onClose} wide>
    <div className="form-stack">
      <section className="node-form-section">
        <h4>基础配置 <span className="node-protocol-badge">连接与节点控制</span></h4>
        <div className="node-form-grid">
          <label className="field"><span>节点名称</span><input value={draft.name} onChange={(event) => setDraft({ ...draft, name: event.target.value })} /></label>
          <label className="field"><span>协议类型</span><select value={draft.type} onChange={(event) => setType(event.target.value as NodeProtocolType)}>
            {(protocolDefinitions.length
              ? protocolDefinitions
              : [{ type: draft.type, label: draft.type, schema_version: 0, defaults: {}, form_schema: [] }]
            ).map((item) => <option key={item.type} value={item.type}>{item.label}</option>)}
          </select></label>
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
        <h4>协议配置 <span className="node-protocol-badge">{protocolDefinition(draft.type, protocolDefinitions)?.label || draft.type}</span></h4>
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
