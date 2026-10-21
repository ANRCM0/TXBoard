import { t, useLocale, getLocale } from '../lib/i18n'
import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { ArrowUpRight, Users, ReceiptText, RefreshCw, Wallet, Ticket } from 'lucide-react'
import { admin, useAdminSession } from './session'
import { QueueFailures } from './QueueFailures'
import { ManagementFeedback } from './Feedback'
import { PageHeader } from '../components/ui/PageHeader'
import { MetricCard } from '../components/ui/MetricCard'
import { SegmentedControl } from '../components/ui/SegmentedControl'
import { DataTable } from '../components/ui/DataTable'
import { LineChart } from '../components/charts/ReferenceCharts'
import { ChartSkeleton } from '../components/ui/Skeleton'
import { DateRangePicker } from '../components/ui/DateRangePicker'
import { minorAmount } from './readPresentation'
import { gigabytes } from '../lib/format'
import { dashboardDates, dashboardRanges, nativeDashboardTrend, type DashboardMoney, type DashboardTrendKind, type DashboardMeasure } from './overviewCharts'
import './overview-reference.css'
import './dashboard-native.css'

const trendKinds = [
  { value: 'income' as const, label: '订单收入' },
  { value: 'earned_commission' as const, label: '已入账佣金' },
  { value: 'commission_transfer' as const, label: '已转入钱包佣金' },
]
const queueStatuses = { unavailable: '不可用', not_applicable: '不适用', inactive: '未运行', paused: '已暂停', running: '运行中' }
const amounts = (rows: readonly DashboardMoney[]) => rows.length ? rows.map(row => minorAmount(row.amount_minor, row.currency)).join(' / ') : '0'
const count = (value: string) => BigInt(value).toLocaleString(getLocale())

export function OverviewPage() {
  useLocale()
  const session = useAdminSession()
  const [period, setPeriod] = useState('30')
  const [dates, setDates] = useState(() => dashboardDates(30))
  const [kind, setKind] = useState<DashboardTrendKind>('income')
  const [measure, setMeasure] = useState<DashboardMeasure>('amount')
  const [currency, setCurrency] = useState('')
  const dashboard = useQuery({ queryKey: ['native-admin', 'overview', 'native', dates.start_date, dates.end_date], enabled: session,
    queryFn: ({ signal }) => admin.read('analytics.native-dashboard.get', dates, signal) })
  const queue = useQuery({ queryKey: ['native-admin', 'queue-snapshot'], enabled: session,
    queryFn: ({ signal }) => admin.read('platform.queue.snapshot.get', {}, signal) })
  const denied = dashboard.error instanceof CoreAdminApiError && [401, 403].includes(dashboard.error.status)
  const current = session && !dashboard.isError ? dashboard.data?.data : undefined
  const queueCurrent = session && !denied && !queue.isError ? queue.data?.data : undefined
  const currencies = [...new Set(current?.trends.flatMap(point => point[kind].map(row => row.currency)) ?? [])].sort()
  const activeCurrency = currencies.includes(currency) ? currency : currencies[0] ?? 'CNY'
  const trend = nativeDashboardTrend(current?.trends ?? [], kind, measure, activeCurrency)
  const refreshing = dashboard.isFetching || queue.isFetching
  const updatedAt = current?.observed_at
  const cards = current?.cards
  const metrics = [
    { label: t('今日收入'), value: cards ? amounts(cards.today_income) : '—', caption: t('已支付原生订单 · UTC'), icon: ReceiptText, tone: 'blue' as const, href: '/finance/order' },
    { label: t('本月收入'), value: cards ? amounts(cards.month_income) : '—', caption: t('本月累计 · 分币种'), icon: ReceiptText, tone: 'blue' as const, href: '/finance/order' },
    { label: t('上月收入'), value: cards ? amounts(cards.last_month_income) : '—', caption: t('上个自然月 · 分币种'), icon: ReceiptText, tone: 'green' as const, href: '/finance/order' },
    { label: t('本月已转入钱包佣金'), value: cards ? amounts(cards.month_commission_transfer) : '—', caption: t('已批准转入 · 非全部应计佣金'), icon: Wallet, tone: 'orange' as const, href: '/finance/commission' },
    { label: t('上月已转入钱包佣金'), value: cards ? amounts(cards.last_month_commission_transfer) : '—', caption: t('上个自然月已批准转入'), icon: Wallet, tone: 'orange' as const, href: '/finance/commission' },
    { label: t('本月新增账户'), value: cards ? count(cards.month_new_accounts) : '—', caption: t('按原生身份创建时间'), icon: Users, tone: 'green' as const, href: '/user/manage' },
    { label: t('待回复工单'), value: cards ? count(cards.pending_tickets) : '—', caption: t('开启且等待回复'), icon: Ticket, tone: 'blue' as const, href: '/user/ticket' },
    { label: t('待审核佣金转入'), value: cards ? count(cards.pending_commission_payouts) : '—', caption: t('当前申请笔数'), icon: Wallet, tone: 'orange' as const, href: '/finance/commission' },
  ]
  function selectPeriod(value: string) {
    setPeriod(value)
    if (value !== 'custom') setDates(dashboardDates(Number(value)))
  }
  return <div className="native-management-page reference-overview native-dashboard">
    <PageHeader title={t("管理中心")} description={t("原生订单、佣金与已结算流量")} action={<div className="overview-refresh">
      <span className="overview-update">{refreshing ? t('更新中') : updatedAt ? new Date(updatedAt * 1000).toLocaleTimeString(getLocale()) + t(' 更新') : t('等待更新')}</span>
      <button className="icon-button" aria-label={t("刷新概览")} title={t("刷新概览")} disabled={!session || refreshing} onClick={() => { void Promise.allSettled([dashboard.refetch(), queue.refetch()]) }}><RefreshCw size={19} className={refreshing ? 'is-spinning' : ''} /></button>
    </div>} />
    <ManagementFeedback error={dashboard.error} loading={dashboard.isFetching} onRetry={() => void dashboard.refetch()} />
    {denied ? <p role="alert">{t("无法确认概览读取权限，已隐藏统计、趋势与排行榜。")}</p> : null}
    <section className="overview-metrics" aria-label={t("原生经营统计")}>{metrics.map(({ label, value, caption, icon: Icon, tone, href }) => <MetricCard key={label} label={label} value={value} caption={current ? caption : dashboard.isError ? t('暂不可用') : t('等待统计')} icon={<Icon size={19} />} tone={tone} href={href} />)}</section>
    <p className="dashboard-online-scope">{t("在线节点：未知 · 在线用户：未知 · 在线设备：未知。当前原生权威尚未提供这些观测指标。")}</p>
    <section className="card overview-chart-card" aria-label={t("经营趋势")}>
      <div className="reference-card-heading"><div><h2>{t("经营趋势")}</h2><p>{t("按 UTC 日期统计 · 金额与笔数 · 同时用于下方流量排行")}</p></div>
        <label className="dashboard-select">{t("时间范围")}<select aria-label={t("概览时间范围")} value={period} onChange={event => selectPeriod(event.target.value)}>{dashboardRanges.map(days => <option key={days} value={String(days)}>{t('近 {days} 天', { days })}</option>)}<option value="custom">{t("自定义日期")}</option></select></label>
      </div>
      {period === 'custom' ? <div className="dashboard-custom-range"><DateRangePicker value={dates} onApply={setDates} onClear={() => { setDates(dashboardDates(30)); setPeriod('30') }} /></div> : null}
      <div className="dashboard-chart-controls"><SegmentedControl label={t("统计类型")} value={kind} options={trendKinds.map(option => ({ ...option, label: t(option.label) }))} onChange={setKind} /><SegmentedControl label={t("统计指标")} value={measure} options={[{ value: 'amount', label: t('金额') }, { value: 'count', label: t('笔数') }]} onChange={setMeasure} />
        {currencies.length ? <label className="dashboard-select">{t("币种")}<select aria-label={t("统计币种")} value={activeCurrency} onChange={event => setCurrency(event.target.value)}>{currencies.map(value => <option key={value}>{value}</option>)}</select></label> : null}
      </div>
      {!dashboard.data && !dashboard.isError ? <ChartSkeleton label={t("正在加载原生经营趋势")} /> : null}
      {current ? <><LineChart title={t(trendKinds.find(value => value.value === kind)?.label ?? '经营趋势')} labels={trend.labels} series={trend.series} yAxisWidth={100} formatValue={trend.formatValue} emptyText={t("此日期范围暂无记录")} />
        <p className="overview-chart-scope">{t('日期范围：{start} 至 {end}（UTC）', { start: current.start_date, end: current.end_date })} · {t('订单收入按支付时间汇总原生购买订单冻结金额，包含钱包部分，未扣退款；充值不重复计入。佣金入账与已批准转入钱包分别统计，币种分别查看。')}</p></> : null}
    </section>
    <div className="overview-data-grid">{(['nodes', 'users'] as const).map(type => <section className="card overview-data-card" key={type} aria-label={type === 'nodes' ? t('节点流量排行') : t('用户流量排行')}>
      <div className="reference-card-heading"><div><h2>{type === 'nodes' ? t('节点流量排行') : t('用户流量排行')}</h2><p>{t("所选日期 · 已结算原始上传 + 下载 · 前 10 名")}</p></div></div>
      {current ? <DataTable rows={current.rankings[type]} rowKey={row => row.id} label={type === 'nodes' ? t('节点流量排行榜') : t('用户流量排行榜')} empty={t("此日期范围暂无已结算流量")} columns={[
        { key: 'identity', header: type === 'nodes' ? t('节点') : t('用户'), render: row => <Link className="table-link" to={type === 'nodes' ? '/server/node/' + row.id : '/user/' + row.id}>{row.name ?? '#' + row.id}</Link> },
        { key: 'traffic', header: t('流量'), render: row => <span title={row.total_bytes + t(' 字节')}>{gigabytes(BigInt(row.total_bytes))}<small className="dashboard-byte-value">{t('{value} 字节', { value: count(row.total_bytes) })}</small></span> },
      ]} /> : <p className="reference-secondary">{dashboard.isError ? t('排行暂不可用') : t('正在加载排行…')}</p>}
    </section>)}</div>
    <section className="card overview-data-card" aria-label={t("队列监控概况")}><div className="reference-card-heading"><div><h2>{t("队列监控")}</h2><p>{t("独立实时观测，不受日期范围影响")}</p></div><Link to="/system/queue">{t("查看队列")}<ArrowUpRight size={15} /></Link></div>
      <ManagementFeedback error={queue.error} loading={queue.isFetching} onRetry={() => void queue.refetch()} />
      {queueCurrent ? <div className="dashboard-queue-grid">{[[t('运行状态'), t(queueStatuses[queueCurrent.status])], [t('待处理任务'), queueCurrent.pending_jobs], [t('每分钟任务'), queueCurrent.jobs_per_minute], [t('近七日失败'), queueCurrent.failed_last_7_days]].map(([label, value]) => <div key={label}><span>{label}</span><strong>{value ?? t('未知')}</strong></div>)}</div> : null}
      {queueCurrent?.message ? <p className="overview-chart-scope">{queueCurrent.message}</p> : null}
      {queueCurrent ? <QueueFailures compact /> : null}
    </section>
  </div>
}
