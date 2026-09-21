import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import type { PluginRendererProps } from '../registry'
import { getAuditAnalytics, getAuditNodes } from './api'

type Range = '1h' | '24h' | '7d' | '30d'

export function AccessAuditAnalytics(_props: PluginRendererProps) {
  const [range, setRange] = useState<Range>('24h')
  const [nodeId, setNodeId] = useState('')
  const nodes = useQuery({ queryKey: ['accessAudit', 'nodes'], queryFn: getAuditNodes })
  const query = useQuery({
    queryKey: ['accessAudit', 'analytics', range, nodeId],
    queryFn: () => getAuditAnalytics(range, nodeId ? Number(nodeId) : undefined),
  })
  const data = query.data

  return <div className="plugin-native-page">
    <div className="plugin-native-filter">
      <select value={range} onChange={e => setRange(e.target.value as Range)}>
        <option value="1h">最近 1 小时</option><option value="24h">最近 24 小时</option>
        <option value="7d">最近 7 天</option><option value="30d">最近 30 天</option>
      </select>
      <select value={nodeId} onChange={e => setNodeId(e.target.value)}>
        <option value="">全部节点</option>
        {(nodes.data || []).map(node => <option key={node.node_id} value={node.node_id}>{node.node_name}</option>)}
      </select>
      {data?.coverage && <span className="muted">趋势来源：{data.coverage.trend_source} · 明细保留 {data.coverage.detail_retention_days} 天</span>}
    </div>

    <div className="plugin-metrics">
      <Metric label="访问事件" value={data?.summary.events}/>
      <Metric label="规则命中" value={data?.summary.matched}/>
      <Metric label="命中率" value={data?.summary.match_rate == null ? '—' : `${data.summary.match_rate}%`}/>
      <Metric label="活跃用户" value={data?.summary.active_users}/>
      <Metric label="封禁" value={data?.summary.bans == null ? '节点筛选下不可用' : data.summary.bans}/>
    </div>

    <section className="card">
      <div className="plugin-panel-head"><div><h3>访问 / 命中趋势</h3><p>短周期使用原始数据，7d/30d 优先使用小时聚合。</p></div></div>
      <div className="plugin-chart">
        <ResponsiveContainer width="100%" height={300}>
          <LineChart data={data?.trend || []}>
            <CartesianGrid strokeDasharray="3 3"/>
            <XAxis dataKey="time" tickFormatter={formatAxisTime} minTickGap={28}/>
            <YAxis allowDecimals={false}/>
            <Tooltip labelFormatter={value => formatTime(Number(value))}/>
            <Line type="monotone" dataKey="events" name="访问" stroke="var(--plugin-chart-primary, #2563eb)" dot={false}/>
            <Line type="monotone" dataKey="matched" name="命中" stroke="var(--plugin-chart-danger, #dc2626)" dot={false}/>
          </LineChart>
        </ResponsiveContainer>
      </div>
    </section>

    <div className="two-col">
      <Ranking title="节点排行" columns={['节点','访问','命中','命中率']} rows={(data?.nodes || []).map(x => [x.node_name,x.events,x.matched,x.match_rate == null ? '-' : `${x.match_rate}%`])}/>
      <Ranking title="规则排行" columns={['规则','命中','用户','封禁']} rows={(data?.rules || []).map(x => [x.rule_name,x.hits,x.users,x.bans ?? '-'])}/>
      <Ranking title="活跃用户" columns={['用户','访问','命中']} rows={(data?.top_users || []).map(x => [x.user_email,x.events,x.matched])}/>
      <Ranking title="热门目标" columns={['目标','访问','命中']} rows={(data?.top_targets || []).map(x => [x.target,x.events,x.matched])}/>
    </div>
  </div>
}

function Metric({ label, value }: { label: string; value?: string | number }) {
  return <div className="card plugin-metric"><span>{label}</span><strong>{value ?? '—'}</strong></div>
}

function Ranking({ title, columns, rows }: { title: string; columns: string[]; rows: Array<Array<string | number>> }) {
  return <section className="card">
    <div className="plugin-panel-head"><div><h3>{title}</h3></div></div>
    <div className="table-wrap"><table className="data-table">
      <thead><tr>{columns.map(col => <th key={col}>{col}</th>)}</tr></thead>
      <tbody>
        {rows.map((row, index) => <tr key={index}>{row.map((cell, i) => <td key={i}>{cell}</td>)}</tr>)}
        {!rows.length && <tr><td colSpan={columns.length} className="empty-cell">暂无数据</td></tr>}
      </tbody>
    </table></div>
  </section>
}

function formatAxisTime(value: number) {
  const date = new Date(value * 1000)
  return date.toLocaleString([], { month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' })
}
function formatTime(value: number) {
  return value ? new Date(value * 1000).toLocaleString() : '-'
}
