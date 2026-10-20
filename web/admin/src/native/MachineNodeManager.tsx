import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { CoreAdminApiError, type CoreOutput } from '@txboard/core-sdk'
import { t, useLocale } from '../lib/i18n'
import { Modal } from '../components/ui/Modal'
import { Input } from '../components/ui/Field'
import type { Column } from '../components/ui/DataTable'
import { admin, workflowActions } from './session'
import { ReadList } from './ReadList'
import { NetworkEndpoint } from './NetworkValues'
import { NetworkRecovery, useNetworkManagement } from './NetworkManagement'
import { ManagementFeedback } from './Feedback'
import { useListPagination } from './useListPagination'

type BoundNode = CoreOutput<'network.machines.nodes.list'>[number]
type Candidate = CoreOutput<'network.nodes.list'>[number]

export function MachineNodeManager({ machineId, disabled, recoveryBlocked = false, onAuthorizationFailure }: {
  machineId: string; disabled: boolean; recoveryBlocked?: boolean; onAuthorizationFailure: (error: unknown) => void
}) {
  useLocale()
  const { page, setPage, perPage, setPerPage } = useListPagination()
  const [binding, setBinding] = useState(false), [candidatePage, setCandidatePage] = useState(1)
  const [search, setSearch] = useState('')
  const [preparing, setPreparing] = useState(false)
  const alive = useRef(true), preparingRef = useRef(false), scope = useRef(0)
  useEffect(() => { alive.current = true; return () => { alive.current = false; scope.current++ } }, [])
  const list = useQuery({ queryKey: ['native-admin', 'machine-nodes', machineId, page, perPage],
    queryFn: ({ signal }) => admin.read('network.machines.nodes.list', { machine_id: machineId, page, per_page: perPage }, signal) })
  const candidates = useQuery({ queryKey: ['native-admin', 'machine-node-candidates', machineId, candidatePage], enabled: binding,
    queryFn: ({ signal }) => admin.read('network.nodes.list', { page: candidatePage, per_page: 100 }, signal) })
  const management = useNetworkManagement('network.nodes.', [list.error, candidates.error], async () => {
    await list.refetch({ throwOnError: true })
    if (binding || candidates.isError) await candidates.refetch({ throwOnError: true })
  }, machineId)
  const locked = disabled || management.denied || list.isError || !list.data || list.isFetching
    || preparing || management.state.busy || !!management.state.pending
  useEffect(() => {
    if (list.error instanceof CoreAdminApiError && [401, 403].includes(list.error.status)) onAuthorizationFailure(list.error)
  }, [list.error, onAuthorizationFailure])
  useEffect(() => { if (management.denied) { setBinding(false); setSearch('') } }, [management.denied])

  async function update(row: BoundNode | Candidate, patch: { enabled?: boolean; machine_id?: string | null }) {
    if (locked || preparingRef.current || candidates.isFetching) return
    const previousMachine = 'machine_id' in row ? row.machine_id : machineId
    if ('machine_id' in patch && previousMachine !== null
      && !window.confirm(patch.machine_id === null
        ? t('解除节点 {name} 的服务器归属？节点本身和配置将保留。', { name: row.name })
        : t('将节点 {name} 从当前服务器迁移到此服务器？', { name: row.name }))) return
    preparingRef.current = true
    setPreparing(true)
    const epoch = workflowActions.generation()
    const currentScope = scope.current
    try { await management.perform(async () => {
      // Preserve every owner field and compare the observed association before a revision-checked write.
      const snapshot = await admin.read('network.nodes.configuration.get', { node_id: row.id })
      if (!alive.current || currentScope !== scope.current || epoch !== workflowActions.generation()) return
      const configuration = JSON.parse(snapshot.data.configuration_json) as Record<string, unknown>
      if (configuration.machine_id !== previousMachine || ('enabled' in patch && configuration.enabled !== row.enabled)) {
        throw new Error(t('节点配置或归属已变化，请刷新列表后重新操作。'))
      }
      await workflowActions.run('network.nodes.configuration.replace', { node_id: row.id,
        expected_revision: snapshot.data.revision, configuration_json: JSON.stringify({ ...configuration, ...patch }) })
    }, () => { if (alive.current) setBinding(false) })
    } finally { preparingRef.current = false; if (alive.current) setPreparing(false) }
  }
  const columns: Column<BoundNode>[] = [
    { key: 'name', header: t('节点'), render: row => <div className="native-row-identity"><Link to={'/server/node/' + row.id}><strong>{row.name}</strong></Link><small>#{row.id}</small></div> },
    { key: 'address', header: t('地址'), render: row => <NetworkEndpoint host={row.host} port={row.port} /> },
    { key: 'protocol', header: t('协议'), render: row => row.type },
    { key: 'state', header: t('状态'), render: row => row.enabled ? t('已启用') : t('已停用') },
    { key: 'actions', header: t('操作'), pin: 'right', hideable: false, render: row => <div className="native-list-toolbar">
      <button type="button" className="button" disabled={locked} onClick={() => void update(row, { enabled: !row.enabled })}>{row.enabled ? t('停用节点') : t('启用节点')}</button>
      <button type="button" className="button" disabled={locked} onClick={() => void update(row, { machine_id: null })}>{t('解除绑定')}</button>
    </div> },
  ]
  const visibleCandidates = candidates.isError || management.denied ? [] : (candidates.data?.data ?? [])
    .filter(row => row.machine_id !== machineId && [row.name, row.id, row.host].some(value => value.toLowerCase().includes(search.trim().toLowerCase())))
  return <>
    <NetworkRecovery management={management} blocked={recoveryBlocked || list.isFetching || list.isError || !list.data} />
    <ReadList columnSettingsKey="machine-bound-nodes" stickyHeader stickyFirstColumn title="绑定节点"
      rows={management.denied || list.isError ? [] : list.data?.data ?? []} columns={columns} perPage={perPage} onPageSize={setPerPage}
      page={page} meta={list.data?.meta} ready={!!list.data && !management.denied} loading={list.isFetching} error={list.error}
      onRetry={() => void management.refresh()} onPage={setPage}>
      <div className="native-list-toolbar">
        <button type="button" className="button" disabled={locked} onClick={() => { setCandidatePage(1); setSearch(''); setBinding(true) }}>{t('绑定已有节点')}</button>
        {!locked ? <Link className="button" to={'/server/manage?machine_id=' + encodeURIComponent(machineId) + '&open_create=1'}>{t('在此服务器创建节点')}</Link> : null}
        <button type="button" className="button" disabled={list.isFetching || management.state.busy} onClick={() => void management.refresh()}>{t('刷新绑定节点')}</button>
      </div>
    </ReadList>
    <Modal open={binding && !management.denied} title={t('绑定已有节点')} onClose={() => { if (!management.state.busy && !preparing) setBinding(false) }}>
      <p>{t('逐页选择节点；已有服务器归属的节点将明确确认迁移。保存校验最新配置修订。')}</p>
      <label>{t('搜索当前页')}<Input value={search} onChange={event => setSearch(event.target.value)} /></label>
      <ManagementFeedback loading={candidates.isFetching} error={candidates.error} onRetry={() => void candidates.refetch()} />
      {visibleCandidates.map(row => <div className="native-list-toolbar" key={row.id}><span>{row.name} · #{row.id}</span>
        <small>{row.machine_id ? t('当前服务器 #{id}', { id: row.machine_id }) : t('未绑定')}</small>
        <button type="button" className="button" disabled={locked || candidates.isFetching || candidates.isError} onClick={() => void update(row, { machine_id: machineId })}>{t('绑定节点')}</button></div>)}
      {!candidates.isFetching && !candidates.isError && !visibleCandidates.length ? <p>{t('本页没有符合条件的资源。')}</p> : null}
      <div className="network-node-pagination"><button type="button" className="button" disabled={candidates.isFetching || candidatePage <= 1 || management.state.busy} onClick={() => setCandidatePage(value => value - 1)}>{t('上一页节点')}</button>
        <span>{t('第{page} / {total}页', { page: candidatePage, total: candidates.isError ? '?' : candidates.data?.meta.last_page ?? '?' })}</span>
        <button type="button" className="button" disabled={candidates.isFetching || candidates.isError || !candidates.data || candidatePage >= candidates.data.meta.last_page || management.state.busy} onClick={() => setCandidatePage(value => value + 1)}>{t('下一页节点')}</button></div>
    </Modal>
  </>
}
