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
    { key: 'trade', header: '订单号', render: row => <button className="native-read-link" onClick={() => setDetail(row.trade_no)}>{row.trade_no}</button> },
    { key: 'user', header: '用户 ID', render: row => <Link className="table-link" to={'/user/' + row.user_id}>#{row.user_id}</Link> },
    { key: 'plan', header: '套餐', render: row => row.plan?.name ?? '未记录' },
    { key: 'amount', header: '订单金额', render: row => <strong className="reference-number">{minorAmount(row.amount_minor, row.currency)}</strong> },
    { key: 'status', header: '状态', render: row => <span className={'reference-status ' + (row.status === 3 ? 'is-success' : row.status === 0 || row.status === 1 ? 'is-warning' : '')}>{orderStatus(row.status)}</span> },
    { key: 'created', header: '创建时间', render: row => isoDate(row.created_at) },
  ]
  return <div className="native-management-page">
    <PageHeader title="订单管理" description="购买记录与订单处理"
      action={<button className="button" disabled={!valid || query.isFetching} onClick={() => void query.refetch()}><RefreshCw size={15} />刷新</button>} />
    {detail === null ? <OrderCommandRecovery /> : null}
    {!valid ? <p role="alert">用户 ID、订单号或状态参数有误，请修正筛选。</p> : null}
    <ReadList title="订单列表" rows={data?.data ?? []} columns={columns} meta={data?.meta} page={page}
      ready={!!data} loading={query.isFetching} error={query.isError ? query.error : undefined}
      onRetry={() => void query.refetch()} onPage={paginate}>
      <form className="management-filter-form" onSubmit={apply}>
        <label>用户 ID<input aria-label="订单用户 ID" value={user} maxLength={19}
          onChange={event => setUser(event.target.value)} inputMode="numeric" placeholder="精确用户 ID" /></label>
        <label>订单号<input aria-label="订单号" value={trade} maxLength={128}
          onChange={event => setTrade(event.target.value)} placeholder="精确订单号" /></label>
        <label>订单状态<select aria-label="订单状态" value={status} onChange={event => setStatus(event.target.value)}>
          <option value="">全部状态</option>{[0, 1, 2, 3, 4].map(value => <option key={value} value={String(value)}>{orderStatus(value)}</option>)}
        </select></label>
        <button className="button primary" type="submit"><Search size={15} />查询</button>
        <button className="button" type="button" onClick={() => setParams({})}>清除筛选</button>
      </form>
    </ReadList>
    {detail ? <OrderReadDialog key={detail} tradeNo={detail} onClose={() => setDetail(null)} /> : null}
  </div>
}
