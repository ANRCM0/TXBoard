import { t, useLocale } from '../lib/i18n'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { useEffect, useId, useRef, useState, useSyncExternalStore, type FormEvent } from 'react'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { Modal } from '../components/ui/Modal'
import { Field, Input, Select, Switch } from '../components/ui/Field'
import { TagInput } from '../components/ui/TagInput'
import { ManagementFeedback, managementError } from './Feedback'
import { admin, workflowActions } from './session'
import { NodeProtocolFields, NodeJsonField } from './NodeProtocolFields'
import { NodeResourcePicker } from './NodeResourcePicker'
import { NodeCertificateFields } from './NodeCertificateFields'
import { GroupCreationRecovery } from './GroupSelector'
import { newNodeConfiguration, nodeQuotaFromGb, nodeQuotaGb, parseNodeConfiguration, parseNodeProtocols,
  withProtocolValue, type NodeConfiguration, type NodeProtocol, type NodeProtocolField } from './nodeConfiguration'
import './node-editor.css'

function savedId(response: unknown): string | undefined {
  if (!response || typeof response !== 'object' || !('data' in response) || !response.data || typeof response.data !== 'object') return
  return 'id' in response.data && typeof response.data.id === 'string' ? response.data.id : undefined
}

export function NodeEditor({ nodeId, defaultMachineId, onClose, onSaved }: {
  nodeId?: string; defaultMachineId?: string; onClose: () => void; onSaved: (id?: string) => void
}) {
  useLocale()
  const formId = useId()
  const workflow = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const generation = useSyncExternalStore(workflowActions.subscribe, workflowActions.generation)
  const [protocols, setProtocols] = useState<NodeProtocol[]>([]), [draft, setDraft] = useState<NodeConfiguration>()
  const [revision, setRevision] = useState(''), [quota, setQuota] = useState(''), [baseline, setBaseline] = useState('')
  const [readError, setReadError] = useState<unknown>(), [error, setError] = useState(''), [loading, setLoading] = useState(true)
  const [loadedGeneration, setLoadedGeneration] = useState(generation)
  const [reload, setReload] = useState(0), [conflict, setConflict] = useState(false), [generating, setGenerating] = useState('')
  const epoch = useRef(0), drafts = useRef<Record<string, Record<string, unknown>>>({}), latest = useRef(draft)
  latest.current = draft
  const ownWorkflowError = !workflow.errorOperation || workflow.errorOperation.startsWith('network.nodes.') ? workflow.error : undefined
  const denied = [readError, ownWorkflowError].some(value => value instanceof CoreAdminApiError && [401, 403].includes(value.status))
  useEffect(() => {
    const current = ++epoch.current, abort = new AbortController()
    setDraft(undefined); setProtocols([]); drafts.current = {}; setQuota(''); setRevision(''); setBaseline('')
    setLoading(true); setReadError(undefined); setError(''); setConflict(false); setGenerating('')
    void (async () => {
      try {
        const metadata = await admin.read('network.nodes.protocols.list', {}, abort.signal)
        const parsed = parseNodeProtocols(metadata.data.metadata_json)
        const configuration = nodeId ? await admin.read('network.nodes.configuration.get', { node_id: nodeId }, abort.signal) : undefined
        if (current !== epoch.current || abort.signal.aborted) return
        const value = configuration ? parseNodeConfiguration(configuration.data.configuration_json) : newNodeConfiguration(parsed[0], defaultMachineId)
        if (!configuration) { value.type = ''; value.protocol_settings = {} }
        if (configuration && configuration.data.node_id !== nodeId) throw new Error('NODE_CONFIGURATION_ID_MISMATCH')
        setProtocols(parsed); setDraft(value); setQuota(nodeQuotaGb(value.transfer_enable)); setRevision(configuration?.data.revision ?? '')
        setBaseline(JSON.stringify(value)); workflowActions.confirmReadAuthorization('network.nodes.'); workflowActions.clearError()
        setLoadedGeneration(generation)
      } catch (failure) {
        if (current === epoch.current && !abort.signal.aborted) { setReadError(failure); setDraft(undefined); setProtocols([]); drafts.current = {} }
      } finally { if (current === epoch.current && !abort.signal.aborted) setLoading(false) }
    })()
    return () => { ++epoch.current; abort.abort(); latest.current = undefined; drafts.current = {} }
  }, [nodeId, defaultMachineId, generation, reload])
  useEffect(() => { if (denied) { setDraft(undefined); setProtocols([]); setQuota(''); setBaseline(''); drafts.current = {} } }, [denied])
  const pending = workflow.pending?.operation === 'network.nodes.create' || workflow.pending?.operation === 'network.nodes.configuration.replace'
    ? workflow.pending : undefined
  const ownPending = pending && (pending.operation === 'network.nodes.create' ? !nodeId : pending.input.node_id === nodeId) ? pending : undefined
  const locked = loading || denied || workflow.busy || !!workflow.pending || conflict
  const dirty = draft && JSON.stringify({ ...draft, transfer_enable: (() => { try { return nodeQuotaFromGb(quota) } catch { return quota } })() }) !== baseline
  useDirtyDraft(!!dirty && !!draft && !loading && !denied && !workflow.pending && loadedGeneration === generation)
  function dirtyJson() { return !!document.getElementById(formId)?.querySelector('[data-json-dirty="true"]') }
  function close() {
    if (workflow.busy) return
    if (!workflow.pending && (dirty || dirtyJson()) && !window.confirm(t("节点配置尚未保存，确定放弃本次修改？"))) return
    ++epoch.current; onClose()
  }
  function reread() {
    if (workflow.busy || ((dirty || dirtyJson()) && !window.confirm(t("重新读取将替换当前节点草稿，确定继续？")))) return
    setReload(current => current + 1)
  }
  function patch(values: Partial<NodeConfiguration>) { setDraft(current => current ? { ...current, ...values } : current) }
  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (locked || !draft) return
    const form = event.currentTarget
    if (!form.checkValidity()) { form.querySelectorAll('details').forEach(details => { details.open = true }); form.reportValidity(); return }
    const selected = protocols.find(protocol => protocol.type === draft.type)
    if (!selected) { setError('请选择节点协议。'); return }
    const current = epoch.current, session = workflowActions.generation()
    try {
      const configuration_json = JSON.stringify({ ...draft, transfer_enable: nodeQuotaFromGb(quota) })
      if (new TextEncoder().encode(configuration_json).byteLength > 131072) throw new Error("节点配置超过 128 KiB，请减少高级配置内容。")
      setError('')
      const response = nodeId
        ? await workflowActions.runResult('network.nodes.configuration.replace', { node_id: nodeId, expected_revision: revision, configuration_json })
        : await workflowActions.runResult('network.nodes.create', { configuration_json })
      if (current !== epoch.current || session !== workflowActions.generation()) return
      ++epoch.current; onSaved(savedId(response))
    } catch (failure) {
      if (current !== epoch.current || session !== workflowActions.generation()) return
      if (failure instanceof CoreAdminApiError && failure.status === 409 && !workflowActions.snapshot().pending) setConflict(true)
      setError(failure instanceof CoreAdminApiError ? managementError(failure, false) : failure instanceof Error && !workflowActions.snapshot().pending ? failure.message : '保存结果未知，请使用原操作确认结果。')
    }
  }
  async function recover() {
    if (!ownPending || loading || readError || denied || workflow.busy || !window.confirm(t("确认继续查询或完成原节点保存？将复用原配置和原请求编号。"))) return
    const current = epoch.current, session = workflowActions.generation()
    try {
      const response = await workflowActions.runResult(ownPending.operation, ownPending.input)
      if (current !== epoch.current || session !== workflowActions.generation()) return
      ++epoch.current; onSaved(savedId(response))
    } catch (failure) { if (current === epoch.current && session === workflowActions.generation()) setError(managementError(failure, false)) }
  }
  async function generate(field: NodeProtocolField) {
    const generator = field.generator
    if (!generator || locked || !draft || generating) return
    const current = epoch.current, type = draft.type
    setGenerating(field.key); setError('')
    try {
      const response = await admin.read('network.nodes.material.generate', { kind: generator.kind, ...(generator.params?.bytes ? { bytes: generator.params.bytes } : {}),
        ...(generator.kind === 'ech' ? { public_name: generator.params?.public_name === '$host' ? draft.host : generator.params?.public_name || draft.host } : {}) })
      if (current !== epoch.current || latest.current?.type !== type) return
      const material: unknown = JSON.parse(response.data.material_json)
      if (!material || typeof material !== 'object') throw new Error('INVALID_NODE_MATERIAL')
      const generatedFields = Object.entries(generator.map).map(([key, path]) => {
        const generated = (material as Record<string, unknown>)[key]
        if (typeof generated !== 'string') throw new Error('INVALID_NODE_MATERIAL')
        return { path, generated }
      })
      setDraft(value => {
        if (!value || value.type !== type) return value
        let settings = value.protocol_settings
        for (const { path, generated } of generatedFields) {
          settings = withProtocolValue(settings, path, generated)
        }
        return { ...value, protocol_settings: settings }
      })
    } catch (failure) {
      if (current !== epoch.current) return
      if (failure instanceof CoreAdminApiError && [401, 403].includes(failure.status)) { setReadError(failure); setDraft(undefined); drafts.current = {} }
      else setError(managementError(failure, false))
    } finally { if (current === epoch.current) setGenerating('') }
  }
  const protocol = protocols.find(item => item.type === draft?.type)
  return <Modal open title={nodeId ? t("编辑节点配置") : t("创建节点")} subtitle={t("协议表单由当前服务端协议定义提供。显示、部署启用与服务器归属分别保存。")}
    wide className="node-editor-modal" bodyClassName="node-editor-body" onClose={close}
    footer={<><button type="button" className="button" disabled={workflow.busy} onClick={close}>{t("取消")}</button>
      <button type="submit" form={formId} className="button" data-primary="true" disabled={locked || !draft || !protocol}>{workflow.busy ? t("正在保存…") : nodeId ? t("保存完整配置") : t("创建节点")}</button></>}>
    <ManagementFeedback error={readError} loading={loading} onRetry={reread} />
    {error ? <p role="alert">{t(error)}</p> : null}
    {conflict ? <div className="node-editor-notice" role="alert"><p>{t("配置已被其他操作修改。当前草稿已保留，请重新读取后核对再编辑。")}</p><button type="button" className="button" onClick={reread}>{t("重新读取配置")}</button></div> : null}
    {workflow.pending ? <div className="node-editor-notice" role="status"><p>{ownPending ? t("原节点保存的结果等待确认。将保留原配置和原请求编号，不会创建新的保存请求。") : t("另有操作等待确认，请先处理原操作。")}</p>
      {ownPending ? <button type="button" className="button" disabled={loading || !!readError || denied || workflow.busy} onClick={() => void recover()}>{t("确认原节点保存")}</button> : null}</div> : null}
    <GroupCreationRecovery onCreated={id => setDraft(current => current ? { ...current, group_ids: [...new Set([...current.group_ids, id])] } : current)} />
    {draft && !denied && (!workflow.pending || workflow.busy && workflow.pending.operation === 'network.groups.save' && !('id' in workflow.pending.input)) ? <form id={formId} className="node-editor-form" onSubmit={event => void save(event)}>
      <section><h4>{t("协议与基础信息")}</h4><div className="node-editor-grid">
        <Field label={t("协议类型")} required><Select value={draft.type} disabled={locked} onChange={event => {
          if (dirtyJson() && !window.confirm(t('切换协议将放弃尚未应用的 JSON 修改，确定继续？'))) return
          const next = protocols.find(item => item.type === event.target.value)
          if (!next) return
          drafts.current[draft.type] = structuredClone(draft.protocol_settings)
          patch({ type: next.type, parent_id: null, protocol_settings: structuredClone(drafts.current[next.type] ?? next.defaults) })
        }}><option value="">{t("请选择协议")}</option>{protocols.map(item => <option key={item.type} value={item.type}>{t(item.label)}</option>)}</Select></Field>
        <Field label={t("节点名称")} required><Input value={draft.name} maxLength={255} disabled={locked} onChange={event => patch({ name: event.target.value })} /></Field>
        <Field label={t("连接地址")} required hint={t("填写客户端连接使用的域名或 IP，不含协议前缀。")}><Input value={draft.host} maxLength={255} disabled={locked} onChange={event => patch({ host: event.target.value })} /></Field>
        <Field label={t("自定义标识")} hint={t("留空使用系统分配标识。")}><Input value={draft.code ?? ''} maxLength={255} disabled={locked} onChange={event => patch({ code: event.target.value || null })} /></Field>
        <Field label={t("客户端端口")} required hint={t("支持单端口或端口范围，例如 443 或 20000-30000。")}><Input value={draft.port} pattern="[0-9]{1,5}(-[0-9]{1,5})?" maxLength={11} disabled={locked} onChange={event => patch({ port: event.target.value })} /></Field>
        <div className="node-editor-inline"><Field label={t("服务端口")} required><Input type="number" min={1} max={65535} value={draft.server_port || ''} disabled={locked} onChange={event => patch({ server_port: Number(event.target.value) })} /></Field>
          <button type="button" className="button" disabled={locked || !/^\d{1,5}$/.test(draft.port) || Number(draft.port) < 1 || Number(draft.port) > 65535} onClick={() => patch({ server_port: Number(draft.port) })}>{t("同步端口")}</button></div>
        <Switch label={t("在订阅中显示")} checked={draft.show} disabled={locked} onChange={event => patch({ show: event.target.checked })} />
        <Field label={t("部署启用状态")}><Select value={draft.enabled === null ? 'null' : String(draft.enabled)} disabled={locked} onChange={event => patch({ enabled: event.target.value === 'null' ? null : event.target.value === 'true' })}>
          <option value="true">{t("启用")}</option><option value="false">{t("停用")}</option><option value="null">{t("跟随默认设置")}</option></Select></Field>
        <Field label={t("标签")} className="node-editor-wide"><TagInput id={formId + '-tags'} value={draft.tags} maxItems={100} maxLength={100} disabled={locked} onChange={tags => patch({ tags })} /></Field>
      </div></section>
      <section><h4>{t("流量与倍率")}</h4><div className="node-editor-grid">
        <Field label={t("流量倍率")} required hint={t("最多两位小数，0 表示不计费。")}><Input value={draft.rate} pattern="(0|[1-9][0-9]{0,5})(\.[0-9]{1,2})?" disabled={locked} onChange={event => patch({ rate: event.target.value })} /></Field>
        <Field label={t("流量上限（GB）")} hint={t("1 GB = 10⁹ 字节；留空或 0 不限流量。")}><Input value={quota} inputMode="decimal" pattern="(0|[1-9][0-9]*)(\.[0-9]{1,9})?" disabled={locked} onChange={event => setQuota(event.target.value)} /></Field>
        <Switch label={t("启用分时倍率")} checked={draft.rate_time_enable} disabled={locked} onChange={event => patch({ rate_time_enable: event.target.checked })} />
      </div>{draft.rate_time_enable ? <div className="node-editor-rates">{draft.rate_time_ranges.map((range, index) => <div key={index} className="node-editor-rate-row">
        <Field label={t("开始时间 {index}", { index: index + 1 })} required><Input type="time" value={range.start} disabled={locked} onChange={event => patch({ rate_time_ranges: draft.rate_time_ranges.map((row, i) => i === index ? { ...row, start: event.target.value } : row) })} /></Field>
        <Field label={t("结束时间 {index}", { index: index + 1 })} required><Input type="time" value={range.end} disabled={locked} onChange={event => patch({ rate_time_ranges: draft.rate_time_ranges.map((row, i) => i === index ? { ...row, end: event.target.value } : row) })} /></Field>
        <Field label={t("倍率 {index}", { index: index + 1 })} required><Input value={range.rate} pattern="(0|[1-9][0-9]{0,5})(\.[0-9]{1,2})?" disabled={locked} onChange={event => patch({ rate_time_ranges: draft.rate_time_ranges.map((row, i) => i === index ? { ...row, rate: event.target.value } : row) })} /></Field>
        <button type="button" className="button" disabled={locked} onClick={() => patch({ rate_time_ranges: draft.rate_time_ranges.filter((_, i) => i !== index) })}>{t("移除时段")}{index + 1}</button>
      </div>)}<button type="button" className="button" disabled={locked || draft.rate_time_ranges.length >= 100} onClick={() => patch({ rate_time_ranges: [...draft.rate_time_ranges, { start: '00:00', end: '23:59', rate: '1' }] })}>{t("添加倍率时段")}</button></div> : null}</section>
      <section><h4>{t("资源关联")}</h4><div className="node-editor-grid">
        <NodeResourcePicker label={t("权限组")} operation="network.groups.list" multiple value={draft.group_ids} disabled={locked} onChange={group_ids => patch({ group_ids })} />
        <NodeResourcePicker label={t("路由")} operation="network.routes.list" multiple value={draft.route_ids} disabled={locked} onChange={route_ids => patch({ route_ids })} />
        <NodeResourcePicker label={t("服务器")} operation="network.machines.list" value={draft.machine_id ? [draft.machine_id] : []} disabled={locked} onChange={ids => patch({ machine_id: ids[0] ?? null })} />
        <NodeResourcePicker key={draft.type} label={t("父节点")} operation="network.nodes.list" value={draft.parent_id ? [draft.parent_id] : []} disabled={locked || !draft.type} protocol={draft.type} nodeId={nodeId} onChange={ids => patch({ parent_id: ids[0] ?? null })} />
      </div></section>
      {protocol ? <section><h4>{t("{protocol}协议配置", { protocol: t(protocol.label) })}</h4><NodeProtocolFields key={protocol.type} protocol={protocol} value={draft.protocol_settings}
        disabled={locked} generating={generating} onGenerate={field => void generate(field)} onChange={protocol_settings => patch({ protocol_settings })} /></section> : null}
      <details className="node-editor-advanced"><summary>{t("高级出站、路由与证书配置")}</summary><p>{t("修改前请核对部署端支持的配置格式。JSON 校验通过后仍会由服务端验证。")}</p>
        <NodeJsonField label={t("自定义 Outbounds")} value={draft.custom_outbounds} arrayOnly disabled={locked} onChange={value => patch({ custom_outbounds: value as unknown[] })} />
        <NodeJsonField label={t("自定义 Routes")} value={draft.custom_routes} arrayOnly disabled={locked} onChange={value => patch({ custom_routes: value as unknown[] })} />
        <NodeCertificateFields protocol={protocol} value={draft.cert_config} disabled={locked} onChange={cert_config => patch({ cert_config })} />
      </details>
    </form> : null}
  </Modal>
}
