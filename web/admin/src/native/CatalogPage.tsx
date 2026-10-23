import { t, useLocale, getLocale, LocalizedError } from '../lib/i18n'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { useListPagination } from './useListPagination'
import './commerce-reference.css'
import { useEffect, useId, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CoreAdminApiError, coreOperations, type CoreInput, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { managementError, ManagementFeedback } from './Feedback'
import { ReadFields, ReadList } from './ReadList'
import { Modal } from '../components/ui/Modal'
import { PageHeader } from '../components/ui/PageHeader'
import { Popover } from '../components/ui/Popover'
import { Checkbox, Field, Input, Select } from '../components/ui/Field'
import type { Column } from '../components/ui/DataTable'
import { commerceMoney } from './commerce-format'
import { MarkdownEditor } from '../components/ui/MarkdownEditor'
import { TagInput } from '../components/ui/TagInput'
import { GroupSelector, GroupCreationRecovery } from './GroupSelector'
import { monthlyPriceDraft } from './catalogPrices'
import { requestConfirm } from '../components/ui/ConfirmDialog'
import { useAdminConfirmation } from './useAdminConfirmation'

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
const statusName = (status: string) => status === 'published' ? t("当前发布") : status === 'superseded' ? t("已被新修订替代") : status
const periodName = (period: string) => t(periods.find(([key]) => key === period)?.[1] ?? period)
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
  if (!/^(0|[1-9][0-9]*)$/.test(value)) throw new LocalizedError('{label}须为非负整数', { label })
  const result = Number(value)
  if (!Number.isSafeInteger(result) || result > maximum) throw new LocalizedError('{label}超出允许范围', { label })
  return result
}
function nullableInteger(value: string, label: string): number | null { return value === '' ? null : integer(value, label, 4294967295) }
function publication(draft: Draft): Publication {
  if (!draft.name.trim()) throw new LocalizedError("请填写套餐名称")
  if (!['CNY', 'USD'].includes(draft.currency)) throw new LocalizedError("原币种不支持新发布，请明确选择 CNY 或 USD")
  if (!draft.capacityChoice) throw new LocalizedError("请明确选择本次发布的商品名额")
  const prices: Partial<Record<Period, string>> = {}
  for (const [period] of periods) {
    const price = draft.prices[period]
    if (price === '') continue
    if (!/^(0|[1-9][0-9]{0,18})$/.test(price) || BigInt(price) > 9223372036854775807n) throw new LocalizedError('{period}价格须为有效的最小货币单位整数', { period: periods.find(([key]) => key === period)?.[1] ?? period })
    prices[period] = price
  }
  if (!Object.keys(prices).length) throw new LocalizedError("至少填写一个周期价格")
  const input = { ...(draft.planId ? { plan_id: draft.planId, expected_revision_id: draft.expectedRevision } : {}),
    name: draft.name, content: draft.content, tags: draft.tags, quota_gib: integer(draft.quota, "流量", 8589934591),
    group_id: draft.group || null, speed_limit_mbps: nullableInteger(draft.speed, "速度限制"), device_limit: nullableInteger(draft.devices, "设备限制"),
    capacity_limit: draft.capacityChoice === 'unlimited' ? null : integer(draft.capacity, "商品名额", 4294967295),
    reset_method: integer(draft.reset, "重置方式", 4), currency: draft.currency, prices_minor: prices }
  const parsed = coreOperations['catalog.plans.publish'].input.safeParse(input)
  if (!parsed.success) throw new LocalizedError("套餐条款未通过校验，请检查名称、标签、分组 ID 和数值范围")
  return parsed.data
}

export function CatalogPage() {
  const confirm = useAdminConfirmation(() => !blocked)
  useLocale()
  const formId = useId(), availabilityFormId = useId()
  const client = useQueryClient(), state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const { page: planPage, setPage: setPlanPage, perPage: planSize, setPerPage: setPlanSize } = useListPagination()
  const { page: offerPage, setPage: setOfferPage, perPage: offerSize, setPerPage: setOfferSize } = useListPagination()
  const { page: historyPage, setPage: setHistoryPage, perPage: historySize, setPerPage: setHistorySize } = useListPagination()
  const [filterDraft, setFilterDraft] = useState(''), [planFilter, setPlanFilter] = useState('')
  const [status, setStatus] = useState<'published' | 'superseded' | ''>('published')
  const [selected, setSelected] = useState(''), [revision, setRevision] = useState('')
  const [draft, setDraft] = useState<Draft>(), [availability, setAvailability] = useState<Availability>()
  const [error, setError] = useState<string | LocalizedError>(''), [conflict, setConflict] = useState(false), [opening, setOpening] = useState(false)
  const [failedRead, setFailedRead] = useState<CoreInput<'catalog.admin-plans.get'>>()
  const view = useRef(0), draftBaseline = useRef('')
  const dirty = !!draft && JSON.stringify(draft) !== draftBaseline.current
  useEffect(() => {
    if (!dirty) return
    const warn = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', warn); return () => window.removeEventListener('beforeunload', warn)
  }, [dirty])
  function acceptDraft(next: Draft) { draftBaseline.current = JSON.stringify(next); setDraft(next) }
  async function discardDraft() { return !dirty || (await confirm(t("将丢弃未发布的套餐草稿，是否继续？"))) }
  const generation = workflowActions.generation(), observedGeneration = useRef(generation)
  useEffect(() => () => { view.current++ }, [])
  useEffect(() => {
    if (observedGeneration.current === generation) return
    observedGeneration.current = generation; view.current++
    setDraft(undefined); setAvailability(undefined); setOpening(false); setFailedRead(undefined); setConflict(false); setError('')
  }, [generation])
  const plans = useQuery({ queryKey: ['native-admin', 'catalog', 'plans', planPage, planSize],
    queryFn: ({ signal }) => admin.read('catalog.admin-plans.list', { page: planPage, per_page: planSize }, signal) })
  const offerInput: CoreInput<'catalog.admin-offers.list'> = { page: offerPage, per_page: offerSize,
    ...(planFilter ? { plan_id: planFilter } : {}), ...(status ? { status } : {}) }
  const offers = useQuery({ queryKey: ['native-admin', 'catalog', 'offers', offerInput],
    queryFn: ({ signal }) => admin.read('catalog.admin-offers.list', offerInput, signal) })
  const detail = useQuery({ queryKey: ['native-admin', 'catalog', 'detail', selected, revision], enabled: !!selected,
    queryFn: ({ signal }) => admin.read('catalog.admin-plans.get', { plan_id: selected, ...(revision ? { revision_id: revision } : {}) }, signal) })
  const history = useQuery({ queryKey: ['native-admin', 'catalog', 'history', selected, historyPage, historySize], enabled: !!selected,
    queryFn: ({ signal }) => admin.read('catalog.admin-plans.history.list', { plan_id: selected, page: historyPage, per_page: historySize }, signal) })
  const pending = state.pending?.operation.startsWith('catalog.') ? state.pending : undefined
  const blocked = !!failedRead || [plans.error, offers.error, selected && detail.error, selected && history.error, !state.errorOperation || state.errorOperation.startsWith('catalog.') ? state.error : undefined].some(denied)
  const locked = blocked || state.busy || !!state.pending || opening || plans.isError || offers.isError
  const availabilityDirty = !!availability && (availability.show !== availability.offer.show || availability.sell !== availability.offer.sell
    || availability.renew !== availability.offer.renew || availability.capacity !== (availability.offer.capacity_limit ?? ''))
  useDirtyDraft((dirty || availabilityDirty) && !blocked && observedGeneration.current === generation && !state.pending)
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
    if (locked || !(await discardDraft())) return
    const generation = workflowActions.generation(), current = ++view.current
    setOpening(true); setError('')
    try {
      const result = await admin.read('catalog.admin-plans.get', { plan_id: id })
      if (generation !== workflowActions.generation() || current !== view.current) return
      if (result.data.revision.revision_id !== result.data.current_revision_id) throw new LocalizedError("当前套餐修订不一致，请重新读取")
      selectPlan(id); acceptDraft(fromCurrent(result.data)); setConflict(false); setAvailability(undefined)
    } catch (failure) {
      if (generation !== workflowActions.generation() || current !== view.current) return
      setError(failure instanceof CoreAdminApiError && failure.status === 404 ? '套餐或修订不存在，请刷新列表' : managementError(failure, false))
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
      setError(managementError(failure, false))
      if (failure instanceof CoreAdminApiError && failure.status === 409 && !failure.needsStateConfirmation) {
        if (operation === 'catalog.plans.publish') setConflict(true)
        else setAvailability(old => old && { ...old, conflict: true })
        await refresh()
      }
    }
  }
  async function publish() {
    if (locked || !draft || conflict) return
    try { await perform('catalog.plans.publish', publication(draft)) } catch (failure) { setError(failure instanceof LocalizedError ? failure : failure instanceof Error ? failure.message : '请检查套餐条款') }
  }
  async function saveAvailability() {
    if (locked || !availability || availability.conflict || availability.offer.status !== 'published') return
    try {
      await perform('catalog.offers.availability.update', { offer_id: availability.offer.offer_id, expected_version: availability.offer.row_version,
        show: availability.show, sell: availability.sell, renew: availability.renew, capacity_limit: nullableInteger(availability.capacity, t("商品名额")) })
    } catch (failure) { setError(failure instanceof LocalizedError ? failure : failure instanceof Error ? failure.message : '请检查可用性设置') }
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
      if (!row || row.status !== 'published') { setAvailability(undefined); await refresh(); if (generation === workflowActions.generation() && current === view.current) setError('该商品已不在当前发布中，请查看最新套餐'); return }
      setAvailability({ offer: row, show: row.show, sell: row.sell, renew: row.renew, capacity: row.capacity_limit ?? '', conflict: false })
    } catch (failure) {
      if (generation === workflowActions.generation() && current === view.current) {
        setError(managementError(failure, false))
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
    } catch (failure) { if (generation === workflowActions.generation() && current === view.current) setError(managementError(failure, false)) }
  }
  function update<K extends keyof Draft>(key: K, value: Draft[K]) { setDraft(old => old && { ...old, [key]: value }) }
  async function clearPrices() {
    if (!draft || locked) return
    if (Object.values(draft.prices).some(value => value !== '') && !(await confirm(t('确认清空全部周期价格草稿？保存发布前仍可重新填写。')))) return
    update('prices', Object.fromEntries(periods.map(([key]) => [key, ''])) as Draft['prices'])
  }
  function useContentTemplate() {
    if (!draft || locked) return
    const current = view.current, generation = workflowActions.generation()
    const template = '## ' + (draft.name.trim() || t("套餐说明")) + '\n\n' +
      t("- 包含流量：") + draft.quota + ' GiB\n' +
      t("- 速度限制：") + (draft.speed === '' ? t("不限") : draft.speed + ' Mbps') + '\n' +
      t("- 设备数量：") + (draft.devices === '' ? t("不限") : draft.devices + t(" 台")) + '\n\n' +
      t("### 使用说明\n\n请在此补充适用场景、使用方式和注意事项。")
    const replace = () => {
      if (view.current !== current || workflowActions.generation() !== generation) return
      setDraft(old => old === draft ? { ...old, content: template } : old)
    }
    if (!draft.content?.trim()) replace()
    else requestConfirm({ title: t("替换套餐说明？"), message: t("将使用当前流量与限制生成内容模板，现有正文会被替换。其他套餐条款保持原值。"), confirmLabel: t("替换正文"), cancelLabel: t("保留原文"), action: replace })
  }
  function derivePrices(replace: boolean) {
    if (!draft || locked) return
    const observed = draft, generation = workflowActions.generation()
    let next: Draft['prices']
    try { next = monthlyPriceDraft(draft.prices, replace) } catch (failure) { setError(failure instanceof LocalizedError ? failure : failure instanceof Error ? failure.message : '请检查月付价格'); return }
    const apply = () => { if (generation === workflowActions.generation()) setDraft(current => current === observed ? { ...current, prices: next } : current) }
    if (replace) requestConfirm({ title: t("按月价重算周期价格？"), message: t("季付、半年付及年付系列将按月份乘月价；免费周期、一次性和流量重置价格保留。请发布前核对。"), confirmLabel: t("重算周期价格"), action: apply })
    else apply()
  }
  const planColumns: Column<Plan & { id: string }>[] = [
    { key: 'name', header: t("套餐"), render: row => <div className="native-row-identity"><strong>{row.name}</strong><small>#{row.plan_id}</small></div> },
    { key: 'revision', header: t("当前修订"), render: row => <div className="commerce-cell-stack"><strong>{t("第")}{row.revision_no}{t("版")}</strong><small>#{row.current_revision_id}</small></div> },
    { key: 'currency', header: t("币种"), render: row => row.currency },
    { key: 'published', header: t("发布时间"), render: row => new Date(row.published_at * 1000).toLocaleString(getLocale()) },
    { key: 'actions', header: t("操作"), pin: 'right', hideable: false, render: row => <div className="commerce-row-actions"><button className="button" disabled={locked} onClick={() => selectPlan(row.plan_id)}>{t("查看完整条款")}</button><button className="button" disabled={locked} onClick={() => void openCurrent(row.plan_id)}>{t("编辑当前套餐")}</button></div> },
  ]
  const offerColumns: Column<Offer & { id: string }>[] = [
    { key: 'name', header: t("商品 / 套餐"), render: row => <div className="native-row-identity"><strong>{row.name}</strong><small>{t("商品 #")}{row.offer_id}{t("· 套餐 #")}{row.plan_id}</small></div> },
    { key: 'period', header: t("周期 / 发布价格"), render: row => <div className="commerce-cell-stack"><strong>{periodName(row.offer_code)}</strong><span className="commerce-amount">{commerceMoney(row.price_minor, row.currency)}</span></div> },
    { key: 'status', header: t("修订状态"), render: row => <div className="commerce-cell-stack"><span className={row.status === 'published' ? 'commerce-badge success' : 'commerce-badge'}>{statusName(row.status)}</span><small>{t("修订 #")}{row.revision_id}</small></div> },
    { key: 'access', header: t("当前可用性"), render: row => <div className="commerce-cell-stack"><span>{row.show ? t("显示") : t("隐藏")} · {row.sell ? t("可新购") : t("不新购")} · {row.renew ? t("可续费") : t("不续费")}</span><small>{t("名额")}{row.capacity_limit ?? t("不限")}{t("· 版本")}{row.row_version}</small></div> },
    { key: 'actions', header: t("操作"), pin: 'right', hideable: false, render: row => <button className="button" disabled={locked || offers.isFetching || row.status !== 'published'} onClick={async () => { if (!(await discardDraft())) return; view.current++; setAvailability({ offer: row, show: row.show, sell: row.sell, renew: row.renew, capacity: row.capacity_limit ?? '', conflict: false }); setDraft(undefined); setError('') }}>{t("调整可用性")}</button> },
  ]
  const historyColumns: Column<Revision & { id: string }>[] = [
    { key: 'revision', header: t("修订"), render: row => t("第 ") + row.revision_no + t(" 版 · #") + row.revision_id },
    { key: 'name', header: t("名称 / 币种"), render: row => row.name + ' · ' + row.currency },
    { key: 'time', header: t("发布时间"), render: row => new Date(row.published_at * 1000).toLocaleString(getLocale()) },
    { key: 'open', header: t("操作"), render: row => <button className="button" disabled={locked} onClick={() => setRevision(row.revision_id)}>{t("查看此修订")}</button> },
  ]
  const visible = !blocked && !detail.isError ? detail.data?.data : undefined
  async function closeEditor() {
    if (state.busy || !(await discardDraft())) return
    view.current++; setDraft(undefined); setConflict(false); setOpening(false)
  }
  async function closeAvailability() {
    if (state.busy || availabilityDirty && !(await confirm(t("商品销售设置尚未保存，确定放弃修改？")))) return
    view.current++; setAvailability(undefined); setOpening(false)
  }
  return <div className="native-management-page commerce-reference catalog-page">
    <PageHeader title={t("套餐与发布")} description={t("管理套餐版本、周期价格和商品销售状态")} action={<>
      <Popover label={t("套餐版本与销售规则")}><p>{t("发布新版本仅影响后续购买，已有订单保留原套餐内容")}</p><p>{t("销售状态可以单独调整，历史版本仍可查看")}</p></Popover>
      <button className="button" disabled={plans.isFetching || offers.isFetching || opening} onClick={() => void refresh()}>{t("刷新")}</button><button className="button primary" disabled={locked} onClick={async () => { if (!(await discardDraft())) return; view.current++; acceptDraft(blank()); setAvailability(undefined); setConflict(false); setError('') }}>{t("创建套餐")}</button>
    </>} />
    <GroupCreationRecovery onCreated={id => { if (draft) update('group', id) }} />
    {pending ? <section className="list-card commerce-pending" role="status"><h2>{t("套餐操作等待确认")}</h2><p>{t("请先确认上次发布或销售设置的结果。")}</p><details className="commerce-help"><summary>{t("查看操作标识")}</summary><code>{pending.operation}{t("· 原操作")}{pending.key}</code></details><button className="button" disabled={blocked || state.busy} onClick={() => void recover()}>{t("使用原操作确认结果")}</button></section> : null}
    {!draft && !availability && (error || state.error) ? <p role="alert">{error instanceof LocalizedError ? error.localizedMessage() : error ? t(error) : managementError(state.error)}</p> : null}
    {blocked ? <p role="alert">{t("无法确认套餐管理权限，已隐藏数据请刷新确认权限")}</p> : null}
    {opening ? <p role="status">{t("正在读取套餐内容…")}</p> : null}
    <ReadList mobilePrimaryKey="name" title={"已发布套餐"} rows={blocked || plans.isError ? [] : plans.data?.data.map(row => ({ ...row, id: row.plan_id })) ?? []} columns={planColumns}
      meta={plans.data?.meta} perPage={planSize} onPageSize={setPlanSize} page={planPage} ready={!!plans.data && !blocked} loading={plans.isFetching} error={plans.isError ? plans.error : undefined} onRetry={() => void refresh()} onPage={setPlanPage}><header className="commerce-card-heading"><div><h2>{t("已发布套餐")}</h2><p>{t("套餐内容与当前发布版本")}</p></div></header></ReadList>
    <ReadList mobilePrimaryKey="name" title={"周期商品"} rows={blocked || offers.isError ? [] : offers.data?.data.map(row => ({ ...row, id: row.offer_id })) ?? []} columns={offerColumns}
      meta={offers.data?.meta} perPage={offerSize} onPageSize={setOfferSize} page={offerPage} ready={!!offers.data && !blocked} loading={offers.isFetching} error={offers.isError ? offers.error : undefined} onRetry={() => void refresh()} onPage={setOfferPage}>
      <header className="commerce-card-heading"><div><h2>{t("周期商品")}</h2><p>{t("按套餐与修订状态查找已发布的周期商品")}</p></div></header><form className="native-filters" onSubmit={event => { event.preventDefault(); if (filterDraft && (!/^[1-9][0-9]{0,18}$/.test(filterDraft) || BigInt(filterDraft) > 9223372036854775807n)) { setError('套餐 ID 格式无效'); return }; setPlanFilter(filterDraft); setOfferPage(1); setError('') }}>
        <label>{t("套餐 ID")}<input placeholder={t("输入套餐 ID")} value={filterDraft} inputMode="numeric" pattern="[1-9][0-9]{0,18}" onChange={event => setFilterDraft(event.target.value)} /></label>
        <label>{t("修订状态")}<select value={status} onChange={event => { setStatus(event.target.value as typeof status); setOfferPage(1) }}><option value="published">{t("当前发布")}</option><option value="superseded">{t("已被替代")}</option><option value="">{t("全部修订")}</option></select></label><button className="button">{t("筛选商品")}</button>
      </form>
    </ReadList>
    {selected && !blocked ? <section className="list-card commerce-editor" aria-label={t("套餐完整条款")}><header className="commerce-card-heading"><div><h2>{t("套餐详情与版本历史")}</h2><p>{t("查看每次发布时的完整套餐内容")}</p></div><button className="button" disabled={state.busy} onClick={() => { view.current++; setOpening(false); setSelected(''); setRevision('') }}>{t("关闭详情")}</button></header>
      <ManagementFeedback error={detail.isError ? detail.error : undefined} loading={detail.isFetching} onRetry={() => void refresh()} notFoundMessage={t("套餐或修订不存在")} />
      {visible ? <><div className="commerce-metrics"><div><small>{t("套餐名称")}</small><strong className="commerce-metric-name">{visible.revision.terms.name}</strong></div><div><small>{t("包含流量")}</small><strong>{visible.revision.terms.quota_gib}<span>GiB</span></strong></div><div><small>{t("查看版本")}</small><strong>{visible.revision.revision_no}</strong></div></div><details className="commerce-help"><summary>{t("查看完整条款与版本标识")}</summary><ReadFields fields={[[t("套餐 ID"), visible.plan_id], [t("查看修订"), visible.revision.revision_id], [t("当前修订"), visible.current_revision_id], [t("修订号"), visible.revision.revision_no], [t("条款哈希"), visible.revision.content_hash], [t("名称"), visible.revision.terms.name], [t("币种"), visible.revision.terms.currency], [t("流量"), visible.revision.terms.quota_gib + ' GiB'], [t("分组 ID"), visible.revision.terms.group_id], [t("速度限制"), visible.revision.terms.speed_limit_mbps === null ? t("不限") : visible.revision.terms.speed_limit_mbps + " Mbps"], [t("设备限制"), visible.revision.terms.device_limit === null ? t("不限") : visible.revision.terms.device_limit + t(" 台")], [t("流量重置"), t(resetNames[visible.revision.terms.reset_method] ?? String(visible.revision.terms.reset_method))]]} /></details>
        <p className="commerce-description">{visible.revision.terms.content ?? t("未填写说明")}</p><p>{t("标签：")}{visible.revision.terms.tags.join(' · ') || t("无")}</p>
        <ul className="commerce-price-list">{periods.filter(([key]) => visible.revision.terms.prices_minor[key] !== undefined).map(([key, label]) => <li key={key}>{t(label)}: {commerceMoney(visible.revision.terms.prices_minor[key]!, visible.revision.terms.currency)}</li>)}</ul>
        <p className="commerce-table-description">{t("编辑将基于最新版本，历史内容仅供查阅")}</p>
        <button className="button" disabled={locked} onClick={() => void openCurrent(visible.plan_id)}>{t("基于当前完整条款编辑")}</button>
      </> : null}
      <ReadList title={"套餐修订历史"} rows={history.isError ? [] : history.data?.data.map(row => ({ ...row, id: row.revision_id })) ?? []} columns={historyColumns}
        meta={history.data?.meta} perPage={historySize} onPageSize={setHistorySize} page={historyPage} ready={!!history.data && !blocked} loading={history.isFetching} error={history.isError ? history.error : undefined} onRetry={() => void refresh()} onPage={setHistoryPage}><header className="commerce-card-heading"><div><h3>{t("套餐修订历史")}</h3><p>{t("选择修订查看发布时的完整条款")}</p></div></header></ReadList>
    </section> : null}
    {draft && !blocked ? <Modal open className="commerce-reference catalog-page" size="wide" title={draft.planId ? t("发布套餐新修订") : t("创建并发布套餐")} subtitle={t("完善基本信息、流量额度与周期价格")} onClose={() => void closeEditor()} footer={<div className="commerce-form-actions"><span>{t("发布后，后续购买将使用新版本")}</span><button className="button" type="button" disabled={state.busy} onClick={() => void closeEditor()}>{t("关闭编辑")}</button><button type="submit" form={formId} className="button primary" disabled={locked || conflict || !draft.capacityChoice || !['CNY', 'USD'].includes(draft.currency)}>{t("发布完整条款")}</button></div>}><section className="commerce-editor refined-catalog-editor" aria-label={t("套餐发布编辑器")}>{error || state.error ? <p role="alert">{error instanceof LocalizedError ? error.localizedMessage() : error ? t(error) : managementError(state.error)}</p> : null}
      {draft.planId ? <p>{t("套餐 #")}{draft.planId}{t("· 基于当前修订")}<strong>{draft.expectedRevision}</strong>{t("，草稿不会随刷新自动替换")}</p> : null}
      {conflict ? <p role="alert">{t("套餐已变化，草稿仍保留，发布已停止；请重新读取当前条款并替换草稿后再确认")}</p> : null}
      {draft.planId ? <button className="button" disabled={locked} onClick={() => void openCurrent(draft.planId!)}>{t("重新读取当前条款并替换草稿")}</button> : null}
      <form id={formId} key={view.current} className="native-content-form commerce-catalog-form" aria-label={t("发布完整套餐条款")} onSubmit={event => { event.preventDefault(); void publish() }}>
        <fieldset disabled={locked} className="commerce-form-grid"><legend>{t("套餐内容")}</legend>
          <Field label={t("套餐名称")}><Input id="catalog-name" value={draft.name} maxLength={255} required disabled={locked} onChange={event => update('name', event.target.value)} /></Field>
          <div className="commerce-wide-field"><div className="commerce-card-heading"><label htmlFor="catalog-content">{t("套餐说明")}</label><button type="button" className="button" disabled={locked} onClick={useContentTemplate}>{t("使用内容模板")}</button></div><MarkdownEditor id="catalog-content" required={false} value={draft.content ?? ''} maxLength={8192} disabled={locked} onChange={value => update('content', value)} /></div>
        </fieldset>
        <fieldset disabled={locked}><legend>{t("标签（最多 40 项）")}</legend><label htmlFor="catalog-tags">{t("套餐标签")}</label><TagInput id="catalog-tags" value={draft.tags} disabled={locked} maxItems={40} maxLength={80} onChange={value => update('tags', value)} /></fieldset>
        <fieldset disabled={locked} className="commerce-form-grid"><legend>{t("额度与访问限制")}</legend>
          <Field label={t("流量（GiB）")}><Input id="catalog-quota" value={draft.quota} inputMode="numeric" pattern="0|[1-9][0-9]*" required disabled={locked} onChange={event => update('quota', event.target.value)} /></Field>
          <div className="commerce-wide-field"><GroupSelector value={draft.group ? [draft.group] : []} multiple={false} label={t("套餐权限组")} disabled={locked} onChange={ids => update('group', ids[0] ?? '')} /></div>
          <Field label={t("速度限制（Mbps，留空不限）")}><Input id="catalog-speed" value={draft.speed} inputMode="numeric" pattern="0|[1-9][0-9]*" disabled={locked} onChange={event => update('speed', event.target.value)} /></Field>
          <Field label={t("设备限制（留空不限）")}><Input id="catalog-devices" value={draft.devices} inputMode="numeric" pattern="0|[1-9][0-9]*" disabled={locked} onChange={event => update('devices', event.target.value)} /></Field>
          <Field label={t("流量重置方式")}><Select id="catalog-reset" value={draft.reset} disabled={locked} onChange={event => update('reset', event.target.value)}>{resetNames.map((name, index) => <option value={String(index)} key={index}>{t(name)}</option>)}</Select></Field>
        </fieldset><fieldset disabled={locked}><legend>{t("发布价格")}</legend><Field label={t("发布币种")}><Select id="catalog-currency" value={draft.currency} disabled={locked} onChange={event => update('currency', event.target.value)}>{!['CNY', 'USD'].includes(draft.currency) ? <option value={draft.currency}>{t("原币种")}{draft.currency}{t("（须明确改选）")}</option> : null}<option value="CNY">CNY</option><option value="USD">USD</option></Select></Field>
        {!['CNY', 'USD'].includes(draft.currency) ? <p role="status">{t("原修订使用")}{draft.currency}{t("，新发布仅支持 CNY/USD，必须明确选择币种并核对所有价格；系统不会自动换算")}</p> : null}
        </fieldset><fieldset disabled={locked} className="commerce-form-grid"><legend>{t("周期价格（分）")}</legend><div className="commerce-wide-field commerce-date-presets"><button type="button" className="button" disabled={locked} onClick={clearPrices}>{t("清空全部周期价格")}</button><button type="button" className="button" disabled={locked} onClick={() => derivePrices(false)}>{t("按月价填写空白周期")}</button><button type="button" className="button" disabled={locked} onClick={() => derivePrices(true)}>{t("按月价重算周期价格")}</button></div><p className="commerce-wide-field">{t("CNY / USD 均按百分之一货币单位填写，留空不发布该周期，填写 0 表示免费")}</p>{periods.map(([key, label]) => <Field key={key} label={t(label)} hint={draft.prices[key] !== '' && /^\d+$/.test(draft.prices[key]) ? commerceMoney(draft.prices[key], draft.currency) : t("留空不发布该周期")}><Input id={'catalog-price-' + key} value={draft.prices[key]} inputMode="numeric" pattern="0|[1-9][0-9]{0,18}" maxLength={19} onChange={event => update('prices', { ...draft.prices, [key]: event.target.value })} /></Field>)}</fieldset>
        <fieldset disabled={locked}><legend>{t("本次发布的商品名额")}</legend><p>{t("为本次发布的各周期商品设置销售名额，旧商品名额不会自动沿用")}</p><Field label={t("名额设置")}><Select id="catalog-capacity-choice" value={draft.capacityChoice} onChange={event => update('capacityChoice', event.target.value as Draft['capacityChoice'])}><option value="">{t("请选择")}</option><option value="unlimited">{t("不限名额")}</option><option value="limited">{t("指定名额")}</option></Select></Field>{draft.capacityChoice === 'limited' ? <Field label={t("本次名额")}><Input id="catalog-capacity" value={draft.capacity} inputMode="numeric" pattern="0|[1-9][0-9]*" required onChange={event => update('capacity', event.target.value)} /></Field> : null}</fieldset>

      </form>
    </section></Modal> : null}
    {availability && !blocked ? <Modal open className="commerce-reference catalog-page" size="compact" title={t("调整商品销售状态")} subtitle={t("分别控制展示、新购与续费")} onClose={() => void closeAvailability()} footer={<div className="commerce-form-actions"><span>{t("套餐价格与内容保持原值")}</span><button className="button" type="button" disabled={state.busy} onClick={() => void closeAvailability()}>{t("关闭可用性编辑")}</button><button type="submit" form={availabilityFormId} className="button primary" disabled={locked || availability.conflict}>{t("保存可用性")}</button></div>}><section className="commerce-editor" aria-label={t("Offer 可用性编辑器")}><p>{t("当前修订 #")}{availability.offer.revision_id}{t("· 版本")}{availability.offer.row_version}{t("· 价格与冻结条款不会改变")}</p>
      {availability.conflict ? <p role="alert">{t("可用性版本已变化，原设置保留，请重新读取后再次确认")}</p> : null}
      <button className="button" disabled={locked} onClick={() => void reloadAvailability()}>{t("重新读取商品并替换设置")}</button>
      <form id={availabilityFormId} className="native-content-form commerce-availability-form" onSubmit={event => { event.preventDefault(); void saveAvailability() }}>
        {(['show', 'sell', 'renew'] as const).map((key, index) => <Checkbox className="commerce-toggle" key={key} id={'catalog-availability-' + key} label={[t("向用户显示"), t("允许新购"), t("允许续费")][index]} checked={availability[key]} disabled={locked} onChange={event => setAvailability(old => old && { ...old, [key]: event.target.checked })} />)}
        <Field label={t("商品名额（留空不限）")} className="commerce-wide-field"><Input id="catalog-availability-capacity" value={availability.capacity} inputMode="numeric" pattern="0|[1-9][0-9]*" disabled={locked} onChange={event => setAvailability(old => old && { ...old, capacity: event.target.value })} /></Field>

      </form>
    </section></Modal> : null}
  </div>
}
