import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CoreAdminApiError, coreOperations, type CoreInput, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { managementError, ManagementFeedback } from './Feedback'
import { ReadFields, ReadList } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import type { Column } from '../components/ui/DataTable'

type Plan = CoreOutput<'catalog.admin-plans.list'>[number]
type PlanDetail = CoreOutput<'catalog.admin-plans.get'>
type Revision = CoreOutput<'catalog.admin-plans.history.list'>[number]
type Offer = CoreOutput<'catalog.admin-offers.list'>[number]
type Publication = CoreInput<'catalog.plans.publish'>
const periods = [
  ['monthly', '月付'], ['quarterly', '季付'], ['half_yearly', '半年付'], ['yearly', '年付'],
  ['two_yearly', '两年付'], ['three_yearly', '三年付'], ['onetime', '一次性'], ['reset_traffic', '流量重置'],
] as const
type Period = typeof periods[number][0]
type Draft = {
  planId?: string; expectedRevision?: string; name: string; content: string | null; tags: string[]
  quota: string; group: string; speed: string; devices: string; reset: string; currency: string
  prices: Record<Period, string>; capacityChoice: '' | 'unlimited' | 'limited'; capacity: string
}
type Availability = { offer: Offer; show: boolean; sell: boolean; renew: boolean; capacity: string; conflict: boolean }
const denied = (error: unknown) => error instanceof CoreAdminApiError && [401, 403].includes(error.status)
const statusName = (status: string) => status === 'published' ? '当前发布' : status === 'superseded' ? '已被新修订替代' : status
const periodName = (period: string) => periods.find(([key]) => key === period)?.[1] ?? period
const resetNames = ['每月 1 日', '按月重置', '不重置', '每年 1 月 1 日', '按年重置']
function blank(): Draft {
  return { name: '', content: null, tags: [], quota: '0', group: '', speed: '', devices: '', reset: '0', currency: 'CNY',
    prices: Object.fromEntries(periods.map(([key]) => [key, ''])) as Record<Period, string>, capacityChoice: '', capacity: '' }
}
function fromCurrent(detail: PlanDetail): Draft {
  const terms = detail.revision.terms
  return { ...blank(), planId: detail.plan_id, expectedRevision: detail.current_revision_id,
    name: terms.name, content: terms.content, tags: [...terms.tags], quota: String(terms.quota_gib),
    group: terms.group_id ?? '', speed: terms.speed_limit_mbps === null ? '' : String(terms.speed_limit_mbps),
    devices: terms.device_limit === null ? '' : String(terms.device_limit), reset: String(terms.reset_method), currency: terms.currency,
    prices: Object.fromEntries(periods.map(([key]) => [key, terms.prices_minor[key] ?? ''])) as Record<Period, string> }
}
function integer(value: string, label: string, maximum: number): number {
  if (!/^(0|[1-9][0-9]*)$/.test(value)) throw new Error(label + '须为非负整数。')
  const result = Number(value)
  if (!Number.isSafeInteger(result) || result > maximum) throw new Error(label + '超出允许范围。')
  return result
}
function nullableInteger(value: string, label: string): number | null { return value === '' ? null : integer(value, label, 4294967295) }
function publication(draft: Draft): Publication {
  if (!draft.name.trim()) throw new Error('请填写套餐名称。')
  if (!['CNY', 'USD'].includes(draft.currency)) throw new Error('原币种不支持新发布，请明确选择 CNY 或 USD。')
  if (!draft.capacityChoice) throw new Error('请明确选择本次发布的 Offer 名额。')
  const prices: Partial<Record<Period, string>> = {}
  for (const [period] of periods) {
    const price = draft.prices[period]
    if (price === '') continue
    if (!/^(0|[1-9][0-9]{0,18})$/.test(price) || BigInt(price) > 9223372036854775807n) throw new Error(periodName(period) + '价格须为有效的最小货币单位整数。')
    prices[period] = price
  }
  if (!Object.keys(prices).length) throw new Error('至少填写一个周期价格。')
  const input = { ...(draft.planId ? { plan_id: draft.planId, expected_revision_id: draft.expectedRevision } : {}),
    name: draft.name, content: draft.content, tags: draft.tags, quota_gib: integer(draft.quota, '流量', 8589934591),
    group_id: draft.group || null, speed_limit_mbps: nullableInteger(draft.speed, '速度限制'), device_limit: nullableInteger(draft.devices, '设备限制'),
    capacity_limit: draft.capacityChoice === 'unlimited' ? null : integer(draft.capacity, 'Offer 名额', 4294967295),
    reset_method: integer(draft.reset, '重置方式', 4), currency: draft.currency, prices_minor: prices }
  const parsed = coreOperations['catalog.plans.publish'].input.safeParse(input)
  if (!parsed.success) throw new Error('套餐条款未通过校验，请检查名称、标签、分组 ID 和数值范围。')
  return parsed.data
}

export function CatalogPage() {
  const client = useQueryClient(), state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [planPage, setPlanPage] = useState(1), [offerPage, setOfferPage] = useState(1), [historyPage, setHistoryPage] = useState(1)
  const [filterDraft, setFilterDraft] = useState(''), [planFilter, setPlanFilter] = useState('')
  const [status, setStatus] = useState<'published' | 'superseded' | ''>('published')
  const [selected, setSelected] = useState(''), [revision, setRevision] = useState('')
  const [draft, setDraft] = useState<Draft>(), [availability, setAvailability] = useState<Availability>()
  const [error, setError] = useState(''), [conflict, setConflict] = useState(false), [opening, setOpening] = useState(false)
  const [failedRead, setFailedRead] = useState<CoreInput<'catalog.admin-plans.get'>>()
  const view = useRef(0)
  const generation = workflowActions.generation(), observedGeneration = useRef(generation)
  useEffect(() => () => { view.current++ }, [])
  useEffect(() => {
    if (observedGeneration.current === generation) return
    observedGeneration.current = generation; view.current++
    setDraft(undefined); setAvailability(undefined); setOpening(false); setFailedRead(undefined); setConflict(false); setError('')
  }, [generation])
  const plans = useQuery({ queryKey: ['native-admin', 'catalog', 'plans', planPage],
    queryFn: ({ signal }) => admin.read('catalog.admin-plans.list', { page: planPage, per_page: 20 }, signal) })
  const offerInput: CoreInput<'catalog.admin-offers.list'> = { page: offerPage, per_page: 20,
    ...(planFilter ? { plan_id: planFilter } : {}), ...(status ? { status } : {}) }
  const offers = useQuery({ queryKey: ['native-admin', 'catalog', 'offers', offerInput],
    queryFn: ({ signal }) => admin.read('catalog.admin-offers.list', offerInput, signal) })
  const detail = useQuery({ queryKey: ['native-admin', 'catalog', 'detail', selected, revision], enabled: !!selected,
    queryFn: ({ signal }) => admin.read('catalog.admin-plans.get', { plan_id: selected, ...(revision ? { revision_id: revision } : {}) }, signal) })
  const history = useQuery({ queryKey: ['native-admin', 'catalog', 'history', selected, historyPage], enabled: !!selected,
    queryFn: ({ signal }) => admin.read('catalog.admin-plans.history.list', { plan_id: selected, page: historyPage, per_page: 20 }, signal) })
  const pending = state.pending?.operation.startsWith('catalog.') ? state.pending : undefined
  const blocked = !!failedRead || [plans.error, offers.error, selected && detail.error, selected && history.error, state.error].some(denied)
  const locked = blocked || state.busy || !!state.pending || opening || plans.isError || offers.isError
  useEffect(() => {
    if (blocked) { view.current++; setDraft(undefined); setAvailability(undefined); setOpening(false) }
  }, [blocked])
  async function refresh() {
    const generation = workflowActions.generation()
    try {
      await Promise.all([
        client.refetchQueries({ queryKey: ['native-admin', 'catalog'], type: 'active' }, { throwOnError: true }),
        failedRead ? admin.read('catalog.admin-plans.get', failedRead) : Promise.resolve(),
      ])
      if (generation !== workflowActions.generation()) return
      setFailedRead(undefined); setError('')
      workflowActions.clearError(); workflowActions.confirmReadAuthorization('catalog.')
    } catch { /* Query feedback keeps the failed authorization check visible. */ }
  }
  function selectPlan(id: string) { setSelected(id); setRevision(''); setHistoryPage(1) }
  async function openCurrent(id: string) {
    if (locked) return
    const generation = workflowActions.generation(), current = ++view.current
    setOpening(true); setError('')
    try {
      const result = await admin.read('catalog.admin-plans.get', { plan_id: id })
      if (generation !== workflowActions.generation() || current !== view.current) return
      if (result.data.revision.revision_id !== result.data.current_revision_id) throw new Error('当前套餐修订不一致，请重新读取。')
      selectPlan(id); setDraft(fromCurrent(result.data)); setConflict(false); setAvailability(undefined)
    } catch (failure) {
      if (generation !== workflowActions.generation() || current !== view.current) return
      setError(failure instanceof CoreAdminApiError && failure.status === 404 ? '套餐或修订不存在，请刷新列表。' : managementError(failure))
      if (denied(failure)) { setDraft(undefined); setAvailability(undefined); setFailedRead({ plan_id: id }) }
    } finally { if (generation === workflowActions.generation() && current === view.current) setOpening(false) }
  }
  async function perform(operation: 'catalog.plans.publish' | 'catalog.offers.availability.update', input: Publication | CoreInput<'catalog.offers.availability.update'>) {
    const generation = workflowActions.generation(), current = view.current
    setError('')
    try {
      if (operation === 'catalog.plans.publish') await workflowActions.run(operation, input as Publication)
      else await workflowActions.run(operation, input as CoreInput<'catalog.offers.availability.update'>)
      if (generation !== workflowActions.generation() || current !== view.current) return
      setDraft(undefined); setAvailability(undefined); setConflict(false); setRevision(''); await refresh()
    } catch (failure) {
      if (generation !== workflowActions.generation() || current !== view.current) return
      setError(managementError(failure))
      if (failure instanceof CoreAdminApiError && failure.status === 409 && !failure.needsStateConfirmation) {
        if (operation === 'catalog.plans.publish') setConflict(true)
        else setAvailability(old => old && { ...old, conflict: true })
        await refresh()
      }
    }
  }
  async function publish() {
    if (locked || !draft || conflict) return
    try { await perform('catalog.plans.publish', publication(draft)) } catch (failure) { setError(failure instanceof Error ? failure.message : '请检查套餐条款。') }
  }
  async function saveAvailability() {
    if (locked || !availability || availability.conflict || availability.offer.status !== 'published') return
    try {
      await perform('catalog.offers.availability.update', { offer_id: availability.offer.offer_id, expected_version: availability.offer.row_version,
        show: availability.show, sell: availability.sell, renew: availability.renew, capacity_limit: nullableInteger(availability.capacity, 'Offer 名额') })
    } catch (failure) { setError(failure instanceof Error ? failure.message : '请检查可用性设置。') }
  }
  async function reloadAvailability() {
    const old = availability
    if (locked || !old) return
    const generation = workflowActions.generation(), current = ++view.current
    setOpening(true); setError('')
    try {
      const result = await admin.read('catalog.admin-plans.get', { plan_id: old.offer.plan_id, revision_id: old.offer.revision_id })
      if (generation !== workflowActions.generation() || current !== view.current) return
      const row = result.data.offers.find(item => item.offer_id === old.offer.offer_id)
      if (!row || row.status !== 'published') { setAvailability(undefined); await refresh(); if (generation === workflowActions.generation() && current === view.current) setError('该 Offer 已不在当前发布中，请查看最新套餐。'); return }
      setAvailability({ offer: row, show: row.show, sell: row.sell, renew: row.renew, capacity: row.capacity_limit ?? '', conflict: false })
    } catch (failure) {
      if (generation === workflowActions.generation() && current === view.current) {
        setError(managementError(failure))
        if (denied(failure)) setFailedRead({ plan_id: old.offer.plan_id, revision_id: old.offer.revision_id })
      }
    } finally { if (generation === workflowActions.generation() && current === view.current) setOpening(false) }
  }
  async function recover() {
    const action = pending
    if (!action || blocked || state.busy) return
    const generation = workflowActions.generation(), current = view.current
    try {
      await workflowActions.run(action.operation, action.input)
      if (generation !== workflowActions.generation() || current !== view.current) return
      setDraft(undefined); setAvailability(undefined); setConflict(false); setError(''); setRevision(''); await refresh()
    } catch (failure) { if (generation === workflowActions.generation() && current === view.current) setError(managementError(failure)) }
  }
  function update<K extends keyof Draft>(key: K, value: Draft[K]) { setDraft(old => old && { ...old, [key]: value }) }
  const planColumns: Column<Plan & { id: string }>[] = [
    { key: 'name', header: '套餐', render: row => <div className="native-row-identity"><strong>{row.name}</strong><small>#{row.plan_id}</small></div> },
    { key: 'revision', header: '当前修订', render: row => '第 ' + row.revision_no + ' 版 · #' + row.current_revision_id },
    { key: 'currency', header: '币种', render: row => row.currency },
    { key: 'published', header: '发布时间', render: row => new Date(row.published_at * 1000).toLocaleString() },
    { key: 'actions', header: '操作', render: row => <><button className="button" disabled={locked} onClick={() => selectPlan(row.plan_id)}>查看完整条款</button><button className="button" disabled={locked} onClick={() => void openCurrent(row.plan_id)}>编辑当前套餐</button></> },
  ]
  const offerColumns: Column<Offer & { id: string }>[] = [
    { key: 'name', header: 'Offer / 套餐', render: row => <div className="native-row-identity"><strong>{row.name}</strong><small>Offer #{row.offer_id} · 套餐 #{row.plan_id}</small></div> },
    { key: 'period', header: '周期 / 冻结价格', render: row => <>{periodName(row.offer_code)}<br />{row.price_minor} 最小单位 {row.currency}</> },
    { key: 'status', header: '修订状态', render: row => <>{statusName(row.status)}<br />修订 #{row.revision_id}</> },
    { key: 'access', header: '当前可用性', render: row => <>{row.show ? '显示' : '隐藏'} · {row.sell ? '可新购' : '不新购'} · {row.renew ? '可续费' : '不续费'}<br />名额 {row.capacity_limit ?? '不限'} · 版本 {row.row_version}</> },
    { key: 'actions', header: '操作', render: row => <button className="button" disabled={locked || offers.isFetching || row.status !== 'published'} onClick={() => { view.current++; setAvailability({ offer: row, show: row.show, sell: row.sell, renew: row.renew, capacity: row.capacity_limit ?? '', conflict: false }); setDraft(undefined); setError('') }}>调整可用性</button> },
  ]
  const historyColumns: Column<Revision & { id: string }>[] = [
    { key: 'revision', header: '修订', render: row => '第 ' + row.revision_no + ' 版 · #' + row.revision_id },
    { key: 'name', header: '名称 / 币种', render: row => row.name + ' · ' + row.currency },
    { key: 'time', header: '发布时间', render: row => new Date(row.published_at * 1000).toLocaleString() },
    { key: 'open', header: '操作', render: row => <button className="button" disabled={locked} onClick={() => setRevision(row.revision_id)}>查看此修订</button> },
  ]
  const visible = !blocked && !detail.isError ? detail.data?.data : undefined
  return <div className="native-management-page">
    <PageHeader title="套餐与发布" description="发布完整套餐修订，并管理当前 Offer 的显示、销售与续费状态。" action={<><button className="button" disabled={plans.isFetching || offers.isFetching || opening} onClick={() => void refresh()}>刷新</button><button className="button" disabled={locked} onClick={() => { view.current++; setDraft(blank()); setAvailability(undefined); setConflict(false); setError('') }}>创建套餐</button></>} />
    <p>发布新修订不会改写已有订单与客户的冻结条款。历史修订仅供查看；调整当前 Offer 可用性不会修改价格与套餐条款。</p>
    {pending ? <section className="list-card" role="status"><h2>套餐操作等待确认</h2><p>{pending.operation} · 原操作 {pending.key}</p><button className="button" disabled={blocked || state.busy} onClick={() => void recover()}>使用原操作确认结果</button></section> : null}
    {error || state.error ? <p role="alert">{error || managementError(state.error)}</p> : null}
    {blocked ? <p role="alert">无法确认套餐管理权限，已隐藏数据。请刷新确认权限。</p> : null}
    {opening ? <p role="status">正在读取权威完整条款…</p> : null}
    <ReadList title="已发布套餐" rows={blocked || plans.isError ? [] : plans.data?.data.map(row => ({ ...row, id: row.plan_id })) ?? []} columns={planColumns}
      meta={plans.data?.meta} page={planPage} ready={!!plans.data && !blocked} loading={plans.isFetching} error={plans.isError ? plans.error : undefined} onRetry={() => void refresh()} onPage={setPlanPage} />
    <ReadList title="套餐 Offer" rows={blocked || offers.isError ? [] : offers.data?.data.map(row => ({ ...row, id: row.offer_id })) ?? []} columns={offerColumns}
      meta={offers.data?.meta} page={offerPage} ready={!!offers.data && !blocked} loading={offers.isFetching} error={offers.isError ? offers.error : undefined} onRetry={() => void refresh()} onPage={setOfferPage}>
      <form className="native-filters" onSubmit={event => { event.preventDefault(); if (filterDraft && (!/^[1-9][0-9]{0,18}$/.test(filterDraft) || BigInt(filterDraft) > 9223372036854775807n)) { setError('套餐 ID 格式无效。'); return }; setPlanFilter(filterDraft); setOfferPage(1); setError('') }}>
        <label>套餐 ID<input value={filterDraft} inputMode="numeric" pattern="[1-9][0-9]{0,18}" onChange={event => setFilterDraft(event.target.value)} /></label>
        <label>修订状态<select value={status} onChange={event => { setStatus(event.target.value as typeof status); setOfferPage(1) }}><option value="published">当前发布</option><option value="superseded">已被替代</option><option value="">全部修订</option></select></label><button className="button">筛选 Offer</button>
      </form>
    </ReadList>
    {selected && !blocked ? <section className="list-card" aria-label="套餐完整条款"><h2>完整条款与修订历史</h2>
      <ManagementFeedback error={detail.isError ? detail.error : undefined} loading={detail.isFetching} onRetry={() => void refresh()} notFoundMessage="套餐或修订不存在。" />
      {visible ? <><ReadFields fields={[["套餐 ID", visible.plan_id], ["查看修订", visible.revision.revision_id], ["当前修订", visible.current_revision_id], ["修订号", visible.revision.revision_no], ["条款哈希", visible.revision.content_hash], ["名称", visible.revision.terms.name], ["币种", visible.revision.terms.currency], ["流量", visible.revision.terms.quota_gib + ' GiB'], ["分组 ID", visible.revision.terms.group_id], ["速度限制", visible.revision.terms.speed_limit_mbps], ["设备限制", visible.revision.terms.device_limit], ["流量重置", resetNames[visible.revision.terms.reset_method] ?? String(visible.revision.terms.reset_method)]]} />
        <p style={{ whiteSpace: 'pre-wrap', overflowWrap: 'anywhere' }}>{visible.revision.terms.content ?? '未填写说明'}</p><p>标签：{visible.revision.terms.tags.join(' · ') || '无'}</p>
        <ul>{periods.filter(([key]) => visible.revision.terms.prices_minor[key] !== undefined).map(([key, label]) => <li key={key}>{label}：{visible.revision.terms.prices_minor[key]} 最小单位 {visible.revision.terms.currency}</li>)}</ul>
        <p>历史修订不会自动填入编辑表单。编辑时重新读取当前完整条款，并使用该修订作为发布前置条件。</p>
        <button className="button" disabled={locked} onClick={() => void openCurrent(visible.plan_id)}>基于当前完整条款编辑</button>
      </> : null}
      <ReadList title="套餐修订历史" rows={history.isError ? [] : history.data?.data.map(row => ({ ...row, id: row.revision_id })) ?? []} columns={historyColumns}
        meta={history.data?.meta} page={historyPage} ready={!!history.data && !blocked} loading={history.isFetching} error={history.isError ? history.error : undefined} onRetry={() => void refresh()} onPage={setHistoryPage} />
    </section> : null}
    {draft && !blocked ? <section className="list-card" aria-label="套餐发布编辑器"><h2>{draft.planId ? '发布套餐新修订' : '创建并发布套餐'}</h2>
      {draft.planId ? <p>套餐 #{draft.planId} · 基于当前修订 <strong>{draft.expectedRevision}</strong>。本表单不会随后台刷新自动替换。</p> : null}
      {conflict ? <p role="alert">套餐已变化。草稿仍保留，发布已停止；请重新读取当前条款并替换草稿后再确认。</p> : null}
      {draft.planId ? <button className="button" disabled={locked} onClick={() => void openCurrent(draft.planId!)}>重新读取当前条款并替换草稿</button> : null}
      <form className="native-content-form" aria-label="发布完整套餐条款" onSubmit={event => { event.preventDefault(); void publish() }}>
        <label htmlFor="catalog-name">套餐名称</label><input id="catalog-name" value={draft.name} maxLength={255} required disabled={locked} onChange={event => update('name', event.target.value)} />
        <label htmlFor="catalog-content">套餐说明</label><textarea id="catalog-content" rows={5} value={draft.content ?? ''} maxLength={8192} disabled={locked} onChange={event => update('content', event.target.value)} />
        <fieldset disabled={locked}><legend>标签（最多 40 项）</legend>{draft.tags.map((tag, index) => <div className="native-filters" key={index}><label>标签 {index + 1}<input value={tag} maxLength={80} onChange={event => update('tags', draft.tags.map((value, position) => position === index ? event.target.value : value))} /></label><button className="button" type="button" onClick={() => update('tags', draft.tags.filter((_, position) => position !== index))}>移除标签 {index + 1}</button></div>)}<button className="button" type="button" disabled={draft.tags.length >= 40} onClick={() => update('tags', [...draft.tags, ''])}>添加标签</button></fieldset>
        <label>流量（GiB）<input id="catalog-quota" value={draft.quota} inputMode="numeric" pattern="0|[1-9][0-9]*" required disabled={locked} onChange={event => update('quota', event.target.value)} /></label>
        <label>分组 ID（留空不指定）<input id="catalog-group" value={draft.group} inputMode="numeric" pattern="[1-9][0-9]{0,18}" disabled={locked} onChange={event => update('group', event.target.value)} /></label>
        <label>速度限制（Mbps，留空不限）<input id="catalog-speed" value={draft.speed} inputMode="numeric" pattern="0|[1-9][0-9]*" disabled={locked} onChange={event => update('speed', event.target.value)} /></label>
        <label>设备限制（留空不限）<input id="catalog-devices" value={draft.devices} inputMode="numeric" pattern="0|[1-9][0-9]*" disabled={locked} onChange={event => update('devices', event.target.value)} /></label>
        <label>流量重置方式<select id="catalog-reset" value={draft.reset} disabled={locked} onChange={event => update('reset', event.target.value)}>{resetNames.map((name, index) => <option value={String(index)} key={index}>{name}</option>)}</select></label>
        <label>发布币种<select id="catalog-currency" value={draft.currency} disabled={locked} onChange={event => update('currency', event.target.value)}>{!['CNY', 'USD'].includes(draft.currency) ? <option value={draft.currency}>原币种 {draft.currency}（须明确改选）</option> : null}<option value="CNY">CNY</option><option value="USD">USD</option></select></label>
        {!['CNY', 'USD'].includes(draft.currency) ? <p role="status">原修订使用 {draft.currency}。新发布仅支持 CNY/USD，必须明确选择币种并核对所有价格；系统不会自动换算。</p> : null}
        <fieldset disabled={locked}><legend>周期价格（最小货币单位整数）</legend><p>CNY/USD 均以分填写。留空表示本次不发布该周期，0 表示免费；数值按原字符串提交，不做浮点换算。</p>{periods.map(([key, label]) => <label key={key}>{label}<input id={'catalog-price-' + key} value={draft.prices[key]} inputMode="numeric" pattern="0|[1-9][0-9]{0,18}" maxLength={19} onChange={event => update('prices', { ...draft.prices, [key]: event.target.value })} /></label>)}</fieldset>
        <fieldset disabled={locked}><legend>本次发布的 Offer 名额</legend><p>名额属于当前 Offer 可用性，不是冻结套餐条款。请为新修订明确选择；不会从某个旧 Offer 推断共享名额。</p><label>名额设置<select id="catalog-capacity-choice" value={draft.capacityChoice} onChange={event => update('capacityChoice', event.target.value as Draft['capacityChoice'])}><option value="">请选择</option><option value="unlimited">不限名额</option><option value="limited">指定名额</option></select></label>{draft.capacityChoice === 'limited' ? <label>本次名额<input id="catalog-capacity" value={draft.capacity} inputMode="numeric" pattern="0|[1-9][0-9]*" required onChange={event => update('capacity', event.target.value)} /></label> : null}</fieldset>
        <button className="button" disabled={locked || conflict || !draft.capacityChoice || !['CNY', 'USD'].includes(draft.currency)}>发布完整条款</button><button className="button" type="button" disabled={state.busy} onClick={() => { view.current++; setDraft(undefined); setConflict(false); setOpening(false) }}>关闭编辑</button>
      </form>
    </section> : null}
    {availability && !blocked ? <section className="list-card" aria-label="Offer 可用性编辑器"><h2>调整 Offer #{availability.offer.offer_id} 可用性</h2><p>当前修订 #{availability.offer.revision_id} · 版本 {availability.offer.row_version}。价格与冻结条款不会改变。</p>
      {availability.conflict ? <p role="alert">可用性版本已变化。原设置保留，请重新读取后再次确认。</p> : null}
      <button className="button" disabled={locked} onClick={() => void reloadAvailability()}>重新读取 Offer 并替换设置</button>
      <form className="native-content-form" onSubmit={event => { event.preventDefault(); void saveAvailability() }}>
        {(['show', 'sell', 'renew'] as const).map((key, index) => <label key={key}><input type="checkbox" id={'catalog-availability-' + key} checked={availability[key]} disabled={locked} onChange={event => setAvailability(old => old && { ...old, [key]: event.target.checked })} />{['向用户显示', '允许新购', '允许续费'][index]}</label>)}
        <label>Offer 名额（留空不限）<input id="catalog-availability-capacity" value={availability.capacity} inputMode="numeric" pattern="0|[1-9][0-9]*" disabled={locked} onChange={event => setAvailability(old => old && { ...old, capacity: event.target.value })} /></label>
        <button className="button" disabled={locked || availability.conflict}>保存可用性</button><button className="button" type="button" disabled={state.busy} onClick={() => { view.current++; setAvailability(undefined); setOpening(false) }}>关闭可用性编辑</button>
      </form>
    </section> : null}
  </div>
}
