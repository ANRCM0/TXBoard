import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, BarChart3, Bot, MessageSquare, Server, Users, Wallet, Wifi } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { getDashboardStats, getOrderChart, getTrafficRank } from '../api/statistics'
import { getAgentActions, getAgentFleetHealth } from '../api/agent'
import { QueryFeedback } from '../components/ui/QueryFeedback'
import { DashboardPeriodPicker } from '../components/dashboard/DashboardPeriodPicker'
import { QueueDashboardSections } from '../components/dashboard/QueueDashboardSections'
import { getPeriodDates, getPeriodTimestamps, readDashboardPeriod, saveDashboardPeriod, type DashboardPeriod } from '../lib/dashboardPeriod'

export function DashboardPage() {
  const [incomePeriod, setIncomePeriod] = useState<DashboardPeriod>(() => readDashboardPeriod('income'))
  const [summaryPeriod, setSummaryPeriod] = useState<DashboardPeriod>(() => readDashboardPeriod('order-summary'))
  const [userPeriod, setUserPeriod] = useState<DashboardPeriod>(() => readDashboardPeriod('user-rank'))
  const [nodePeriod, setNodePeriod] = useState<DashboardPeriod>(() => readDashboardPeriod('node-rank'))

  useEffect(() => saveDashboardPeriod('income', incomePeriod), [incomePeriod])
  useEffect(() => saveDashboardPeriod('order-summary', summaryPeriod), [summaryPeriod])
  useEffect(() => saveDashboardPeriod('user-rank', userPeriod), [userPeriod])
  useEffect(() => saveDashboardPeriod('node-rank', nodePeriod), [nodePeriod])

  const incomeDates = useMemo(() => getPeriodDates(incomePeriod), [incomePeriod])
  const summaryDates = useMemo(() => getPeriodDates(summaryPeriod), [summaryPeriod])
  const userDates = useMemo(() => getPeriodDates(userPeriod), [userPeriod])
  const nodeDates = useMemo(() => getPeriodDates(nodePeriod), [nodePeriod])
  const stats = useQuery({ queryKey: ['dashboardStats'], queryFn: getDashboardStats, refetchInterval: 60_000 })
  const chart = useQuery({
    queryKey: ['orderChart', incomeDates.start_date, incomeDates.end_date],
    queryFn: () => getOrderChart(incomeDates),
    refetchInterval: 300_000,
  })
  const orderSummary = useQuery({
    queryKey: ['orderChart', summaryDates.start_date, summaryDates.end_date],
    queryFn: () => getOrderChart(summaryDates),
    refetchInterval: 300_000,
  })
  const userRank = useQuery({
    queryKey: ['trafficRank', 'user', userDates.start_date, userDates.end_date],
    queryFn: () => {
      const { start_time, end_time } = getPeriodTimestamps(userPeriod)
      return getTrafficRank('user', start_time, end_time)
    },
    refetchInterval: 300_000,
  })
  const nodeRank = useQuery({
    queryKey: ['trafficRank', 'node', nodeDates.start_date, nodeDates.end_date],
    queryFn: () => {
      const { start_time, end_time } = getPeriodTimestamps(nodePeriod)
      return getTrafficRank('node', start_time, end_time)
    },
    refetchInterval: 300_000,
  })
  const fleet=useQuery({ queryKey:['agentFleetHealth'], queryFn:getAgentFleetHealth, refetchInterval:30_000, retry:1 })
  const agentActions=useQuery({ queryKey:['agentActions','pending'], queryFn:()=>getAgentActions('pending'), refetchInterval:15_000, retry:1 })
  const s=stats.data||{}
  const pendingAgentActions=(agentActions.data||[]).filter(item=>item.status==='pending')
  const criticalNodes=Number(fleet.data?.summary.critical_nodes||0)
  const degradedNodes=Number(fleet.data?.summary.degraded_nodes||0)
  const points=(chart.data?.list||[]).map(row=>({date:String(row.date||''),value:Number(row.paid_total||0)/100}))

  return <div className="admin-dashboard">
    <QueryFeedback loading={stats.isFetching && !stats.data} error={stats.isError && !stats.data} onRetry={() => stats.refetch()} />

    <section className="dashboard-ops-section">
      <div className="dashboard-section-heading">
        <div>
          <h2>需要处理</h2>
          <p>优先展示需要管理员介入的工单、佣金与运维异常。</p>
        </div>
      </div>
      <div className="dashboard-stats dashboard-action-stats">
        <Link className="dash-link" to="/user/ticket"><DashCard icon={<MessageSquare size={18}/>} label="待处理工单" value={count(s.ticketPendingTotal)} sub="进入工单管理"/></Link>
        <Link className="dash-link" to="/finance/order"><DashCard icon={<BarChart3 size={18}/>} label="待确认佣金" value={count(s.commissionPendingTotal)} sub="进入订单管理"/></Link>
        <Link className="dash-link" to="/system/agent-ops"><DashCard icon={<AlertTriangle size={18}/>} label="异常节点" value={fleet.isLoading || fleet.isError ? '—' : String(criticalNodes + degradedNodes)} sub={fleet.isError ? 'Fleet Health 加载失败' : fleet.isLoading ? '正在读取 Fleet Health' : `Critical ${criticalNodes} · Degraded ${degradedNodes}`}/></Link>
        <Link className="dash-link" to="/system/agent-ops"><DashCard icon={<Bot size={18}/>} label="待审批运维" value={agentActions.isLoading || agentActions.isError ? '—' : String(pendingAgentActions.length)} sub={agentActions.isError ? '审批队列加载失败' : agentActions.isLoading ? '正在读取审批队列' : '进入 Agent 运维'}/></Link>
      </div>
    </section>

    <div className="dashboard-stats">
      <DashCard icon={<Wallet size={18}/>} label="今日收入" value={money(s.todayIncome)} sub={growth(s.dayIncomeGrowth)}/>
      <DashCard icon={<Wallet size={18}/>} label="本月收入" value={money(s.currentMonthIncome)} sub={growth(s.monthIncomeGrowth)}/>
      <DashCard icon={<Users size={18}/>} label="总用户" value={count(s.totalUsers)} sub={'活跃 '+count(s.activeUsers)}/>
      <DashCard icon={<Users size={18}/>} label="本月新用户" value={count(s.currentMonthNewUsers)} sub={growth(s.userGrowth)}/>
      <DashCard icon={<Wifi size={18}/>} label="在线用户" value={count(s.onlineUsers)} sub={'设备 '+count(s.onlineDevices)}/>
      <DashCard icon={<Server size={18}/>} label="在线节点" value={count(s.onlineNodes)} sub="实时快照"/>
    </div>

    <section className="admin-dashboard-card dashboard-chart-card">
      <div className="admin-dashboard-card-head">
        <div>
          <h2>订单收入趋势</h2>
          <p>按所选周期查看已支付订单金额。</p>
        </div>
        <div className="dashboard-card-actions">
          <strong>{money(chart.data?.summary?.paid_total)}</strong>
          <DashboardPeriodPicker label="订单收入趋势" value={incomePeriod} onChange={setIncomePeriod}/>
        </div>
      </div>
      <div className="dash-chart">
        {chart.isFetching && !chart.data ? <QueryFeedback loading /> : chart.isError ? <QueryFeedback error onRetry={() => chart.refetch()} /> : <IncomeChart points={points}/>} 
      </div>
    </section>

    <div className="dashboard-rank-grid">
      <Rank title="用户流量排行" period={userPeriod} onPeriodChange={setUserPeriod} rows={userRank.data||[]} loading={userRank.isFetching && !userRank.data} error={userRank.isError} onRetry={() => userRank.refetch()}/>
      <Rank title="节点流量排行" period={nodePeriod} onPeriodChange={setNodePeriod} rows={nodeRank.data||[]} loading={nodeRank.isFetching && !nodeRank.data} error={nodeRank.isError} onRetry={() => nodeRank.refetch()}/>
    </div>

    <div className="dashboard-summary-grid">
      <section className="admin-dashboard-card dashboard-summary-card">
        <div className="admin-dashboard-card-head">
          <div><h2>周期汇总</h2><p>订单与佣金统计。</p></div>
          <DashboardPeriodPicker label="周期汇总" value={summaryPeriod} onChange={setSummaryPeriod}/>
        </div>
        <QueryFeedback loading={orderSummary.isFetching && !orderSummary.data} error={orderSummary.isError} onRetry={() => orderSummary.refetch()}/>
        <dl className="dashboard-summary-list">
          <div><dt>已支付订单</dt><dd>{count(orderSummary.data?.summary?.paid_count)}</dd></div>
          <div><dt>收入</dt><dd>{money(orderSummary.data?.summary?.paid_total)}</dd></div>
          <div><dt>佣金笔数</dt><dd>{count(orderSummary.data?.summary?.commission_count)}</dd></div>
          <div><dt>佣金</dt><dd>{money(orderSummary.data?.summary?.commission_total)}</dd></div>
        </dl>
      </section>
      <section className="admin-dashboard-card">
        <div className="admin-dashboard-card-head"><div><h2>流量与佣金</h2><p>今日及当月实时概览。</p></div></div>
        <dl className="dashboard-summary-list">
          <div><dt>今日流量</dt><dd>{bytes(s.todayTraffic?.total)}</dd></div>
          <div><dt>月累计流量</dt><dd>{bytes(s.monthTraffic?.total)}</dd></div>
          <div><dt>本月佣金支出</dt><dd>{money(s.currentMonthCommissionPayout)}</dd></div>
          <div><dt>佣金增长</dt><dd>{growth(s.commissionGrowth)}</dd></div>
        </dl>
      </section>
    </div>
    <QueueDashboardSections />
  </div>
}

function IncomeChart({points}:{points:Array<{date:string;value:number}>}){
  const chart=useMemo(()=>{
    if(!points.length)return null
    const width=1000
    const height=260
    const inset=24
    const max=Math.max(1,...points.map(point=>point.value))
    const plotWidth=width-inset*2
    const plotHeight=height-inset*2
    const coordinates=points.map((point,index)=>({
      ...point,
      x:inset+(points.length===1?.5:index/(points.length-1))*plotWidth,
      y:height-inset-(point.value/max)*plotHeight,
    }))
    const line=coordinates.map((point,index)=>(index?'L':'M')+' '+point.x.toFixed(1)+' '+point.y.toFixed(1)).join(' ')
    const area='M '+coordinates[0].x.toFixed(1)+' '+(height-inset)+' '+line+' L '+coordinates.at(-1)!.x.toFixed(1)+' '+(height-inset)+' Z'
    return {width,height,inset,coordinates,line,area,max}
  },[points])

  if(!chart)return <div className="dashboard-chart-empty">暂无订单收入数据</div>

  return <svg className="income-chart" viewBox={'0 0 '+chart.width+' '+chart.height} role="img" aria-label="订单收入趋势图">
    {[0,.25,.5,.75,1].map(step=>{
      const y=chart.inset+step*(chart.height-chart.inset*2)
      return <line key={step} x1={chart.inset} x2={chart.width-chart.inset} y1={y} y2={y} className="income-chart-grid"/>
    })}
    <path d={chart.area} className="income-chart-area"/>
    <path d={chart.line} className="income-chart-line"/>
    {chart.coordinates.map(point=><circle key={point.date} cx={point.x} cy={point.y} r="3" className="income-chart-dot">
      <title>{point.date}：¥ {point.value.toFixed(2)}</title>
    </circle>)}
    <text x={chart.inset} y={chart.height-2} className="income-chart-label">{points[0].date}</text>
    <text x={chart.width-chart.inset} y={chart.height-2} textAnchor="end" className="income-chart-label">{points.at(-1)?.date}</text>
  </svg>
}

function DashCard({icon,label,value,sub}:{icon:React.ReactNode;label:string;value:string;sub?:string}){
  return <div className="admin-stat-card">
    <div className="admin-stat-card-head"><span>{label}</span><span className="admin-stat-icon">{icon}</span></div>
    <strong>{value}</strong>
    {sub?<small>{sub}</small>:null}
  </div>
}

function Rank({title,period,onPeriodChange,rows,loading,error,onRetry}:{title:string;period:DashboardPeriod;onPeriodChange:(period:DashboardPeriod)=>void;rows:Array<Record<string,unknown>>;loading?:boolean;error?:boolean;onRetry?:()=>void}){
  return <section className="admin-dashboard-card rank-card">
    <div className="admin-dashboard-card-head">
      <div><h2>{title}</h2></div>
      <DashboardPeriodPicker label={title} value={period} onChange={onPeriodChange}/>
    </div>
    <QueryFeedback loading={loading} error={error} onRetry={onRetry}/>
    <div className="rank-list">
      <div className="rank-list-head"><span>#</span><span>名称</span><span>流量</span></div>
      {rows.slice(0,10).map((r,i)=><div key={i}><span>{i+1}</span><span>{String(r.name??r.email??r.server_name??'-')}</span><strong>{bytes(Number(r.value??r.total??r.traffic??0))}</strong></div>)}
      {!rows.length&&!error?<div className="rank-empty">{loading?'加载中…':'暂无数据'}</div>:null}
    </div>
  </section>
}
function count(v:unknown){return v===undefined||v===null?'—':String(v)}
function money(v:unknown){if(v===undefined||v===null)return '—';const n=Number(v);return Number.isFinite(n)?'¥ '+(n/100).toFixed(2):'—'}
function growth(v:unknown){if(v===undefined||v===null)return '—';const n=Number(v);return Number.isFinite(n)?(n>=0?'+':'')+n.toFixed(1)+'%':'—'}
function bytes(v:unknown){if(v===undefined||v===null)return '—';const n=Number(v);if(!Number.isFinite(n))return '—';if(!n)return '0 B';const gb=n/1073741824;return gb>=1024?(gb/1024).toFixed(2)+' TB':gb.toFixed(gb>=10?1:2)+' GB'}
