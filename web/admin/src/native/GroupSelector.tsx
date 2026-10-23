import { t, useLocale } from '../lib/i18n'
import { useEffect, useId, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { ManagementFeedback, managementError } from './Feedback'
import { Modal } from '../components/ui/Modal'
import { useAdminConfirmation } from './useAdminConfirmation'
import { useDirtyDraft } from '../lib/useDirtyDraft'

function groupId(response: unknown): string | undefined {
  if (!response || typeof response !== 'object' || !('data' in response) || !response.data || typeof response.data !== 'object') return
  return 'id' in response.data && typeof response.data.id === 'string' ? response.data.id : undefined
}

export function GroupCreationRecovery({ onCreated }: { onCreated: (id: string) => void }) {
  useLocale()
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const pending = state.pending?.unknown && state.pending.operation === 'network.groups.save' && !('id' in state.pending.input) ? state.pending : undefined
  const [error, setError] = useState(''), client = useQueryClient()
  const read = useQuery({ queryKey: ['native-admin', 'group-create-recovery'], enabled: !!pending,
    queryFn: ({ signal }) => admin.read('network.groups.list', { page: 1, per_page: 1 }, signal) })
  const mounted = useRef(true)
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  if (!pending) return null
  async function recover() {
    if (!pending || state.busy || read.isFetching || read.isError || !read.data) return
    const generation = workflowActions.generation(); setError('')
    try {
      workflowActions.confirmReadAuthorization('network.groups.')
      const result = await workflowActions.runResult(pending.operation, pending.input)
      if (!mounted.current || generation !== workflowActions.generation()) return
      const id = groupId(result)
      if (id) onCreated(id)
      await client.invalidateQueries({ queryKey: ['native-admin'] })
    } catch (failure) { if (mounted.current && generation === workflowActions.generation()) setError(managementError(failure, false)) }
  }
  return <section className="network-recovery-card" role="status"><p>{t("权限组创建结果等待确认：{name}。确认会复用原名称和原请求编号。", { name: String(pending.input.name) })}</p>
    <ManagementFeedback error={read.error} loading={read.isFetching} onRetry={() => void read.refetch()} />
    {error ? <p role="alert">{t(error)}</p> : null}
    <button type="button" className="button" disabled={state.busy || read.isFetching || read.isError || !read.data} onClick={() => void recover()}>{t("确认原权限组创建")}</button>
  </section>
}

export function GroupSelector({ value, onChange, multiple = true, disabled, label = t("权限组"), allowCreate = true }: {
  value: string[]; onChange: (ids: string[]) => void; multiple?: boolean; disabled?: boolean; label?: string; allowCreate?: boolean
}) {
  useLocale()
  const [open, setOpen] = useState(false), [page, setPage] = useState(1), [search, setSearch] = useState('')
  const [createOpen, setCreateOpen] = useState(false)
  const pickerId = useId()
  const [name, setName] = useState(''), [error, setError] = useState(''), [createdNames, setCreatedNames] = useState<Record<string, string>>({})
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot), client = useQueryClient(), mounted = useRef(true)
  const ownWorkflowError = !state.errorOperation || state.errorOperation.startsWith('network.groups.') ? state.error : undefined
  const query = useQuery({ queryKey: ['native-admin', 'group-selector', page], enabled: open,
    queryFn: ({ signal }) => admin.read('network.groups.list', { page, per_page: 100 }, signal) })
  const selected = useQuery({ queryKey: ['native-admin', 'group-selector-names', ...value], enabled: value.length > 0,
    queryFn: ({ signal }) => admin.read('network.groups.names.get', { group_ids: value }, signal) })
  const denied = [query.error, selected.error, ownWorkflowError].some(failure => failure instanceof CoreAdminApiError && [401, 403].includes(failure.status))
  const locked = disabled || state.busy || !!state.pending || denied
  const confirm = useAdminConfirmation(() => !locked, pickerId)
  useDirtyDraft(createOpen && !!name && !locked)
  const rows = query.isError || denied ? [] : (query.data?.data ?? []).filter(group => [group.name, group.id].some(text => text.toLowerCase().includes(search.trim().toLowerCase())))
  const names = Object.fromEntries((selected.isError || denied ? [] : selected.data?.data.groups ?? []).map(group => [group.id, group.name]))
  useEffect(() => { mounted.current = true; return () => { mounted.current = false } }, [])
  useEffect(() => { if (denied) { setOpen(false); setCreateOpen(false); setName(''); setCreatedNames({}) } }, [denied])
  async function closeCreate() {
    if (state.busy) return
    if (name.trim() && !(await confirm(t('权限组尚未创建，确定放弃输入的名称？')))) return
    setCreateOpen(false); setName(''); setError('')
  }
  async function refreshAuthorization() {
    const generation = workflowActions.generation()
    try {
      await query.refetch({ throwOnError: true })
      if (value.length) await selected.refetch({ throwOnError: true })
      if (!mounted.current || generation !== workflowActions.generation()) return
      workflowActions.confirmReadAuthorization('network.groups.')
      if (workflowActions.snapshot().errorOperation?.startsWith('network.groups.')) workflowActions.clearError()
      setError('')
    } catch { /* The fresh query feedback retains the failed authorization. */ }
  }
  async function create() {
    if (locked || !name.trim() || name.length > 255) return
    const generation = workflowActions.generation(), submitted = name.trim(); setError('')
    try {
      const result = await workflowActions.runResult('network.groups.save', { name: submitted })
      if (!mounted.current || generation !== workflowActions.generation()) return
      const id = groupId(result)
      if (!id) throw new Error('GROUP_CREATION_RESULT_MISSING')
      setCreatedNames(current => ({ ...current, [id]: submitted })); setName(''); setCreateOpen(false)
      onChange(multiple ? [...new Set([...value, id])] : [id])
      await client.invalidateQueries({ queryKey: ['native-admin', 'group-selector'] })
    } catch (failure) {
      if (mounted.current && generation === workflowActions.generation()) {
        setError(managementError(failure, false))
        if (workflowActions.snapshot().pending) setCreateOpen(false)
      }
    }
  }
  return <div className="network-group-selector">
    <div className="network-group-summary"><span>{label}</span><button type="button" className="button" disabled={locked} aria-expanded={open} onClick={() => setOpen(current => !current)}>{open ? t("收起{label}", { label }) : t("选择{label}", { label })}</button></div>
    <div className="network-group-selected" aria-label={t("已选{label}", { label })}>{!value.length ? <small>{t("未选择")}</small> : !denied ? value.map(id => <span key={id}>
      {names[id] ?? createdNames[id] ?? (selected.isError ? t("名称读取失败") : selected.isFetching ? t("正在读取名称") : t("不存在或不可用"))} · #{id}
      <button type="button" disabled={locked} aria-label={t("移除权限组 {id}", { id })} onClick={() => onChange(value.filter(item => item !== id))}>×</button></span>) : null}</div>
    {value.length ? <ManagementFeedback error={selected.error} loading={selected.isFetching} onRetry={() => void selected.refetch()} /> : null}
    {!open && query.isError ? <ManagementFeedback error={query.error} loading={query.isFetching} onRetry={() => void query.refetch()} /> : null}
    {!open && ownWorkflowError ? <ManagementFeedback error={ownWorkflowError} loading={query.isFetching || selected.isFetching} onRetry={() => void refreshAuthorization()} /> : null}
    {!open && error && !ownWorkflowError ? <p role="alert">{t(error)}</p> : null}
    {open && !denied ? <div className="network-group-panel">
      <label>{t("搜索本页{label}", { label })}<input className="ui-input" aria-label={t("搜索本页{label}", { label })} value={search} disabled={locked} onChange={event => setSearch(event.target.value)} placeholder={t("名称或 ID")} /></label>
      <p className="network-help">{t("仅搜索当前页，已选权限组跨页保留。名称从原生权限组读取。")}</p>
      <ManagementFeedback error={query.error} loading={query.isFetching} onRetry={() => void query.refetch()} />
      <div className="network-group-options">{!multiple ? <label><input type="radio" checked={!value.length} disabled={locked || query.isFetching} onChange={() => onChange([])} />{t("不选择")}</label> : null}
        {rows.map(group => <label key={group.id}><input type={multiple ? 'checkbox' : 'radio'} name={pickerId} checked={value.includes(group.id)} disabled={locked || query.isFetching}
          onChange={event => onChange(multiple ? event.target.checked ? [...value, group.id] : value.filter(id => id !== group.id) : [group.id])} />{group.name}<small>#{group.id}</small></label>)}
        {query.data && !query.isError && !rows.length ? <p>{t("本页没有符合条件的权限组。")}</p> : null}
      </div>
      <div className="network-node-pagination"><button type="button" className="button" disabled={locked || query.isFetching || page === 1} onClick={() => { setPage(current => current - 1); setSearch('') }}>{t("上一页权限组")}</button>
        <small>{t("第{page} / {total}页", { page, total: query.isError ? '?' : query.data?.meta.last_page ?? '?' })}</small><button type="button" className="button" disabled={locked || query.isFetching || query.isError || !query.data || page >= query.data.meta.last_page} onClick={() => { setPage(current => current + 1); setSearch('') }}>{t("下一页权限组")}</button></div>
      {allowCreate ? <div className="network-group-create"><p className="field-help">{t("没有合适的权限组？创建后自动选择，当前表单内容继续保留。")}</p><button type="button" className="button" disabled={locked} onClick={() => { setError(''); setCreateOpen(true) }}>{t("新建权限组")}</button></div> : null}
    </div> : null}
    {createOpen && !denied ? <Modal open size="compact" title={t("新建权限组")} subtitle={t("创建成功后自动选择此权限组，返回原表单继续编辑。")}
      onClose={() => void closeCreate()} className="network-reference-page network-group-create-dialog"
      footer={<><button type="button" className="button" disabled={state.busy} onClick={() => void closeCreate()}>{t("取消创建权限组")}</button><button type="submit" form={pickerId + '-create'} className="button primary" disabled={locked || !name.trim()}>{t("创建并选择权限组")}</button></>}>
      <form id={pickerId + '-create'} className="native-content-form" onSubmit={event => { event.preventDefault(); void create() }}>
        <label>{t("新权限组名称")}<input data-autofocus className="ui-input" aria-label={t("新权限组名称")} value={name} required maxLength={255} disabled={locked} onChange={event => setName(event.target.value)} placeholder={t("例如：标准订阅组")} /></label>
        {error ? <p role="alert">{t(error)}</p> : null}
      </form>
    </Modal> : null}
  </div>
}
