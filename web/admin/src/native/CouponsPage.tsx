import { t, useLocale, getLocale } from '../lib/i18n'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { useListPagination } from './useListPagination'
import './commerce-reference.css'
import { useEffect, useState, useSyncExternalStore } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreInput, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { managementError } from './Feedback'
import { ReadList } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import { Popover } from '../components/ui/Popover'
import { Field, Input, Select } from '../components/ui/Field'
import type { Column } from '../components/ui/DataTable'
import { commerceMoney } from './commerce-format'
import { CopyButton } from '../components/ui/CopyButton'
import { OfferSelector } from './OfferSelector'

type Coupon = CoreOutput<'billing.coupons.list'>[number]
const blank = () => ({ code: '', name: '', kind: 'fixed' as 'fixed' | 'basis_points', value: '100', minimum: '0', total: 100, perUser: 1, starts: '', expires: '', offers: [] as string[], scope: 'all' as 'all' | 'selected' })
function timestamp(value: string) { return value ? Math.floor(Date.parse(value) / 1000) : null }
function localDate(value: number) { const date = new Date(value); return new Date(value - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16) }

export function CouponsPage() {
  useLocale()
  const client = useQueryClient(), state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const { page, setPage, perPage, setPerPage } = useListPagination(50)
  const [draft, setDraft] = useState(blank), [editing, setEditing] = useState(false), [error, setError] = useState('')
  const [offersReady, setOffersReady] = useState(false)
  const list = useQuery({ queryKey: ['native-admin', 'coupons', page, perPage], queryFn: ({ signal }) => admin.read('billing.coupons.list', { page, per_page: perPage }, signal) })
  const denied = [list.error, !state.errorOperation || state.errorOperation.startsWith('billing.coupons.') ? state.error : undefined].some(failure => failure instanceof CoreAdminApiError && [401, 403].includes(failure.status))
  const pending = state.pending?.operation.startsWith('billing.coupons.') ? state.pending : undefined
  const locked = denied || state.busy || !!state.pending
  const generation = workflowActions.generation()
  const dirty = editing && JSON.stringify(draft) !== JSON.stringify(blank())
  useDirtyDraft(dirty && !denied && !state.pending)
  useEffect(() => { setDraft(blank()); setEditing(false); setError('') }, [generation])
  useEffect(() => { if (denied) { setDraft(blank()); setEditing(false) } }, [denied])
  useEffect(() => {
    if (!dirty) return
    const beforeUnload = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', beforeUnload)
    return () => window.removeEventListener('beforeunload', beforeUnload)
  }, [dirty])
  async function refresh() { const generation = workflowActions.generation(); await client.invalidateQueries({ queryKey: ['native-admin', 'coupons'] }); if (generation === workflowActions.generation() && client.getQueryCache().findAll({ queryKey: ['native-admin', 'coupons'] }).filter(query => query.isActive()).every(query => query.state.status === 'success')) { workflowActions.confirmReadAuthorization('billing.coupons.'); workflowActions.clearError(); setError('') } }
  async function perform(run: () => Promise<void>) { const generation = workflowActions.generation(); setError(''); try { await run() } catch (failure) { if (generation !== workflowActions.generation()) return; setError(managementError(failure, false)); if (failure instanceof CoreAdminApiError && failure.status === 409 && !failure.needsStateConfirmation) await refresh() } }
  async function create() {
    if (locked) return
    await perform(async () => {
      const value = Number(draft.value), maximum = draft.kind === 'basis_points' ? 10000 : 9999999999
      if (!Number.isSafeInteger(value) || value < 1 || value > maximum) { setError('请填写有效范围内的折扣整数'); return }
      const starts = timestamp(draft.starts), expires = timestamp(draft.expires)
      if (starts !== null && !Number.isFinite(starts) || expires !== null && (!Number.isFinite(expires) || expires <= (starts ?? Date.now() / 1000))) { setError('结束时间必须晚于开始时间，立即生效时必须晚于当前时间'); return }
      if (draft.scope === 'selected' && (!offersReady || !draft.offers.length)) { setError('请成功读取并选择至少一个适用商品'); return }
      const input: CoreInput<'billing.coupons.create'> = { code: draft.code.trim().toUpperCase(), name: draft.name, currency: 'CNY', discount_kind: draft.kind, value: String(value), minimum_minor: draft.minimum, total_limit: draft.total, user_limit: draft.perUser, starts_at: starts, expires_at: expires, offer_ids: draft.scope === 'selected' ? draft.offers : [] }
      await workflowActions.run('billing.coupons.create', input); setDraft(blank()); setEditing(false); await refresh()
    })
  }
  async function disable(row: Coupon) { if (locked) return; await perform(async () => { await workflowActions.run('billing.coupons.disable', { coupon_id: row.id, expected_version: row.row_version }); await refresh() }) }
  async function recover() { const action = pending; if (!action || denied || state.busy) return; await perform(async () => { await workflowActions.run(action.operation, action.input); setDraft(blank()); setEditing(false); await refresh() }) }
  function update<K extends keyof ReturnType<typeof blank>>(key: K, value: ReturnType<typeof blank>[K]) { setDraft(old => ({ ...old, [key]: value })) }
  const columns: Column<Coupon>[] = [
    { key: 'code', header: t("优惠券"), render: row => <div className="native-row-identity"><strong>{row.name}</strong><span className="commerce-copy-value"><code>{row.code}</code><CopyButton value={row.code} label={t("复制优惠码")} /></span><small>#{row.id}</small></div> },
    { key: 'value', header: t("折扣与门槛"), render: row => <div className="commerce-cell-stack"><strong className="commerce-amount">{row.discount_kind === 'fixed' ? t("减 ") + commerceMoney(row.value, row.currency) : t("减免 ") + (Number(row.value) / 100).toLocaleString(getLocale(), { maximumFractionDigits: 2 }) + '%'}</strong><small>{row.minimum_minor === '0' ? t("无最低金额限制") : t("满 ") + commerceMoney(row.minimum_minor, row.currency) + t(" 可用")}</small></div> },
    { key: 'uses', header: t("使用次数"), render: row => <div className="commerce-cell-stack"><strong>{row.used_total} / {row.total_limit}</strong><small>{t("每人上限")}{row.user_limit}{t("次")}</small></div> },
    { key: 'window', header: t("有效期"), render: row => <div className="commerce-cell-stack commerce-date-range"><span>{t("起")}{row.starts_at === null ? t("立即") : new Date(row.starts_at * 1000).toLocaleString(getLocale())}</span><small>{t("止")}{row.expires_at === null ? t("长期") : new Date(row.expires_at * 1000).toLocaleString(getLocale())}</small></div> },
    { key: 'offers', header: t("适用商品"), render: row => <div className="commerce-cell-stack"><span>{row.offer_ids.length ? t("指定 ") + row.offer_ids.length + t(" 个商品") : t("全部商品")}</span>{row.offer_ids.length ? <small>{t("商品 ID：")}{row.offer_ids.join('、')}</small> : null}</div> },
    { key: 'state', header: t("状态"), render: row => <div className="commerce-cell-stack"><span className={row.enabled ? 'commerce-badge success' : 'commerce-badge'}>{row.enabled ? t("启用") : t("已停用")}</span>{row.enabled ? <small>{row.expires_at !== null && row.expires_at <= Date.now() / 1000 ? t("已过有效期") : row.starts_at !== null && row.starts_at > Date.now() / 1000 ? t("尚未开始") : row.used_total >= row.total_limit ? t("使用额度已用尽") : t("有效期内 · 尚有额度")}</small> : null}</div> },
    { key: 'disable', header: t("操作"), pin: 'right', hideable: false, render: row => <button className="button" disabled={locked || !row.enabled} title={t("停用后无法用于新订单")} onClick={() => void disable(row)}>{t("停用")}</button> },
  ]
  return <div className="native-management-page commerce-reference coupons-page">
    <PageHeader title={t("优惠券管理")} description={t("配置折扣、使用次数、用户限制、有效期和适用商品")} action={<>
      <Popover label={t("优惠券使用规则")}><p>{t("优惠内容创建后固定，可随时停用 · 创建订单即占用一次使用次数，取消或退款不返还次数 · 退款以订单实付金额为限，暂不与升级抵扣叠加")}</p></Popover>
      <button className="button" disabled={list.isFetching} onClick={() => void refresh()}>{t("刷新")}</button><button className="button primary" disabled={locked} onClick={() => setEditing(true)}>{t("创建优惠券")}</button>
    </>} />
    {pending ? <section className="list-card commerce-pending" role="status"><h2>{t("操作等待确认")}</h2><p>{t("确认上次结果后，可继续管理优惠券。")}</p><details className="commerce-help"><summary>{t("查看操作标识")}</summary><code>{pending.operation} · {pending.key}</code></details><button className="button" disabled={denied || state.busy} onClick={() => void recover()}>{t("使用原操作确认结果")}</button></section> : null}
    {error || state.error ? <p role="alert">{error ? t(error) : managementError(state.error)}</p> : null}
    {denied ? <p role="alert">{t("无法确认优惠券管理权限，已隐藏数据")}</p> : null}
    <ReadList mobilePrimaryKey="code" maxPageSize={50} title={"优惠券"} rows={denied || list.isError ? [] : list.data?.data ?? []} columns={columns} meta={denied || list.isError ? undefined : list.data?.meta} perPage={perPage} onPageSize={setPerPage} page={page} ready={!!list.data && !denied} loading={list.isFetching} error={list.isError ? list.error : undefined} onRetry={() => void refresh()} onPage={setPage} columnSettingsKey="coupons" stickyHeader stickyFirstColumn><header className="commerce-card-heading"><div><h2>{t("优惠券")}</h2><p>{t("核对折扣、使用额度与有效期")}</p></div></header></ReadList>
    {editing && !denied ? <section className="list-card commerce-editor"><header className="commerce-card-heading"><div><h2>{t("创建优惠券")}</h2><p>{t("设置优惠内容、使用限额与适用范围")}</p></div><span className="commerce-badge">{t("新优惠")}</span></header><form className="native-content-form commerce-form-grid" onSubmit={event => { event.preventDefault(); void create() }}>
      <div className="commerce-section-label"><h3>{t("基本信息")}</h3><p>{t("优惠码由用户在结账时填写")}</p></div>
      <Field label={t("优惠码")}><Input autoFocus placeholder={t("例如 WELCOME2026")} value={draft.code} required minLength={8} maxLength={64} pattern="[A-Za-z0-9][A-Za-z0-9_-]{7,63}" disabled={locked} onChange={event => update('code', event.target.value)} /></Field>
      <Field label={t("名称")}><Input value={draft.name} required maxLength={120} disabled={locked} onChange={event => update('name', event.target.value)} /></Field>
      <div className="commerce-section-label"><h3>{t("折扣与用量")}</h3><p>{t("金额以分填写，100 分等于 1 元")}</p></div>
      <Field label={t("折扣方式")}><Select value={draft.kind} disabled={locked} onChange={event => update('kind', event.target.value as typeof draft.kind)}><option value="fixed">{t("固定金额（分）")}</option><option value="basis_points">{t("比例（每万份）")}</option></Select></Field>
      <Field label={draft.kind === 'fixed' ? t("减免金额（分）") : t("减免比例（1000 表示减免 10%）")} hint={/^[1-9][0-9]*$/.test(draft.value) ? draft.kind === 'fixed' ? t("减免 ") + commerceMoney(draft.value, 'CNY') : t("减免 ") + Number(draft.value) / 100 + '%' : t("请填写正整数")}><Input type="number" min={1} max={draft.kind === 'basis_points' ? 10000 : 9999999999} step={1} value={draft.value} required inputMode="numeric" disabled={locked} onChange={event => update('value', event.target.value)} /></Field>
      <Field label={t("最低商品金额（分）")} hint={/^\d+$/.test(draft.minimum) ? draft.minimum === '0' ? t("0 表示不限制最低金额") : t("满 ") + commerceMoney(draft.minimum, 'CNY') + t(" 可用") : undefined}><Input value={draft.minimum} required pattern="0|[1-9][0-9]{0,9}" inputMode="numeric" disabled={locked} onChange={event => update('minimum', event.target.value)} /></Field>
      <Field label={t("总使用上限")}><Input type="number" value={draft.total} min={1} max={1000000000} required disabled={locked} onChange={event => update('total', Number(event.target.value))} /></Field>
      <Field label={t("每人使用上限")}><Input type="number" value={draft.perUser} min={1} max={draft.total} required disabled={locked} onChange={event => update('perUser', Number(event.target.value))} /></Field>
      <div className="commerce-section-label"><h3>{t("有效期与范围")}</h3><p>{t("留空使用默认范围")}</p></div>
      <Field label={t("开始时间（留空立即）")}><Input type="datetime-local" value={draft.starts} disabled={locked} onChange={event => update('starts', event.target.value)} /></Field>
      <Field label={t("结束时间（留空长期）")}><Input type="datetime-local" value={draft.expires} disabled={locked} onChange={event => update('expires', event.target.value)} /></Field>
      <div className="commerce-wide-field commerce-date-presets" role="group" aria-label={t("优惠券有效期快捷设置")}>{[7, 30, 90].map(days => <button type="button" className="button" key={days} disabled={locked} onClick={() => setDraft(current => ({ ...current, starts: '', expires: localDate(Date.now() + days * 86400000) }))}>{t('立即起 {days} 天', { days })}</button>)}<button className="button" type="button" disabled={locked} onClick={() => setDraft(current => ({ ...current, starts: '', expires: '' }))}>{t("立即起长期有效")}</button></div>
      <Field label={t("适用商品范围")} className="commerce-wide-field"><Select value={draft.scope} disabled={locked} onChange={event => update('scope', event.target.value as typeof draft.scope)}><option value="all">{t("全部商品")}</option><option value="selected">{t("指定商品")}</option></Select></Field>
      {draft.scope === 'selected' ? <div className="commerce-wide-field"><OfferSelector value={draft.offers} onChange={ids => update('offers', ids)} onReady={setOffersReady} disabled={locked} /></div> : null}
      <footer className="commerce-form-actions"><span>{t("创建后可停用，优惠内容不可修改")}</span><button className="button primary" disabled={locked || draft.scope === 'selected' && (!offersReady || !draft.offers.length)}>{t("创建")}</button><button className="button" type="button" disabled={state.busy} onClick={() => { if (!dirty || window.confirm(t("关闭后将丢弃未保存的优惠券草稿，是否继续？"))) { setEditing(false); setDraft(blank()) } }}>{t("关闭")}</button></footer>
    </form></section> : null}
  </div>
}
