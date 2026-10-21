import { t, useLocale, formatNumber } from '../../lib/i18n'
import { readPageSize } from '../../native/useListPagination'
import '../../native/commerce-reference.css'
import './orders-reference.css'
import { useQuery } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { RefreshCw, Search, ArrowUpRight } from 'lucide-react'
import type { CoreInput, CoreOutput } from '@txboard/core-sdk'
import { ListRuleFilter, orderRules, parseListRules } from '../../native/ListRuleFilter'
import { admin } from '../../native/session'
import { NetworkRecovery, useNetworkManagement } from '../../native/NetworkManagement'
import { ReadList } from '../../native/ReadList'
import { OrderReadDialog } from '../../native/OrderReadDialog'
import { OrderCommandRecovery } from '../../native/OrderAdministration'
import { isoDate, minorAmount, orderStatus, readPage, validId, validTradeNo } from '../../native/readPresentation'
import { PageHeader } from '../../components/ui/PageHeader'
import { FilterBar } from '../../components/ui/FilterBar'
import { FacetedFilter } from '../../components/ui/FacetedFilter'
import { Tag } from '../../components/ui/Tag'
import { Input } from '../../components/ui/Field'
import { StatusBadge } from '../../components/ui/StatusBadge'
import { CopyButton } from '../../components/ui/CopyButton'
import { orderPeriod, orderType } from '../../native/orderPresentation'
import type { Column } from '../../components/ui/DataTable'

type Order = CoreOutput<'commerce.admin-orders.list'>[number]
export function OrdersPage() {
  useLocale()
  const [params, setParams] = useSearchParams()
  const page = readPage(params.get('page'))
  const perPage = readPageSize(params.get('per_page'))
  function resize(size: number) {
    setParams(current => { const next = new URLSearchParams(current); next.delete('page'); next.set('per_page', String(size)); return next })
  }
  const appliedUser = params.get('user_id') ?? ''
  const appliedTrade = params.get('trade_no') ?? ''
  const appliedStatus = params.get('status') ?? ''
  const [user, setUser] = useState(appliedUser), [trade, setTrade] = useState(appliedTrade)
  const [status, setStatus] = useState(appliedStatus), [detail, setDetail] = useState<string | null>(null)
  const advanced = parseListRules(params, orderRules)
  const valid = advanced.valid && (!appliedUser || validId(appliedUser)) && (!appliedTrade || validTradeNo(appliedTrade))
    && ['', '0', '1', '2', '3', '4'].includes(appliedStatus)
  const input = { page, per_page: perPage, ...(appliedUser ? { user_id: appliedUser } : {}),
    ...(appliedTrade ? { trade_no: appliedTrade } : {}), ...(appliedStatus !== '' ? { status: Number(appliedStatus) } : {}),
    ...(params.has('filters') ? { filters: advanced.filters } : {}), ...(params.has('sort_by') ? { sort_by: advanced.sort_by } : {}),
    ...(params.has('sort_direction') ? { sort_direction: advanced.sort_direction } : {}) } as CoreInput<'commerce.admin-orders.list'>
  const query = useQuery({ queryKey: ['native-admin', 'admin-orders', input], enabled: valid,
    queryFn: ({ signal }) => admin.read('commerce.admin-orders.list', input, signal) })
  const recovery = useNetworkManagement('commerce.admin-orders.', [query.error], () => query.refetch({ throwOnError: true }))
  useEffect(() => {
    setUser(appliedUser); setTrade(appliedTrade); setStatus(appliedStatus); setDetail(null)
  }, [appliedUser, appliedTrade, appliedStatus, page, perPage, recovery.denied])
  const data = !valid || recovery.denied || query.isError ? undefined : query.data
  function apply(event: FormEvent) {
    event.preventDefault()
    setParams(current => { const next = new URLSearchParams(current); next.delete('page'); for (const [key, value] of [['user_id', user.trim()], ['trade_no', trade.trim()], ['status', status]]) { if (value) next.set(key, value); else next.delete(key) }; return next })
  }
  function paginate(nextPage: number) {
    setParams(current => { const next = new URLSearchParams(current); next.set('page', String(nextPage)); return next })
  }
  function removeFilter(key: string) {
    const parameter = key === 'user' ? 'user_id' : key === 'trade' ? 'trade_no' : key
    setParams(current => { const next = new URLSearchParams(current); next.delete(parameter); next.delete('page'); return next })
  }
  const columns: Column<Order>[] = [
    { key: 'trade', header: t("订单号"), hideable: false, pin: 'left', render: row => <div className="native-row-identity"><div className="order-copy-line"><button className="native-read-link commerce-order-link" onClick={() => setDetail(row.trade_no)}>{row.trade_no}</button><CopyButton value={row.trade_no} label={t("复制订单号")} /></div><small>{t('订单 #{id}', { id: row.id })}</small></div> },
    { key: 'type', header: t("类型"), render: row => <Tag tone={row.type === 2 ? 'success' : row.type === 3 ? 'info' : 'neutral'}>{orderType(row.type)}</Tag> },
    { key: 'user', header: t("用户 ID"), render: row => <Link className="table-link" to={'/user/' + row.user_id}>#{row.user_id}</Link> },
    { key: 'plan', header: t("套餐 / 周期"), render: row => <div className="order-plan-cell"><strong>{row.plan?.name ?? t("未记录")}</strong><small>{orderPeriod(row.period)}</small></div> },
    { key: 'amount', header: t("订单金额"), render: row => <strong className="reference-number">{minorAmount(row.amount_minor, row.currency)}</strong> },
    { key: 'status', header: t("状态"), description: t("待支付：等待付款；开通中：支付已确认，正在履约；已完成：履约完成；已取消与已折抵为订单终态。"), render: row => <StatusBadge tone={row.status === 3 ? 'success' : row.status === 0 || row.status === 1 ? 'warning' : 'neutral'}>{orderStatus(row.status)}</StatusBadge> },
    { key: 'created', header: t("创建 / 支付时间"), render: row => <div className="order-date-cell"><span>{isoDate(row.created_at)}</span><small>{row.paid_at ? t('支付于 {time}', { time: isoDate(row.paid_at) }) : t("暂无支付记录")}</small></div> },
    { key: 'actions', header: t("操作"), hideable: false, pin: 'right', render: row => <button className="button order-detail-button" aria-label={t('查看订单 {trade} 详情', { trade: row.trade_no })} onClick={() => setDetail(row.trade_no)}>{t("详情")}<ArrowUpRight size={13} aria-hidden="true" /></button> },
  ]
  return <div className="native-management-page commerce-reference orders-page">
    <PageHeader title={t("订单管理")} description={t("购买记录与订单处理")}
      action={<button className="button" disabled={!valid || query.isFetching} onClick={() => void recovery.refresh()}><RefreshCw size={15} />{t("刷新")}</button>} />
    {recovery.state.error ? <NetworkRecovery management={recovery} blocked={query.isFetching || query.isError || !query.data} /> : null}
    {detail === null ? <OrderCommandRecovery /> : null}
    {!valid ? <p role="alert">{t("用户 ID、订单号或状态参数有误，请修正筛选。")}</p> : null}
    <ReadList title="订单列表" rows={data?.data ?? []} columns={columns} meta={data?.meta} perPage={perPage} onPageSize={resize} page={page}
      ready={!!data} loading={query.isFetching} error={query.isError ? query.error : undefined}
      onRetry={() => void recovery.refresh()} onPage={paginate}
      columnSettingsKey="orders" stickyHeader stickyFirstColumn mobilePrimaryKey="trade">
      <header className="commerce-card-heading"><div><h2>{t("订单列表")}</h2><p>{t("按用户、订单号或处理状态查询购买记录")}</p></div>{data ? <Tag>{t('{count} 笔订单', { count: formatNumber(data.meta.total) })}</Tag> : null}</header><form className="management-filter-form" onSubmit={apply}>
        <FilterBar applied={[
          ...(appliedUser ? [{ key: 'user', label: t('用户 #{id}', { id: appliedUser }) }] : []),
          ...(appliedTrade ? [{ key: 'trade', label: t('订单 {trade}', { trade: appliedTrade }) }] : []),
          ...(appliedStatus !== '' ? [{ key: 'status', label: orderStatus(Number(appliedStatus)) }] : []),
        ]} onRemove={removeFilter} hint={t("订单号与用户 ID 为精确查询，点击订单号查看金额与履约进度")} onClear={() => setParams(perPage !== 20 ? { per_page: String(perPage) } : {})}>
          <label>{t("用户 ID")}<Input aria-label={t("订单用户 ID")} value={user} maxLength={19}
            onChange={event => setUser(event.target.value)} inputMode="numeric" placeholder={t("精确用户 ID")} /></label>
          <label>{t("订单号")}<Input aria-label={t("订单号")} value={trade} maxLength={128}
            onChange={event => setTrade(event.target.value)} placeholder={t("精确订单号")} /></label>
          <FacetedFilter label={t("订单状态")} multiple={false} value={status ? [status] : []} onChange={next => setStatus(next[0] ?? '')}
            options={[0, 1, 2, 3, 4].map(value => ({ value: String(value), label: orderStatus(value) }))} />
          <button className="button primary" type="submit"><Search size={15} aria-hidden="true" />{t("查询")}</button>
        </FilterBar>
      </form>
      <ListRuleFilter definition={orderRules} />
    </ReadList>
    {detail ? <OrderReadDialog key={detail} tradeNo={detail} onClose={() => setDetail(null)} /> : null}
  </div>
}
