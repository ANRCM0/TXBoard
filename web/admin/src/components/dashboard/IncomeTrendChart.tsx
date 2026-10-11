import { useMemo } from 'react'
import { ArrowRight, BarChart3 } from 'lucide-react'
import { Link } from 'react-router-dom'
import { EmptyState } from '../ui/EmptyState'
import { hasIncomeTrend } from '../../lib/dashboardAttention'
import { incomeAreaPath, type IncomePoint } from '../../lib/dashboardVisuals'

function axisMoney(value: number): string {
  return '¥' + new Intl.NumberFormat('zh-CN', {
    maximumFractionDigits: value > 100 ? 0 : 2,
    notation: value >= 10_000 ? 'compact' : 'standard',
  }).format(value)
}

export function IncomeTrendChart({ points }: { points: readonly IncomePoint[] }) {
  const graph = useMemo(() => {
    if (!points.length) return null
    const width = 1000
    const height = 200
    const inset = 8
    const baseline = height - inset
    const max = Math.max(1, ...points.map(point => point.value))
    const coords = points.map((point, index) => ({
      ...point,
      x: inset + (points.length === 1 ? 0.5 : index / (points.length-1))*(width - inset*2),
      y: baseline - (point.value/max)*(height - inset*2),
    }))
    const line = coords.map((p,index) => (index ? 'L ' : 'M ') + p.x.toFixed(2) + ' ' + p.y.toFixed(2)).join(' ')
    return {width,height,inset,baseline,max,coords,line,area:incomeAreaPath(coords,baseline)}
  }, [points])

  if (!graph || !hasIncomeTrend(points)) return (
    <EmptyState className="dashboard-chart-empty" icon={<BarChart3 size={22}/>}
      title="所选时间范围暂无收入趋势"
      description="该时段没有可展示的已支付订单金额。切换时间范围，或前往订单管理查看订单状态。"
      action={<Link className="button" to="/finance/order">查看订单 <ArrowRight size={15} aria-hidden="true"/></Link>}/>
  )

  return <figure className="dashboard-v3-trend">
    <div className="dashboard-v3-trend-y" aria-hidden="true">
      <span>{axisMoney(graph.max)}</span>
      <span>{axisMoney(graph.max / 2)}</span>
      <span>¥0</span>
    </div>
    <div className="dashboard-v3-trend-plot">
      <svg className="dashboard-v3-trend-svg" viewBox={'0 0 ' + graph.width + ' ' + graph.height}
        preserveAspectRatio="none" role="img"
        aria-label={'订单收入趋势，从' + points[0].date + '到' + points[points.length - 1].date + '，最高' + axisMoney(graph.max)}>
        {[0,.5,1].map(step => {
          const y = graph.inset + step*(graph.height-graph.inset*2)
          return <line key={step} x1={0} x2={graph.width} y1={y} y2={y} className="income-chart-grid"/>
        })}
        <path d={graph.area} className="income-chart-area"/>
        <path d={graph.line} className="income-chart-line" vectorEffect="non-scaling-stroke"/>
        {graph.coords.map((point,index) => <circle key={point.date + index} cx={point.x} cy={point.y}
          r={4} className="income-chart-dot" vectorEffect="non-scaling-stroke">
          <title>{point.date}：{axisMoney(point.value)}</title>
        </circle>)}
      </svg>
      <div className="dashboard-v3-trend-dates" aria-hidden="true">
        <time>{points[0].date}</time><time>{points[points.length-1].date}</time>
      </div>
    </div>
  </figure>
}
