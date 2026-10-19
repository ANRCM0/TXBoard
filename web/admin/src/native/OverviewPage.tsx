import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { ArrowUpRight, Server, Network, Settings, ShieldCheck, Users, ReceiptText, MessageCircle, Bell, BookOpen, Tag, Gift, WalletCards, CreditCard, Boxes, Route, FileText, Plug, Package, Activity, Mail, RefreshCcw, RefreshCw } from 'lucide-react'
import { admin } from './session'
import { ManagementFeedback } from './Feedback'
import { PageHeader } from '../components/ui/PageHeader'
import { DataTable } from '../components/ui/DataTable'
import { isoDate, minorAmount, orderStatus } from './readPresentation'
import { DonutChart, LineChart } from '../components/charts/ReferenceCharts'
import { nodeTrafficLines, orderStatusShare, orderTrend, type OverviewRange } from './overviewCharts'

const entries = [
  { href: '/user/manage', title: '用户管理', description: '查询账户与当前订阅', icon: Users },
  { href: '/finance/order', title: '订单管理', description: '查询原生购买订单与详情', icon: ReceiptText },
  { href: '/finance/plan', title: '套餐管理', description: '查看完整发布历史、发布新修订与调整可用性', icon: CreditCard },
  { href: '/user/ticket', title: '工单管理', description: '认领工单、回复并处理私有附件', icon: MessageCircle },
  { href: '/config/notice', title: '公告管理', description: '编辑公告、发布时间与受众', icon: Bell },
  { href: '/config/knowledge', title: '知识库管理', description: '管理知识内容、分类与排序', icon: BookOpen },
  { href: '/finance/coupon', title: '优惠券', description: '创建优惠策略或停用后续使用', icon: Tag },
  { href: '/finance/gift-card', title: '礼品卡', description: '查看批次与逐卡状态、撤销未兑换卡片', icon: Gift },
  { href: '/finance/commission', title: '邀请与佣金', description: '查看邀请奖励、审核钱包转入申请', icon: WalletCards },
  { href: '/server/manage', title: '节点管理', description: '查看节点详情、状态与服务器归属', icon: Network },
  { href: '/server/machine', title: '服务器管理', description: '查看服务器详情、编辑状态与镜像通道', icon: Server },
  { href: '/server/group', title: '权限组管理', description: '维护用户、套餐和节点使用的分组', icon: Boxes },
  { href: '/server/route', title: '路由管理', description: '维护路由与完整排序、只读模拟节点匹配', icon: Route },
  { href: '/server/access-audit', title: '访问审计', description: '维护审计规则、筛选节点访问事件', icon: ShieldCheck },
  { href: '/config/system', title: '系统配置', description: '管理站点、注册、安全与通知设置', icon: Settings },
  { href: '/system/sessions', title: '登录会话', description: '查看并撤销管理员账户的会话', icon: ShieldCheck },
  { href: '/system/audit-log', title: '审计日志', description: '按操作、管理员与关键字查询审计记录', icon: FileText },
  { href: '/system/modules', title: '模块中心', description: '查看模块状态、兼容性与部署支持', icon: Boxes },
  { href: '/config/plugin', title: '插件管理', description: '查看插件清单、健康与能力', icon: Plug },
  { href: '/config/theme', title: '主题管理', description: '查看主题清单与当前运行状态', icon: Package },
  { href: '/config/payment', title: '支付配置', description: '配置原生商户渠道并核对当前可用性', icon: WalletCards },
  { href: '/user/traffic-reset', title: '订阅流量重置', description: '核对当前订阅并重置流量周期', icon: RefreshCcw },
  { href: '/system/queue', title: '队列监控', description: '查看任务状态与近期失败记录', icon: Activity },
  { href: '/config/email/templates', title: '邮件模板', description: '编辑通知模板并显式发送测试邮件', icon: Mail },
]
export function OverviewPage() {
  const [range, setRange] = useState<OverviewRange>(14)
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
  const queries = [identity, accounts, orders, nodes, machines]
  const refreshing = queries.some(query => query.isFetching)
  const updatedAt = Math.max(...queries.map(query => query.dataUpdatedAt))
  const orderRows = orders.isError ? [] : orders.data?.data ?? []
  const trend = orderTrend(orderRows, range, new Date(orders.dataUpdatedAt || Date.now()))
  const distribution = orderStatusShare(orderRows)
  const traffic = nodeTrafficLines(nodes.isError ? [] : nodes.data?.data ?? [])
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
      <div className="reference-card-heading"><div><h2>节点累计流量</h2><p>节点间对比 · 上传与下载折线</p></div><Link to="/server/manage">全部节点<ArrowUpRight size={15} /></Link></div>
      <ManagementFeedback error={nodes.isError ? nodes.error : undefined} loading={nodes.isFetching} onRetry={() => void nodes.refetch()} />
      {!nodes.isError && nodes.data ? <>
        <LineChart title="样本节点累计字节" labels={traffic.labels} series={traffic.series} xAxisLabel="节点" formatValue={traffic.formatValue} emptyText="暂无节点流量数据" />
        <p className="overview-chart-scope">{traffic.scopeLabel}。横轴为节点，纵轴为累计字节计数；计数可被重置，不表示历史趋势或今日新增流量。</p>
      </> : null}
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
    <section className="card overview-shortcuts" aria-label="管理入口">
      <div className="reference-card-heading"><div><h2>管理入口</h2><p>账户、业务与系统管理</p></div>{!identity.isError && identity.data ? <span className="reference-secondary">{identity.data.data.email}</span> : null}</div>
    <div className="native-overview-grid">
      {entries.map(({ href, title, description, icon: Icon }) => <Link className="card native-overview-card" to={href} key={href}>
        <div className="native-overview-icon"><Icon size={20} /></div>
        <div className="native-overview-copy"><strong>{title}</strong><span>{description}</span></div>
        <ArrowUpRight className="native-overview-arrow" size={16} aria-hidden="true" />
      </Link>)}
    </div>
    </section>
  </div>
}
