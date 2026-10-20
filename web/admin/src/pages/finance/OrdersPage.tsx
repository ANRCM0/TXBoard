import '../../native/commerce-reference.css'
import { useQuery } from '@tanstack/react-query'
import { useEffect, useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { RefreshCw, Search } from 'lucide-react'
import type { CoreOutput } from '@txboard/core-sdk'
import { admin } from '../../native/session'
import { ReadList } from '../../native/ReadList'
import { OrderReadDialog } from '../../native/OrderReadDialog'
import { OrderCommandRecovery } from '../../native/OrderAdministration'
import { isoDate, minorAmount, orderStatus, readPage, validId, validTradeNo } from '../../native/readPresentation'
import { PageHeader } from '../../components/ui/PageHeader'
import { FilterBar } from '../../components/ui/FilterBar'
import { Input, Select } from '../../components/ui/Field'
import { StatusBadge } from '../../components/ui/StatusBadge'
import type { Column } from '../../components/ui/DataTable'

type Order = CoreOutput<'commerce.admin-orders.list'>[number]
export function OrdersPage() {
  const [params, setParams] = useSearchParams()
  const page = readPage(params.get('page'))
  const appliedUser = params.get('user_id') ?? ''
  const appliedTrade = params.get('trade_no') ?? ''
  const appliedStatus = params.get('status') ?? ''
  const [user, setUser] = useState(appliedUser), [trade, setTrade] = useState(appliedTrade)
  const [status, setStatus] = useState(appliedStatus), [detail, setDetail] = useState<string | null>(null)
  useEffect(() => {
    setUser(appliedUser); setTrade(appliedTrade); setStatus(appliedStatus); setDetail(null)
  }, [appliedUser, appliedTrade, appliedStatus, page])
  const valid = (!appliedUser || validId(appliedUser)) && (!appliedTrade || validTradeNo(appliedTrade))
    && ['', '0', '1', '2', '3', '4'].includes(appliedStatus)
  const input = { page, per_page: 20, ...(appliedUser ? { user_id: appliedUser } : {}),
    ...(appliedTrade ? { trade_no: appliedTrade } : {}), ...(appliedStatus !== '' ? { status: Number(appliedStatus) } : {}) }
  const query = useQuery({ queryKey: ['native-admin', 'admin-orders', input], enabled: valid,
    queryFn: ({ signal }) => admin.read('commerce.admin-orders.list', input, signal) })
  const data = query.isError ? undefined : query.data
  function apply(event: FormEvent) {
    event.preventDefault()
    setParams({ ...(user.trim() ? { user_id: user.trim() } : {}), ...(trade.trim() ? { trade_no: trade.trim() } : {}),
      ...(status !== '' ? { status } : {}) })
  }
  function paginate(nextPage: number) {
    setParams(current => { const next = new URLSearchParams(current); next.set('page', String(nextPage)); return next })
  }
  const columns: Column<Order>[] = [
    { key: 'trade', header: '订单号', render: row => <div className="native-row-identity"><button className="native-read-link commerce-order-link" onClick={() => setDetail(row.trade_no)}>{row.trade_no}</button><small>订单 #{row.id}</small></div> },
    { key: 'user', header: '用户 ID', render: row => <Link className="table-link" to={'/user/' + row.user_id}>#{row.user_id}</Link> },
    { key: 'plan', header: '套餐', render: row => row.plan?.name ?? '未记录' },
    { key: 'amount', header: '订单金额', render: row => <strong className="reference-number">{minorAmount(row.amount_minor, row.currency)}</strong> },
    { key: 'status', header: '状态', render: row => <StatusBadge tone={row.status === 3 ? 'success' : row.status === 0 || row.status === 1 ? 'warning' : 'neutral'}>{orderStatus(row.status)}</StatusBadge> },
    { key: 'created', header: '创建时间', render: row => isoDate(row.created_at) },
  ]
  return <div className="native-management-page commerce-reference orders-page">
    <PageHeader title="订单管理" description="购买记录与订单处理"
      action={<button className="button" disabled={!valid || query.isFetching} onClick={() => void query.refetch()}><RefreshCw size={15} />刷新</button>} />
    {detail === null ? <OrderCommandRecovery /> : null}
    {!valid ? <p role="alert">用户 ID、订单号或状态参数有误，请修正筛选。</p> : null}
    <ReadList title="订单列表" rows={data?.data ?? []} columns={columns} meta={data?.meta} page={page}
      ready={!!data} loading={query.isFetching} error={query.isError ? query.error : undefined}
      onRetry={() => void query.refetch()} onPage={paginate}
      columnSettingsKey="orders" stickyHeader stickyFirstColumn>
      <header className="commerce-card-heading"><div><h2>订单列表</h2><p>按用户、订单号或处理状态查询购买记录</p></div></header><form className="management-filter-form" onSubmit={apply}>
        <FilterBar applied={[
          ...(appliedUser ? [{ key: 'user', label: '用户 #' + appliedUser }] : []),
          ...(appliedTrade ? [{ key: 'trade', label: '订单 ' + appliedTrade }] : []),
          ...(appliedStatus !== '' ? [{ key: 'status', label: orderStatus(Number(appliedStatus)) }] : []),
        ]} onClear={() => setParams({})}>
          <label>用户 ID<Input aria-label="订单用户 ID" value={user} maxLength={19}
            onChange={event => setUser(event.target.value)} inputMode="numeric" placeholder="精确用户 ID" /></label>
          <label>订单号<Input aria-label="订单号" value={trade} maxLength={128}
            onChange={event => setTrade(event.target.value)} placeholder="精确订单号" /></label>
          <label>订单状态<Select aria-label="订单状态" value={status} onChange={event => setStatus(event.target.value)}>
            <option value="">全部状态</option>{[0, 1, 2, 3, 4].map(value => <option key={value} value={String(value)}>{orderStatus(value)}</option>)}
          </Select></label>
          <button className="button primary" type="submit"><Search size={15} aria-hidden="true" />查询</button>
        </FilterBar>
      </form>
    </ReadList>
    {detail ? <OrderReadDialog key={detail} tradeNo={detail} onClose={() => setDetail(null)} /> : null}
  </div>
}
