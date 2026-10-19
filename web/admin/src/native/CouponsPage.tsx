import './commerce-reference.css'
import { useState, useSyncExternalStore } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreInput, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { managementError } from './Feedback'
import { ReadList } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import type { Column } from '../components/ui/DataTable'

type Coupon = CoreOutput<'billing.coupons.list'>[number]
const blank = () => ({ code: '', name: '', kind: 'fixed' as 'fixed' | 'basis_points', value: '100', minimum: '0', total: 100, perUser: 1, starts: '', expires: '', offers: '' })
function timestamp(value: string) { return value ? Math.floor(Date.parse(value) / 1000) : null }

export function CouponsPage() {
  const client = useQueryClient(), state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [page, setPage] = useState(1), [draft, setDraft] = useState(blank), [editing, setEditing] = useState(false), [error, setError] = useState('')
  const list = useQuery({ queryKey: ['native-admin', 'coupons', page], queryFn: ({ signal }) => admin.read('billing.coupons.list', { page, per_page: 20 }, signal) })
  const denied = [list.error, state.error].some(failure => failure instanceof CoreAdminApiError && [401, 403].includes(failure.status))
  const pending = state.pending?.operation.startsWith('billing.coupons.') ? state.pending : undefined
  const locked = denied || state.busy || !!state.pending
  async function refresh() { const generation = workflowActions.generation(); await client.invalidateQueries({ queryKey: ['native-admin', 'coupons'] }); if (generation === workflowActions.generation() && client.getQueryCache().findAll({ queryKey: ['native-admin', 'coupons'] }).filter(query => query.isActive()).every(query => query.state.status === 'success')) { workflowActions.confirmReadAuthorization('billing.coupons.'); workflowActions.clearError(); setError('') } }
  async function perform(run: () => Promise<void>) { const generation = workflowActions.generation(); setError(''); try { await run() } catch (failure) { if (generation !== workflowActions.generation()) return; setError(managementError(failure)); if (failure instanceof CoreAdminApiError && failure.status === 409 && !failure.needsStateConfirmation) await refresh() } }
  async function create() {
    if (locked) return
    await perform(async () => {
      const input: CoreInput<'billing.coupons.create'> = { code: draft.code.trim().toUpperCase(), name: draft.name, currency: 'CNY', discount_kind: draft.kind, value: draft.value, minimum_minor: draft.minimum, total_limit: draft.total, user_limit: draft.perUser, starts_at: timestamp(draft.starts), expires_at: timestamp(draft.expires), offer_ids: draft.offers.split(',').map(value => value.trim()).filter(Boolean) }
      await workflowActions.run('billing.coupons.create', input); setDraft(blank()); setEditing(false); await refresh()
    })
  }
  async function disable(row: Coupon) { if (locked) return; await perform(async () => { await workflowActions.run('billing.coupons.disable', { coupon_id: row.id, expected_version: row.row_version }); await refresh() }) }
  async function recover() { const action = pending; if (!action || denied || state.busy) return; await perform(async () => { await workflowActions.run(action.operation, action.input); setDraft(blank()); setEditing(false); await refresh() }) }
  function update<K extends keyof ReturnType<typeof blank>>(key: K, value: ReturnType<typeof blank>[K]) { setDraft(old => ({ ...old, [key]: value })) }
  const columns: Column<Coupon>[] = [
    { key: 'code', header: '优惠券', render: row => <div className="native-row-identity"><strong>{row.name}</strong><small>{row.code} · #{row.id}</small></div> },
    { key: 'value', header: '折扣', render: row => row.discount_kind === 'fixed' ? row.value + ' 分' : row.value + ' / 10000' },
    { key: 'uses', header: '使用次数', render: row => row.used_total + ' / ' + row.total_limit + ' · 每人 ' + row.user_limit },
    { key: 'window', header: '有效期', render: row => (row.starts_at === null ? '立即' : new Date(row.starts_at * 1000).toLocaleString()) + ' → ' + (row.expires_at === null ? '长期' : new Date(row.expires_at * 1000).toLocaleString()) },
    { key: 'offers', header: '适用商品', render: row => row.offer_ids.length ? row.offer_ids.join(', ') : '全部' },
    { key: 'state', header: '状态', render: row => <span className={row.enabled ? 'commerce-badge success' : 'commerce-badge'}>{row.enabled ? '启用' : '已停用'}</span> },
    { key: 'disable', header: '操作', render: row => <button className="button" disabled={locked || !row.enabled} title="停用后无法用于新订单" onClick={() => void disable(row)}>停用</button> },
  ]
  return <div className="native-management-page commerce-reference">
    <PageHeader title="优惠券管理" description="配置折扣、使用次数、用户限制、有效期和适用商品" action={<><button className="button" disabled={list.isFetching} onClick={() => void refresh()}>刷新</button><button className="button primary" disabled={locked} onClick={() => setEditing(true)}>创建优惠券</button></>} />
    <details className="commerce-help"><summary>优惠券使用规则</summary><p>优惠内容创建后固定，可随时停用；创建订单即占用一次使用次数，取消或退款不返还次数；退款以订单实付金额为限，暂不与升级抵扣叠加</p></details>
    {pending ? <section className="list-card" role="status"><h2>操作等待确认</h2><p>{pending.operation} · {pending.key}</p><button className="button" disabled={denied || state.busy} onClick={() => void recover()}>使用原操作确认结果</button></section> : null}
    {error || state.error ? <p role="alert">{error || managementError(state.error)}</p> : null}
    {denied ? <p role="alert">无法确认优惠券管理权限，已隐藏数据</p> : null}
    <ReadList title="优惠券" rows={denied || list.isError ? [] : list.data?.data ?? []} columns={columns} meta={denied || list.isError ? undefined : list.data?.meta} page={page} ready={!!list.data && !denied} loading={list.isFetching} error={list.isError ? list.error : undefined} onRetry={() => void refresh()} onPage={setPage} />
    {editing && !denied ? <section className="list-card commerce-editor"><header className="commerce-card-heading"><div><h2>创建优惠券</h2><p>设置优惠内容、使用限额与适用范围</p></div><span className="commerce-badge">新优惠</span></header><form className="native-content-form commerce-form-grid" onSubmit={event => { event.preventDefault(); void create() }}>
      <div className="commerce-section-label"><h3>基本信息</h3><p>优惠码由用户在结账时填写</p></div><label>优惠码<input autoFocus placeholder="例如 WELCOME2026" value={draft.code} required minLength={8} maxLength={64} pattern="[A-Za-z0-9][A-Za-z0-9_-]{7,63}" disabled={locked} onChange={event => update('code', event.target.value)} /></label>
      <label>名称<input value={draft.name} required maxLength={120} disabled={locked} onChange={event => update('name', event.target.value)} /></label>
      <div className="commerce-section-label"><h3>折扣与用量</h3><p>金额以分填写，100 分等于 1 元</p></div><label>折扣方式<select value={draft.kind} disabled={locked} onChange={event => update('kind', event.target.value as typeof draft.kind)}><option value="fixed">固定金额（分）</option><option value="basis_points">比例（每万份）</option></select></label>
      <label>{draft.kind === 'fixed' ? '减免金额（分）' : '减免比例（1000 表示减免 10%）'}<input value={draft.value} required pattern="[1-9][0-9]{0,9}" inputMode="numeric" disabled={locked} onChange={event => update('value', event.target.value)} /></label>
      <label>最低商品金额（分）<input value={draft.minimum} required pattern="0|[1-9][0-9]{0,9}" inputMode="numeric" disabled={locked} onChange={event => update('minimum', event.target.value)} /></label>
      <label>总使用上限<input type="number" value={draft.total} min={1} max={1000000000} required disabled={locked} onChange={event => update('total', Number(event.target.value))} /></label>
      <label>每人使用上限<input type="number" value={draft.perUser} min={1} max={draft.total} required disabled={locked} onChange={event => update('perUser', Number(event.target.value))} /></label>
      <div className="commerce-section-label"><h3>有效期与范围</h3><p>留空使用默认范围</p></div><label>开始时间（留空立即）<input type="datetime-local" value={draft.starts} disabled={locked} onChange={event => update('starts', event.target.value)} /></label>
      <label>结束时间（留空长期）<input type="datetime-local" value={draft.expires} disabled={locked} onChange={event => update('expires', event.target.value)} /></label>
      <label>适用商品 ID（英文逗号分隔，留空全部）<input value={draft.offers} disabled={locked} onChange={event => update('offers', event.target.value)} /></label>
      <footer className="commerce-form-actions"><span>创建后可停用，优惠内容不可修改</span><button className="button primary" disabled={locked}>创建</button><button className="button" type="button" onClick={() => setEditing(false)}>关闭</button></footer>
    </form></section> : null}
  </div>
}
