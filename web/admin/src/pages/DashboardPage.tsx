import { useQuery } from '@tanstack/react-query'
import { BarChart3, MessageSquare, Server, Users, Wallet, Wifi } from 'lucide-react'
import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { getDashboardStats, getOrderChart, getTrafficRank } from '../api/statistics'

export function DashboardPage() {
  const [range, setRange] = useState(30)
  const stats = useQuery({ queryKey:['dashboardStats'], queryFn:getDashboardStats, refetchInterval:60_000 })
  const window = useMemo(() => {
    const end = new Date()
    const start = new Date()
    start.setDate(end.getDate()-range)
    const fmt=(d:Date)=>`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`
    return { start_date:fmt(start), end_date:fmt(end) }
  },[range])
  const chart = useQuery({ queryKey:['orderChart',window], queryFn:()=>getOrderChart(window) })
  const now=Math.floor(Date.now()/1000)
  const userRank=useQuery({ queryKey:['trafficRank','user',range], queryFn:()=>getTrafficRank('user',now-range*86400,now) })
  const nodeRank=useQuery({ queryKey:['trafficRank','node',range], queryFn:()=>getTrafficRank('node',now-range*86400,now) })
  const s=stats.data||{}
  const points=(chart.data?.list||[]).map(row=>({date:String(row.date||''),value:Number(row.paid_total||0)/100}))

  return <div className="admin-dashboard">
    <div className="dashboard-range-row">
      <label htmlFor='dashboard-range'>{'\u7edf\u8ba1\u5468\u671f'}</label>
      <select id='dashboard-range' value={range} onChange={event=>setRange(Number(event.target.value))}>
        <option value={7}>7 天</option>
        <option value={30}>30 天</option>
        <option value={90}>90 天</option>
      </select>
    </div>

    <div className="dashboard-stats">
      <DashCard icon={<Wallet size={18}/>} label="今日收入" value={money(s.todayIncome)} sub={growth(s.dayIncomeGrowth)}/>
      <DashCard icon={<Wallet size={18}/>} label="本月收入" value={money(s.currentMonthIncome)} sub={growth(s.monthIncomeGrowth)}/>
      <DashCard icon={<Users size={18}/>} label="总用户" value={String(s.totalUsers||0)} sub={'活跃 '+String(s.activeUsers||0)}/>
      <DashCard icon={<Users size={18}/>} label="本月新用户" value={String(s.currentMonthNewUsers||0)} sub={growth(s.userGrowth)}/>
      <DashCard icon={<Wifi size={18}/>} label="在线用户" value={String(s.onlineUsers||0)} sub={'设备 '+String(s.onlineDevices||0)}/>
      <DashCard icon={<Server size={18}/>} label="在线节点" value={String(s.onlineNodes||0)} sub="实时快照"/>
      <Link className="dash-link" to="/user/ticket"><DashCard icon={<MessageSquare size={18}/>} label="待处理工单" value={String(s.ticketPendingTotal||0)} sub="进入工单管理"/></Link>
      <Link className="dash-link" to="/finance/order"><DashCard icon={<BarChart3 size={18}/>} label="待确认佣金" value={String(s.commissionPendingTotal||0)} sub="进入订单管理"/></Link>
    </div>

    <section className="admin-dashboard-card dashboard-chart-card">
      <div className="admin-dashboard-card-head">
        <div>
          <h2>订单收入趋势</h2>
          <p>按所选周期查看已支付订单金额。</p>
        </div>
        <strong>{money(chart.data?.summary?.paid_total)}</strong>
      </div>
      <div className="dash-chart">
        <IncomeChart points={points}/>
      </div>
    </section>

    <div className="dashboard-rank-grid">
      <Rank title="用户流量排行" rows={userRank.data||[]}/>
      <Rank title="节点流量排行" rows={nodeRank.data||[]}/>
    </div>

    <div className="dashboard-summary-grid">
      <section className="admin-dashboard-card">
        <div className="admin-dashboard-card-head"><div><h2>周期汇总</h2><p>订单与佣金统计。</p></div></div>
        <dl className="dashboard-summary-list">
          <div><dt>已支付订单</dt><dd>{String(chart.data?.summary?.paid_count||0)}</dd></div>
          <div><dt>收入</dt><dd>{money(chart.data?.summary?.paid_total)}</dd></div>
          <div><dt>佣金笔数</dt><dd>{String(chart.data?.summary?.commission_count||0)}</dd></div>
          <div><dt>佣金</dt><dd>{money(chart.data?.summary?.commission_total)}</dd></div>
        </dl>
      </section>
      <section className="admin-dashboard-card">
        <div className="admin-dashboard-card-head"><div><h2>流量与佣金</h2><p>当前周期运营概览。</p></div></div>
        <dl className="dashboard-summary-list">
          <div><dt>今日流量</dt><dd>{bytes(s.todayTraffic?.total)}</dd></div>
          <div><dt>月累计流量</dt><dd>{bytes(s.monthTraffic?.total)}</dd></div>
          <div><dt>本月佣金支出</dt><dd>{money(s.currentMonthCommissionPayout)}</dd></div>
          <div><dt>佣金增长</dt><dd>{growth(s.commissionGrowth)}</dd></div>
        </dl>
      </section>
    </div>
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

function Rank({title,rows}:{title:string;rows:Array<Record<string,unknown>>}){
  return <section className="admin-dashboard-card rank-card">
    <div className="admin-dashboard-card-head"><div><h2>{title}</h2></div></div>
    <div className="rank-list">
      <div className="rank-list-head"><span>#</span><span>名称</span><span>流量</span></div>
      {rows.slice(0,10).map((r,i)=><div key={i}><span>{i+1}</span><span>{String(r.name??r.email??r.server_name??'-')}</span><strong>{bytes(Number(r.value??r.total??r.traffic??0))}</strong></div>)}
      {!rows.length?<div className="rank-empty">暂无数据</div>:null}
    </div>
  </section>
}
function money(v:unknown){const n=Number(v||0);return '¥ '+(n/100).toFixed(2)}
function growth(v:unknown){const n=Number(v||0);return (n>=0?'+':'')+n.toFixed(1)+'%'}
function bytes(v:unknown){const n=Number(v||0);if(!n)return '0 B';const gb=n/1073741824;return gb>=1024?(gb/1024).toFixed(2)+' TB':gb.toFixed(gb>=10?1:2)+' GB'}
