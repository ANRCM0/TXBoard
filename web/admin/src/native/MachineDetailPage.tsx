import { t, useLocale } from '../lib/i18n'
import { CopyButton } from '../components/ui/CopyButton'
import { Plus, RefreshCw, Server, Settings2 } from 'lucide-react'
import './network-reference.css'
import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { admin, workflowActions } from './session'
import { PageHeader } from '../components/ui/PageHeader'
import { ManagementFeedback } from './Feedback'
import { ReadFields } from './ReadList'
import { MachineNodeManager } from './MachineNodeManager'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { formatTimestamp } from './Inventory'
import { NetworkRecovery, useNetworkManagement } from './NetworkManagement'
import { DetailSection } from '../components/ui/DetailSection'
import { DangerZone } from '../components/ui/DangerZone'
import { StatusBadge } from '../components/ui/StatusBadge'
import { Field, Input, Select, Switch, Textarea } from '../components/ui/Field'
import { MachineCredentialsPanel, MachineHistoryPanel } from './MachineRuntimePanels'

export function MachineDetailPage() {
  useLocale()
  const client = useQueryClient()
  const { machineId = '' } = useParams(), navigate = useNavigate()
  const [name, setName] = useState(''), [notes, setNotes] = useState('')
  const [active, setActive] = useState(false), [channel, setChannel] = useState('stable')
  const [runtimeError, setRuntimeError] = useState<unknown>()
  const [nodesBlocked, setNodesBlocked] = useState(true)
  const baseline = useRef({ id: '', value: '' }), discardOnRefresh = useRef(false)
  const draftValue = JSON.stringify({ name, notes, active, channel })
  const currentDraft = useRef(draftValue); currentDraft.current = draftValue
  const query = useQuery({ queryKey: ['native-admin', 'machine-detail', machineId],
    queryFn: ({ signal }) => admin.read('network.machines.get', { machine_id: machineId }, signal), enabled: !!machineId })
  const management = useNetworkManagement('network.machines.', [query.error, runtimeError], async () => {
    await query.refetch({ throwOnError: true }); await client.refetchQueries({ queryKey: ['native-admin', 'machine-nodes', machineId], type: 'all' }, { throwOnError: true })
    await client.refetchQueries({ queryKey: ['native-admin', 'machine-history', machineId], type: 'all' }, { throwOnError: true })
    setRuntimeError(undefined)
  }, machineId)
  const machine = !management.denied && !query.isError ? query.data?.data : undefined
  useEffect(() => { setRuntimeError(undefined); setNodesBlocked(true) }, [machineId])
  useEffect(() => {
    if (machine && baseline.current.id === machine.id && currentDraft.current !== baseline.current.value && !discardOnRefresh.current) return
    const next = { name: machine?.name ?? '', notes: machine?.notes ?? '', active: machine?.is_active ?? false, channel: machine?.image_channel ?? 'stable' }
    baseline.current = { id: machine?.id ?? '', value: JSON.stringify(next) }
    setName(next.name); setNotes(next.notes); setActive(next.active); setChannel(next.channel)
  }, [machine])
  async function reload() {
    if (baseline.current.id && currentDraft.current !== baseline.current.value && !window.confirm(t('服务器配置尚未保存，重新读取将替换草稿，确定继续？'))) return
    discardOnRefresh.current = true
    try {
      if (await management.refresh()) {
        const fresh = client.getQueryData<typeof query.data>(['native-admin', 'machine-detail', machineId])?.data
        if (fresh) {
          const next = { name: fresh.name, notes: fresh.notes ?? '', active: fresh.is_active, channel: fresh.image_channel }
          baseline.current = { id: fresh.id, value: JSON.stringify(next) }
          setName(next.name); setNotes(next.notes); setActive(next.active); setChannel(next.channel)
        }
      }
    } finally { discardOnRefresh.current = false }
  }
  const locked = !machine || query.isFetching || nodesBlocked || management.state.busy || !!management.state.pending
  useDirtyDraft(!!machine && baseline.current.id === machine.id && currentDraft.current !== baseline.current.value)
  async function save() {
    if (locked || !machine || !name.trim() || !['stable', 'dev'].includes(channel) || !window.confirm(t("确定保存服务器配置？请核对当前配置后继续。"))) return
    await management.perform(() => workflowActions.run('network.machines.update', { machine_id: machine.id, name: name.trim(), notes: notes || null, is_active: active, image_channel: channel as 'stable' | 'dev' }), () => { baseline.current.value = currentDraft.current })
  }
  async function remove() {
    if (locked || !machine || !window.confirm(t("确定删除服务器 {name}？其绑定节点将全部解除服务器归属，节点本身保留。", { name: machine.name }))) return
    await management.perform(() => workflowActions.run('network.machines.delete', { machine_id: machine.id }), () => navigate('/server/machine'))
  }
  return <div className="native-management-page network-reference-page network-machine-detail-page">
    <PageHeader title={t("服务器详情")} description={t("管理服务器配置并查看已绑定节点。")} action={<><Link className="button" to="/server/machine">{t("返回服务器列表")}</Link><button className="button" disabled={query.isFetching || management.state.busy} onClick={() => void reload()}><RefreshCw size={15} aria-hidden="true" />{t("刷新服务器")}</button><Link className="button primary" to={'/server/manage?machine_id=' + machineId + '&open_create=1'}><Plus size={15} aria-hidden="true" />{t("创建节点")}</Link></>} />
    <NetworkRecovery management={management} blocked={!machine || query.isFetching || query.isError || nodesBlocked || (!!management.pending && 'machine_id' in management.pending.input && management.pending.input.machine_id !== machine.id)} />
    <ManagementFeedback error={query.error} loading={query.isFetching} onRetry={() => void reload()} notFoundMessage={t("服务器不存在或已被删除。")} />
    {machine ? <>
      <DetailSection label={t("服务器配置概况")} title={machine.name} description={t("服务器 #{id} · {channel}", { id: machine.id, channel: machine.image_channel })}
        icon={<Server size={17} />} className="network-profile-card"
        actions={<StatusBadge tone={machine.is_active ? 'success' : 'neutral'}>{machine.is_active ? t("已启用") : t("已停用")}</StatusBadge>}>
        <h3 className="network-section-heading">{t("运行概况")}</h3>
        <ReadFields fields={[
          [t("服务器 ID"), <span className="network-copy-value">{machine.id}<CopyButton value={machine.id} label={t("复制服务器 ID")} /></span>], [t("绑定节点数"), machine.servers_count], [t("最近心跳"), formatTimestamp(machine.last_seen_at)],
          [t("创建时间"), formatTimestamp(machine.created_at)], [t("更新时间"), formatTimestamp(machine.updated_at)],
        ]} />
      </DetailSection>
      <MachineHistoryPanel key={'history-' + machine.id} machineId={machine.id} onAuthorizationFailure={setRuntimeError} />
      <MachineCredentialsPanel key={'credentials-' + machine.id} machineId={machine.id} disabled={locked} onAuthorizationFailure={setRuntimeError} />
      <DetailSection label={t("服务器设置")} title={t("服务器设置")} description={t("设置名称、镜像通道和管理状态。保存前请核对当前配置。")}
        icon={<Settings2 size={17} />} className="network-settings-card">
        <form className="native-content-form" onSubmit={event => { event.preventDefault(); void save() }}>
          <Field label={t("服务器名称")} required hint={t("用于识别资源归属，建议包含区域和用途。")}>
            <Input required maxLength={255} value={name} disabled={locked} onChange={event => setName(event.target.value)} />
          </Field>
          <Field label={t("镜像通道")} hint={channel === 'dev' ? t("dev 跟随开发版本，升级前请确认兼容性。") : t("stable 使用稳定版本镜像通道。")}>
            <Select value={channel} disabled={locked} onChange={event => setChannel(event.target.value)}>
              {!['stable', 'dev'].includes(channel) ? <option value={channel}>{channel}{t("（当前值）")}</option> : null}
              <option value="stable">stable</option><option value="dev">dev</option>
            </Select>
          </Field>
          <Switch label={t("启用服务器")} checked={active} disabled={locked} onChange={event => setActive(event.target.checked)} />
          <Field label={t("备注")} hint={t("{v0} / 2000 字符", { v0: notes.length })} className="network-form-field-wide">
            <Textarea id="machine-notes" placeholder={t("添加用途、区域或运维备注")} rows={4} maxLength={2000} value={notes} disabled={locked} onChange={event => setNotes(event.target.value)} />
          </Field>
          <div className="network-save-actions">
            <button className="button" data-primary="true" disabled={locked || !name.trim() || !['stable', 'dev'].includes(channel)}>{t("保存服务器")}</button>
            <span className="network-muted">{t("保存前请核对名称、通道与启用状态")}</span>
          </div>
        </form>
        <DangerZone title={t("移除服务器资源")} description={t("删除后，绑定节点将解除服务器归属，节点本身保留。")} className="network-danger-zone">
          <button type="button" className="button danger" disabled={locked} onClick={() => void remove()}>{t("删除服务器")}</button>
        </DangerZone>
      </DetailSection>
      <MachineNodeManager key={machine.id} machineId={machine.id} disabled={locked} recoveryBlocked={query.isFetching || query.isError} onAuthorizationFailure={setRuntimeError} onReadBlockedChange={setNodesBlocked} />
    </> : null}
  </div>
}
