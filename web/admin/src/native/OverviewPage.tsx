import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { ArrowUpRight, Server, Network, Users, ReceiptText, RefreshCw } from 'lucide-react'
import { admin } from './session'
import { ManagementFeedback } from './Feedback'
import { PageHeader } from '../components/ui/PageHeader'
import { DataTable } from '../components/ui/DataTable'
import { isoDate, minorAmount, orderStatus } from './readPresentation'
import { DonutChart, LineChart } from '../components/charts/ReferenceCharts'
import { nodeTrafficHistory, orderStatusShare, orderTrend, type OverviewRange } from './overviewCharts'

export function OverviewPage() {
  const [range, setRange] = useState<OverviewRange>(14)
  const [trafficRange, setTrafficRange] = useState<OverviewRange>(14)
  const identity = useQuery({ queryKey: ['native-admin', 'identity'],
    queryFn: ({ signal }) => admin.read('identity.admin.get', {}, signal) })
  const accounts = useQuery({ queryKey: ['native-admin', 'overview', 'accounts'],
    queryFn: ({ signal }) => admin.read('identity.accounts.list', { page: 1, per_page: 1 }, signal) })
  const orders = useQuery({ queryKey: ['native-admin', 'overview', 'orders'],
    queryFn: ({ signal }) => admin.read('commerce.admin-orders.list', { page: 1, per_page: 100 }, signal) })
  const nodes = useQuery({ queryKey: ['native-admin', 'overview', 'nodes'],
    queryFn: ({ signal }) => admin.read('network.nodes.list', { page: 1, per_page: 5 }, signal) })
  const machines = useQuery({ queryKey: ['native-admin', 'overview', 'machines'],
    queryFn: ({ signal }) => admin.read('network.machines.list', { page: 1, per_page: 1 }, signal) })
  const nodeIds = nodes.isError ? [] : nodes.data?.data.map(node => node.id) ?? []
  const trafficEnabled = nodeIds.length > 0
  const history = useQuery({ queryKey: ['native-admin', 'overview', 'node-traffic', trafficRange, nodeIds],
    queryFn: ({ signal }) => admin.read('network.nodes.traffic-history.get', { days: trafficRange, node_ids: nodeIds }, signal),
    enabled: trafficEnabled })
  const queries = [identity, accounts, orders, nodes, machines, ...(trafficEnabled ? [history] : [])]
  const refreshing = queries.some(query => query.isFetching)
  const updatedAt = Math.max(...queries.map(query => query.dataUpdatedAt))
  const orderRows = orders.isError ? [] : orders.data?.data ?? []
  const trend = orderTrend(orderRows, range, new Date(orders.dataUpdatedAt || Date.now()))
  const distribution = orderStatusShare(orderRows)
  const traffic = nodeTrafficHistory(!nodes.isError && !history.isError && trafficEnabled ? history.data?.data : undefined)
  const metrics = [
    { label: '账户总数', icon: Users, query: accounts, href: '/user/manage', tone: 'blue' },
    { label: '订单总数', icon: ReceiptText, query: orders, href: '/finance/order', tone: 'purple' },
    { label: '节点总数', icon: Network, query: nodes, href: '/server/manage', tone: 'green' },
    { label: '服务器总数', icon: Server, query: machines, href: '/server/machine', tone: 'orange' },
  ]
  return <div className="native-management-page reference-overview">
    <PageHeader title="管理中心" description="站点资源概览" action={<div className="overview-refresh">
      <span className="overview-update">{refreshing ? '更新中' : updatedAt ? new Date(updatedAt).toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' }) + ' 更新' : '等待更新'}</span>
      <button className="icon-button" aria-label="刷新概览" title="刷新概览" disabled={refreshing} onClick={() => { void Promise.allSettled(queries.map(query => query.refetch())) }}><RefreshCw size={19} className={refreshing ? 'is-spinning' : ''} /></button>
    </div>} />
    <ManagementFeedback error={identity.isError ? identity.error : undefined} loading={identity.isFetching}
      onRetry={() => void identity.refetch()} />
    <section className="overview-metrics" aria-label="资源统计">
      {metrics.map(({ label, icon: Icon, query, href, tone }) => <Link className={'card overview-metric tone-' + tone} to={href} key={label}>
        <div className="overview-metric-heading"><span>{label}</span><Icon size={19} /></div>
        <strong>{query.isError ? '—' : query.data ? query.data.meta.total.toLocaleString('zh-CN') : '…'}</strong>
        <span className="overview-metric-caption">{query.isError ? '暂不可用，点击查看' : '查看全部'}<ArrowUpRight size={14} /></span>
      </Link>)}
    </section>
    <div className="overview-charts-grid">
      <section className="card overview-chart-card" aria-label="订单趋势卡片">
        <div className="reference-card-heading"><div><h2>订单趋势</h2><p>按创建日期 · UTC · 当前状态</p></div>
          <div className="overview-range" role="group" aria-label="订单趋势时间范围">{([7, 14, 30] as const).map(days =>
            <button type="button" key={days} aria-pressed={range === days} onClick={() => setRange(days)}>{days} 天</button>)}</div>
        </div>
        <ManagementFeedback error={orders.isError ? orders.error : undefined} loading={orders.isFetching} onRetry={() => void orders.refetch()} />
        {!orders.isError && orders.data ? <>
          <LineChart title="样本订单趋势" labels={trend.labels} series={trend.hasData ? trend.series : []}
            emptyText="此时间范围内暂无样本订单" />
          <p className="overview-chart-scope">最近 {trend.sampleSize} 条订单中，{trend.includedCount} 条在所选范围内。零值表示样本中没有记录，不代表全站当日无订单。</p>
        </> : null}
      </section>
      <section className="card overview-chart-card" aria-label="订单状态卡片">
        <div className="reference-card-heading"><div><h2>订单状态</h2><p>最近订单的当前状态分布</p></div><Link to="/finance/order">全部订单<ArrowUpRight size={15} /></Link></div>
        {!orders.isError && orders.data ? <>
          <DonutChart title="样本订单状态" data={distribution.data} centerLabel="样本订单" />
          <p className="overview-chart-scope">{distribution.scopeLabel}。切换趋势范围不会改变此样本。</p>
        </> : <p className="overview-chart-scope">{orders.isError ? '订单状态暂不可用' : '正在读取订单状态…'}</p>}
      </section>
    </div>
    <section className="card overview-chart-card" aria-label="节点流量卡片">
      <div className="reference-card-heading"><div><h2>节点总流量</h2><p>每日上传 + 下载 · GB · UTC</p></div>
        <div className="overview-range" role="group" aria-label="节点流量时间范围">{([7, 14, 30] as const).map(days =>
          <button type="button" key={days} aria-pressed={trafficRange === days} onClick={() => setTrafficRange(days)}>{days} 天</button>)}</div>
      </div>
      <ManagementFeedback error={nodes.isError ? nodes.error : undefined} loading={nodes.isFetching} onRetry={() => void nodes.refetch()} />
      {trafficEnabled ? <ManagementFeedback error={history.isError ? history.error : undefined} loading={history.isFetching} onRetry={() => void history.refetch()} /> : null}
      {!nodes.isError && !history.isError && history.data && trafficEnabled ? <>
        <LineChart title="节点总流量" labels={traffic.labels} series={traffic.series} xAxisLabel="日期（UTC）" yAxisWidth={92} formatValue={traffic.formatValue} emptyText="暂无节点流量数据" />
        <p className="overview-chart-scope">当前列表前 {traffic.nodeCount} 个节点，每个节点一条曲线。按已结算批次结束日期汇总原始上传与下载，1 GB = 10⁹ 字节；今日数据截至最近刷新。</p>
      </> : null}
      {!nodes.isError && nodes.data && !trafficEnabled ? <p className="overview-chart-scope">暂无节点流量数据</p> : null}
    </section>
    <div className="overview-data-grid">
      <section className="card overview-data-card" aria-label="最近订单">
        <div className="reference-card-heading"><div><h2>最近订单</h2><p>当前列表前 5 条</p></div><Link to="/finance/order">全部订单<ArrowUpRight size={15} /></Link></div>
        <ManagementFeedback error={orders.isError ? orders.error : undefined} loading={orders.isFetching} onRetry={() => void orders.refetch()} />
        {!orders.isError && orders.data ? <DataTable rows={orders.data.data.slice(0, 5)} rowKey={row => row.id} label="最近订单列表" columns={[
          { key: 'order', header: '订单', render: row => <div className="native-row-identity"><Link className="table-link" to={'/finance/order?trade_no=' + encodeURIComponent(row.trade_no)}>{row.trade_no}</Link><small>{row.plan?.name ?? '未记录套餐'}</small></div> },
          { key: 'amount', header: '金额', render: row => <span className="reference-number">{minorAmount(row.amount_minor, row.currency)}</span> },
          { key: 'status', header: '状态', render: row => <span className={'reference-status ' + (row.status === 3 ? 'is-success' : row.status === 0 || row.status === 1 ? 'is-warning' : '')}>{orderStatus(row.status)}</span> },
          { key: 'time', header: '创建时间', render: row => <span className="reference-secondary">{isoDate(row.created_at)}</span> },
        ]} /> : null}
      </section>
      <section className="card overview-data-card" aria-label="节点概况">
        <div className="reference-card-heading"><div><h2>节点概况</h2><p>当前列表前 5 条</p></div><Link to="/server/manage">全部节点<ArrowUpRight size={15} /></Link></div>
        <ManagementFeedback error={nodes.isError ? nodes.error : undefined} loading={nodes.isFetching} onRetry={() => void nodes.refetch()} />
        {!nodes.isError && nodes.data ? <div className="overview-node-list">{nodes.data.data.length ? nodes.data.data.slice(0, 5).map(node => <Link to={'/server/node/' + node.id} className="overview-node" key={node.id}>
          <span className="overview-node-icon"><Server size={18} /></span><span className="native-row-identity"><strong>{node.name}</strong><small>{node.type.toUpperCase()} · {node.host}</small></span><span className={'reference-status ' + (node.enabled ? 'is-success' : '')}>{node.enabled ? '已启用' : '已禁用'}</span>
        </Link>) : <p className="reference-secondary">暂无节点</p>}</div> : null}
      </section>
    </div>
  </div>
}
