import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Info, ArrowUpRight, Server, Network, Users, ReceiptText, RefreshCw } from 'lucide-react'
import { admin } from './session'
import { ManagementFeedback } from './Feedback'
import { PageHeader } from '../components/ui/PageHeader'
import { MetricCard } from '../components/ui/MetricCard'
import { SegmentedControl } from '../components/ui/SegmentedControl'
import './overview-reference.css'
import { DataTable } from '../components/ui/DataTable'
import { isoDate, minorAmount, orderStatus } from './readPresentation'
import { DonutChart, LineChart } from '../components/charts/ReferenceCharts'
import { nodeTrafficHistory, orderStatusShare, incomeTrend, type OverviewRange } from './overviewCharts'

const ranges = ([7, 14, 30] as const).map(value => ({ value, label: value + ' 天' }))

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
  const trend = incomeTrend(orderRows, range, new Date(orders.dataUpdatedAt || Date.now()))
  const distribution = orderStatusShare(orderRows)
  const traffic = nodeTrafficHistory(!nodes.isError && !history.isError && trafficEnabled ? history.data?.data : undefined)
  const metrics = [
    { label: '账户总数', icon: Users, query: accounts, href: '/user/manage', tone: 'blue' as const },
    { label: '订单总数', icon: ReceiptText, query: orders, href: '/finance/order', tone: 'purple' as const },
    { label: '节点总数', icon: Network, query: nodes, href: '/server/manage', tone: 'green' as const },
    { label: '服务器总数', icon: Server, query: machines, href: '/server/machine', tone: 'orange' as const },
  ]
  return <div className="native-management-page reference-overview">
    <PageHeader title="管理中心" description="站点资源概览" action={<div className="overview-refresh">
      <span className="overview-update">{refreshing ? '更新中' : updatedAt ? new Date(updatedAt).toLocaleTimeString('zh-CN', { hour: '2-digit', minute: '2-digit' }) + ' 更新' : '等待更新'}</span>
      <button className="icon-button" aria-label="刷新概览" title="刷新概览" disabled={refreshing} onClick={() => { void Promise.allSettled(queries.map(query => query.refetch())) }}><RefreshCw size={19} className={refreshing ? 'is-spinning' : ''} /></button>
    </div>} />
    <ManagementFeedback error={identity.isError ? identity.error : undefined} loading={identity.isFetching}
      onRetry={() => void identity.refetch()} />
    <section className="overview-metrics" aria-label="资源统计">
      {metrics.map(({ label, icon: Icon, query, href, tone }) => <MetricCard key={label} label={label} tone={tone}
        icon={<Icon size={19} aria-hidden="true" />} href={href}
        value={query.isError ? '—' : query.data ? query.data.meta.total.toLocaleString('zh-CN') : '…'}
        caption={query.isError ? '暂不可用，点击查看' : '查看全部'} />)}
    </section>
    <div className="overview-charts-grid">
      <section className="card overview-chart-card" aria-label="收入趋势卡片">
        <div className="reference-card-heading"><div><h2>收入趋势</h2><p>已支付订单金额 · 按支付日期 · UTC</p></div>
          <SegmentedControl label="收入趋势时间范围" value={range} options={ranges} onChange={setRange} />
        </div>
        <ManagementFeedback error={orders.isError ? orders.error : undefined} loading={orders.isFetching} onRetry={() => void orders.refetch()} />
        {!orders.isError && orders.data ? <>
          <LineChart title="样本收入趋势" yAxisWidth={92} formatValue={trend.formatValue} labels={trend.labels} series={trend.hasData ? trend.series : []}
            emptyText="此时间范围内暂无已支付样本订单" />
          <div className="overview-chart-footer"><span>最近 {trend.sampleSize} 条订单 · 所选范围内 {trend.includedCount} 条已支付</span>
            <details className="overview-scope-details"><summary><Info size={14} />收入统计口径</summary><p>按支付日期分别汇总各币种的订单金额，含钱包支付，未扣除退款；最近订单样本不代表全站实收。零值表示样本中没有对应记录。</p></details></div>
        </> : null}
      </section>
      <section className="card overview-chart-card" aria-label="订单状态卡片">
        <div className="reference-card-heading"><div><h2>订单状态</h2><p>最近订单的当前状态分布</p></div><Link to="/finance/order">全部订单<ArrowUpRight size={15} /></Link></div>
        {!orders.isError && orders.data ? <>
          <DonutChart title="样本订单状态" data={distribution.data} centerLabel="样本订单" />
          <p className="overview-chart-scope">最近 {distribution.sampleSize} 条订单的当前状态；与收入时间范围独立。</p>
        </> : <p className="overview-chart-scope">{orders.isError ? '订单状态暂不可用' : '正在读取订单状态…'}</p>}
      </section>
    </div>
    <section className="card overview-chart-card" aria-label="节点流量卡片">
      <div className="reference-card-heading"><div><h2>节点总流量</h2><p>每日上传 + 下载 · GB · UTC</p></div>
        <SegmentedControl label="节点流量时间范围" value={trafficRange} options={ranges} onChange={setTrafficRange} />
      </div>
      <ManagementFeedback error={nodes.isError ? nodes.error : undefined} loading={nodes.isFetching} onRetry={() => void nodes.refetch()} />
      {trafficEnabled ? <ManagementFeedback error={history.isError ? history.error : undefined} loading={history.isFetching} onRetry={() => void history.refetch()} /> : null}
      {!nodes.isError && !history.isError && history.data && trafficEnabled ? <>
        <LineChart title="节点总流量" labels={traffic.labels} series={traffic.series} xAxisLabel="日期（UTC）" yAxisWidth={92} formatValue={traffic.formatValue} emptyText="暂无节点流量数据" />
        <div className="overview-chart-footer"><span>当前列表前 {traffic.nodeCount} 个节点 · 每条曲线为一个节点</span>
          <details className="overview-scope-details"><summary><Info size={14} />流量统计口径</summary><p>每日总流量为原始上传与下载之和，按已结算批次结束日期（UTC）汇总。1 GB = 10⁹ 字节，显示保留两位小数；今日数据截至最近刷新。</p></details></div>
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
