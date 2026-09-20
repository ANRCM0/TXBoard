import { useQuery } from '@tanstack/react-query'
import { CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { useMemo, useState } from 'react'
import { getMachineHistory } from '../../api/server'

type HistoryPoint = {
  time: string
  cpu: number | null
  memory: number | null
  disk: number | null
}

function numberOf(value: unknown): number | null {
  const n = Number(value)
  return Number.isFinite(n) ? n : null
}

function percent(used: unknown, total: unknown): number | null {
  const u = numberOf(used)
  const t = numberOf(total)
  if (u === null || t === null || t <= 0) return null
  return Math.min(100, Math.max(0, (u / t) * 100))
}

function timeOf(value: unknown, index: number) {
  if (typeof value === 'number') {
    const ms = value < 10_000_000_000 ? value * 1000 : value
    const d = new Date(ms)
    if (!Number.isNaN(d.getTime())) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  }
  if (typeof value === 'string' && value) {
    const d = new Date(value)
    if (!Number.isNaN(d.getTime())) return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    return value
  }
  return String(index + 1)
}

function normalize(raw: unknown): HistoryPoint[] {
  const source = Array.isArray(raw)
    ? raw
    : raw && typeof raw === 'object' && Array.isArray((raw as { data?: unknown[] }).data)
      ? (raw as { data: unknown[] }).data
      : []

  return source.map((item, index) => {
    const row = (item && typeof item === 'object' ? item : {}) as Record<string, unknown>
    return {
      time: timeOf(row.recorded_at, index),
      cpu: numberOf(row.cpu),
      memory: percent(row.mem_used, row.mem_total),
      disk: percent(row.disk_used, row.disk_total),
    }
  })
}

export function MachineHistoryChart({ machineId }: { machineId: number }) {
  const [hours, setHours] = useState(24)
  const query = useQuery({
    queryKey: ['machineHistory', machineId, hours],
    queryFn: () => getMachineHistory(machineId, 240, hours),
  })
  const data = useMemo(() => normalize(query.data), [query.data])

  return <div className="machine-history">
    <div className="history-toolbar">
      <div>
        <strong>负载历史</strong>
        <span className="muted">CPU / 内存 / 磁盘使用率</span>
      </div>
      <select value={hours} onChange={e => setHours(Number(e.target.value))}>
        <option value={1}>最近 1 小时</option>
        <option value={6}>最近 6 小时</option>
        <option value={12}>最近 12 小时</option>
        <option value={24}>最近 24 小时</option>
      </select>
    </div>

    {query.isLoading ? <div className="chart-state">加载历史数据…</div> :
      data.length ? <div className="history-chart">
        <ResponsiveContainer width="100%" height={320}>
          <LineChart data={data}>
            <CartesianGrid strokeDasharray="3 3" vertical={false}/>
            <XAxis dataKey="time" minTickGap={32} tick={{ fontSize: 11 }}/>
            <YAxis domain={[0, 100]} tick={{ fontSize: 11 }} unit="%"/>
            <Tooltip formatter={(value) => value == null ? '-' : `${Number(value).toFixed(1)}%`}/>
            <Legend/>
            <Line type="monotone" dataKey="cpu" name="CPU" stroke="#2563eb" dot={false} connectNulls/>
            <Line type="monotone" dataKey="memory" name="Memory" stroke="#16a34a" dot={false} connectNulls/>
            <Line type="monotone" dataKey="disk" name="Disk" stroke="#d97706" dot={false} connectNulls/>
          </LineChart>
        </ResponsiveContainer>
      </div> : <div className="chart-state">该时间范围暂无历史数据。</div>}
  </div>
}
