import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreInput, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { ManagementFeedback, managementError } from './Feedback'
import { ReadList } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import type { Column } from '../components/ui/DataTable'

type Method = CoreOutput<'billing.admin-payment-methods.list'>[number]
type Save = CoreInput<'billing.admin-payment-methods.save'>
type Draft = { row?: Method; name: string; provider: string; icon: string; enabled: boolean; sort: string; configuration: Record<string, string> }
const prefix = 'billing.admin-payment-methods.'
const forbidden = (error: unknown) => error instanceof CoreAdminApiError && [401, 403].includes(error.status)

export function PaymentMethodsPage() {
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const generation = workflowActions.generation(), observed = useRef(generation), view = useRef(0)
  const [page, setPage] = useState(1), [draft, setDraft] = useState<Draft>(), [error, setError] = useState('')
  const [failedFormRead, setFailedFormRead] = useState<CoreInput<'billing.admin-payment-methods.form'>>()
  const methods = useQuery({ queryKey: ['native-admin', 'payment-methods', generation, page],
    queryFn: ({ signal }) => admin.read('billing.admin-payment-methods.list', { page, per_page: 20 }, signal) })
  const providers = useQuery({ queryKey: ['native-admin', 'payment-providers', generation],
    queryFn: ({ signal }) => admin.read('billing.admin-payment-providers.list', {}, signal) })
  const form = useQuery({ queryKey: ['native-admin', 'payment-form', generation, draft?.provider, draft?.row?.id],
    enabled: !!draft?.provider,
    queryFn: ({ signal }) => admin.read('billing.admin-payment-methods.form', {
      provider: draft!.provider, ...(draft?.row ? { payment_id: draft.row.id } : {}),
    }, signal) })
  const denied = !!failedFormRead || [methods.error, providers.error, draft && form.error, state.error].some(forbidden)
  const pending = state.pending?.operation === 'billing.admin-payment-methods.save' ? state.pending : undefined
  const readable = !denied && !methods.isError && !providers.isError
  const locked = !readable || state.busy || !!state.pending || methods.isFetching || providers.isFetching || !methods.data || !providers.data
  useEffect(() => () => { view.current++ }, [])
  useEffect(() => {
    if (draft && forbidden(form.error)) setFailedFormRead({ provider: draft.provider, ...(draft.row ? { payment_id: draft.row.id } : {}) })
  }, [form.error, draft])
  useEffect(() => {
    if (observed.current !== generation) { observed.current = generation; setFailedFormRead(undefined); view.current++; setDraft(undefined); setError('') }
    else if (denied) { view.current++; setDraft(undefined); setError('') }
  }, [generation, denied])
  async function refresh() {
    const current = workflowActions.generation()
    try {
      await Promise.all([methods.refetch({ throwOnError: true }), providers.refetch({ throwOnError: true }),
        failedFormRead ? admin.read('billing.admin-payment-methods.form', failedFormRead) : Promise.resolve()])
      if (current !== workflowActions.generation()) return
      setFailedFormRead(undefined)
      workflowActions.confirmReadAuthorization(prefix); workflowActions.clearError(); setError('')
    } catch { /* The failed authorization check remains visible. */ }
  }
  function edit(row?: Method) {
    if (locked) return
    view.current++
    setDraft({ row, name: row?.name ?? '', provider: row?.provider ?? providers.data?.data[0]?.provider ?? '',
      icon: row?.icon ?? '', enabled: row?.enabled ?? false, sort: String(row?.sort ?? 0), configuration: {} })
    setError('')
  }
  async function perform(input: Save) {
    const current = workflowActions.generation(), displayed = view.current
    setError('')
    try {
      await workflowActions.run('billing.admin-payment-methods.save', input)
      if (current !== workflowActions.generation() || displayed !== view.current) return
      setDraft(undefined); await refresh()
    } catch (failure) {
      if (current !== workflowActions.generation() || displayed !== view.current) return
      if (failure instanceof CoreAdminApiError && failure.status === 409 && !workflowActions.snapshot().pending) {
        setDraft(undefined); await refresh()
        if (current === workflowActions.generation() && displayed === view.current) setError('支付方式已变化，请按刷新后的配置重新编辑。')
        return
      }
      setError(managementError(failure))
    }
  }
  async function save() {
    if (!draft || locked || !form.data || form.isError || form.isFetching) return
    if (!draft.name.trim() || !/^(0|[1-9][0-9]{0,9})$/.test(draft.sort) || Number(draft.sort) > 2147483647) {
      setError('请填写支付方式名称和有效的排序值。'); return
    }
    await perform({ ...(draft.row ? { payment_id: draft.row.id, expected_version: draft.row.version } : {}),
      name: draft.name.trim(), provider: draft.provider, icon: draft.icon.trim() || null, enabled: draft.enabled,
      sort: Number(draft.sort), configuration: Object.entries(draft.configuration)
        .filter(([, value]) => value !== '').map(([name, value]) => ({ name, value })) })
  }
  async function recover() {
    if (!pending || denied || state.busy || methods.isFetching || providers.isFetching || !readable || !methods.data || !providers.data) return
    await perform(pending.input)
  }
  const columns: Column<Method>[] = [
    { key: 'name', header: '支付方式', render: row => <div className="native-row-identity"><strong>{row.name}</strong><small>#{row.id} · {row.provider}</small></div> },
    { key: 'state', header: '状态', render: row => <span>{row.enabled ? '已启用' : '已停用'} · {row.available ? '当前可结账' : '当前不可结账'}</span> },
    { key: 'fees', header: '手续费', render: row => row.fee_fixed_minor + ' 最小货币单位 + ' + row.fee_percent + '%' },
    { key: 'sort', header: '排序', render: row => row.sort },
    { key: 'action', header: '操作', render: row => <button className="button" disabled={locked} onClick={() => edit(row)}>配置支付方式</button> },
  ]
  return <div className="native-management-page">
    <PageHeader title="支付配置" description="管理原生支付方式。已有订单和支付尝试保留各自冻结的支付资料。"
      action={<><button className="button" disabled={state.busy} onClick={() => void refresh()}>刷新支付配置</button><button className="button primary" disabled={locked || !providers.data?.data.length} onClick={() => edit()}>新增支付方式</button></>} />
    <ManagementFeedback error={providers.error} loading={providers.isFetching} onRetry={() => void refresh()} />
    {pending ? <section className="list-card" role="status"><h2>支付配置等待确认</h2><p>继续保留原输入与操作标识，请确认原操作结果后再编辑。</p><button className="button" disabled={denied || state.busy || !readable || methods.isFetching || providers.isFetching} onClick={() => void recover()}>使用原操作确认结果</button></section> : null}
    {error || state.error ? <p role="alert">{error || managementError(state.error)}</p> : null}
    {denied ? <p role="alert">无法确认支付配置权限，已隐藏数据。</p> : null}
    <ReadList title="支付方式" rows={readable ? methods.data?.data ?? [] : []} columns={columns} page={page}
      meta={readable ? methods.data?.meta : undefined} ready={readable && !!methods.data}
      loading={methods.isFetching} error={methods.error} onRetry={() => void refresh()} onPage={setPage} />
    {readable && providers.data ? <section className="list-card"><h2>可用支付提供方</h2>
      {providers.data.data.length ? <ul>{providers.data.data.map(provider => <li key={provider.provider}>{provider.provider} · {provider.currencies.join(' / ')} · {provider.external_refund ? '支持渠道退款' : '渠道退款未开放'}</li>)}</ul> : <p>当前没有通过原生能力检查的支付提供方，请先完成支付模块的受控部署。</p>}
      <p>当前原生结账支持零手续费渠道；可用状态还取决于提供方、商户配置和币种校验。</p>
    </section> : null}
    {draft && readable ? <section className="list-card"><h2>{draft.row ? '编辑支付方式' : '新增支付方式'}</h2>
      <form className="native-content-form" onSubmit={event => { event.preventDefault(); void save() }}>
        <label>名称<input required maxLength={255} value={draft.name} disabled={locked} onChange={event => setDraft({ ...draft, name: event.target.value })} /></label>
        <label>支付提供方<select value={draft.provider} disabled={locked || !!draft.row} onChange={event => setDraft({ ...draft, provider: event.target.value, configuration: {} })}>
          {draft.row && !providers.data?.data.some(row => row.provider === draft.provider) ? <option value={draft.provider}>{draft.provider}</option> : null}
          {providers.data?.data.map(row => <option key={row.provider} value={row.provider}>{row.provider}</option>)}</select></label>
        <label>图标地址<input maxLength={2048} value={draft.icon} disabled={locked} onChange={event => setDraft({ ...draft, icon: event.target.value })} /></label>
        <label>排序<input inputMode="numeric" value={draft.sort} disabled={locked} onChange={event => setDraft({ ...draft, sort: event.target.value })} /></label>
        <label><input type="checkbox" checked={draft.enabled} disabled={locked} onChange={event => setDraft({ ...draft, enabled: event.target.checked })} />启用此支付方式</label>
        <ManagementFeedback error={form.error} loading={form.isFetching} onRetry={() => void form.refetch()} />
        {form.data && !form.isError ? <><p>已有商户配置不会回显；留空保留已保存的值。新建支付方式时请填写提供方要求的资料。</p>
          {form.data.data.fields.map(field => <label key={field.name}>{field.label}（{field.configured ? '已配置' : '未配置'}）<input autoComplete="off" type={field.kind === 'password' ? 'password' : 'text'} maxLength={8192}
            value={draft.configuration[field.name] ?? ''} disabled={locked} onChange={event => setDraft({ ...draft, configuration: { ...draft.configuration, [field.name]: event.target.value } })} /></label>)}</> : null}
        <button className="button primary" disabled={locked || !form.data || form.isError || form.isFetching}>保存支付配置</button>
        <button className="button" type="button" disabled={state.busy} onClick={() => { view.current++; setDraft(undefined) }}>关闭编辑</button>
      </form>
    </section> : null}
  </div>
}
