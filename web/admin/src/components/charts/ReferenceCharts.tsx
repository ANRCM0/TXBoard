import { t, useLocale, getLocale } from '../../lib/i18n'
import { useEffect, useId, useRef, useState } from 'react'
import type { CSSProperties, KeyboardEvent } from 'react'
import './reference-charts.css'

export type ChartSeries = {
  id: string
  label: string
  /** Nonnegative finite samples; null/invalid/missing values create a gap. */
  values: readonly (number | null)[]
  color?: string
  /** Exact, caller-formatted summary, e.g. a BigInt total. */
  summary?: string
  /** Exact labels for geometry normalized from decimal strings, e.g. raw byte counters. */
  displayValues?: readonly (string | null)[]
}
export type LineChartProps = {
  title: string
  labels: readonly string[]
  series: readonly ChartSeries[]
  formatValue?: (value: number) => string
  height?: number
  emptyText?: string
  xAxisLabel?: string
  yAxisWidth?: number
}
export type DonutDatum = { id: string; label: string; value: number; color?: string; displayValue?: string }
export type DonutChartProps = {
  title: string
  data: readonly DonutDatum[]
  centerLabel?: string
  formatValue?: (value: number) => string
  emptyText?: string
}
export type BarValue = { id: string; label: string; percent: number; displayValue: string; color?: string }
export type HorizontalBarDatum = { id: string; label: string; values: readonly BarValue[]; missingLabels?: readonly string[] }
export type HorizontalBarChartProps = { title: string; data: readonly HorizontalBarDatum[]; emptyText?: string }

/**
 * <LineChart title="请求趋势" labels={['00:00', '01:00']}
 *   series={[{ id: 'requests', label: '请求', values: [0, 12], summary: '12 次' }]} />
 * <DonutChart title="订单状态" data={[{ id: 'paid', label: '已完成', value: 12 }]} />
 * <HorizontalBarChart title="节点流量" data={[{ id: 'node-1', label: '香港 01', values: [
 *   { id: 'upload', label: '上传', percent: 40, displayValue: '9007199254740993 字节' },
 *   { id: 'download', label: '下载', percent: 60, displayValue: '13510798882111490 字节' },
 * ] }]} />
 * For bytes, calculate percent with BigInt before rendering:
 * Number(bytes * 10000n / maximumBytes) / 100 (guard maximumBytes === 0n).
 * Only ratios become Number; displayValue retains the original exact amount.
 */
const colors = [
  'var(--primary)',
  'var(--data-cyan)',
  'var(--data-green)',
  'var(--data-orange)',
  'var(--data-purple)',
  'var(--data-red)',
]
const colorAt = (index: number, color?: string) => color || colors[index % colors.length]
const sample = (value: number | null | undefined): value is number => typeof value === 'number' && Number.isFinite(value) && value >= 0
const formatNumber = (value: number) => new Intl.NumberFormat(getLocale(), { maximumFractionDigits: 2 }).format(value)
const ratio = (value: number) => Number.isFinite(value) ? Math.max(0, Math.min(100, value)) : 0
const toneStyle = (color: string): CSSProperties => ({ '--reference-chart-color': color } as CSSProperties)

function useChartWidth() {
  const ref = useRef<HTMLElement>(null)
  const [width, setWidth] = useState(640)
  useEffect(() => {
    const node = ref.current
    if (!node) return
    const resize = () => { const next = node.getBoundingClientRect().width; if (next > 0) setWidth(Math.max(240, next)) }
    resize()
    if (typeof ResizeObserver === 'undefined') return
    const observer = new ResizeObserver(resize)
    observer.observe(node)
    return () => observer.disconnect()
  }, [])
  return { ref, width }
}

export function LineChart({ title, labels, series, formatValue = formatNumber, height = 240, emptyText = t("暂无趋势数据"), xAxisLabel = t("时间"), yAxisWidth = 48 }: LineChartProps) {
  useLocale()
  const id = useId().replace(/:/g, '')
  const { ref, width } = useChartWidth()
  const [pointer, setPointer] = useState<number | null>(null)
  const [focused, setFocused] = useState<number | null>(null)
  const [pinned, setPinned] = useState<string | null>(null)
  const active = focused ?? pointer
  const index = active === null || !labels.length ? null : Math.min(active, labels.length - 1)
  const pinnedId = series.some(item => item.id === pinned) ? pinned : null
  const plotHeight = Number.isFinite(height) ? Math.max(170, Math.min(600, height)) : 240
  const left = Number.isFinite(yAxisWidth) ? Math.max(48, Math.min(120, yAxisWidth)) : 48
  const right = width - 16, top = 16, bottom = plotHeight - 32
  let maximum = 0, hasSamples = false
  for (const item of series) for (let i = 0; i < labels.length; i++) {
    const value = item.values[i]
    if (sample(value)) { maximum = Math.max(maximum, value); hasSamples = true }
  }
  const scale = maximum || 1
  const x = (i: number) => labels.length <= 1 ? (left + right) / 2 : left + (right - left) * i / (labels.length - 1)
  const y = (value: number) => bottom - value / scale * (bottom - top)
  const description = t("{v0} 个数据点，{v1} 条曲线。横轴为{v2}。聚焦图表后用左右方向键选择数据点，Home 和 End 跳到首尾。点击图例突出显示曲线。", { v0: labels.length, v1: series.length, v2: xAxisLabel })
  const valueLabel = (item: ChartSeries, point: number) => sample(item.values[point])
    ? item.displayValues?.[point] ?? formatValue(item.values[point]!) : t("未上报")
  function keyboard(event: KeyboardEvent<SVGSVGElement>) {
    if (!labels.length || !['ArrowLeft', 'ArrowRight', 'Home', 'End', 'Escape'].includes(event.key)) return
    event.preventDefault()
    if (event.key === 'Escape') { setFocused(null); setPointer(null); return }
    const current = index ?? 0
    setFocused(event.key === 'Home' ? 0 : event.key === 'End' ? labels.length - 1 : Math.max(0, Math.min(labels.length - 1, current + (event.key === 'ArrowRight' ? 1 : -1))))
  }
  function pathSegments(values: readonly (number | null)[]) {
    const segments: { line: string; area: string }[] = []
    let points: { x: number; y: number }[] = []
    const flush = () => {
      if (!points.length) return
      const line = points.map((point, i) => `${i ? 'L' : 'M'}${point.x},${point.y}`).join(' ')
      segments.push({ line, area: `${line} L${points.at(-1)!.x},${bottom} L${points[0].x},${bottom} Z` })
      points = []
    }
    for (let i = 0; i < labels.length; i++) { const value = values[i]; if (sample(value)) points.push({ x: x(i), y: y(value) }); else flush() }
    flush()
    return segments
  }
  return <section className="reference-chart reference-line-chart" aria-label={title} ref={ref}>
    <div className="reference-chart-legend" aria-label={t('{title}图例', { title })}>{series.map((item, i) => <button key={item.id} type="button"
      style={toneStyle(colorAt(i, item.color))} aria-label={t('突出显示{label}曲线', { label: item.label })} aria-pressed={pinnedId === item.id}
      className={pinnedId && pinnedId !== item.id ? 'is-muted' : ''} onClick={() => setPinned(pinnedId === item.id ? null : item.id)}>
      <i aria-hidden="true" /><span>{item.label}{item.summary !== undefined ? <strong>{item.summary}</strong> : null}</span>
    </button>)}</div>
    {!hasSamples ? <div className="reference-chart-empty">{emptyText}</div> : <div className="reference-chart-plot">
      <svg viewBox={`0 0 ${width} ${plotHeight}`} width="100%" height={plotHeight} role="group" tabIndex={0}
        aria-label={t('{title}交互图表', { title })} aria-describedby={id + '-description'} onKeyDown={keyboard}
        onFocus={() => setFocused(current => current ?? 0)} onBlur={() => setFocused(null)}
        onPointerMove={event => {
          setFocused(null)
          const bounds = event.currentTarget.getBoundingClientRect()
          const local = (event.clientX - bounds.left) * width / bounds.width
          setPointer(labels.length <= 1 ? 0 : Math.max(0, Math.min(labels.length - 1, Math.round((local - left) / (right - left) * (labels.length - 1)))))
        }} onPointerLeave={() => setPointer(null)}>
        <title>{title}</title><desc id={id + '-description'}>{description}</desc>
        <defs>{series.map((item, i) => <linearGradient id={`${id}-area-${i}`} key={item.id} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor={colorAt(i, item.color)} stopOpacity=".20" /><stop offset="100%" stopColor={colorAt(i, item.color)} stopOpacity=".01" />
        </linearGradient>)}</defs>
        {[0, 1, 2, 3].map(tick => { const value = scale * tick / 3, position = y(value); return <g key={tick} aria-hidden="true">
          <line className="reference-chart-grid" x1={left} x2={right} y1={position} y2={position} />
          <text className="reference-chart-axis" x={left - 8} y={position + 3} textAnchor="end">{formatValue(value)}</text>
        </g> })}
        {labels.map((label, i) => i === 0 || i === labels.length - 1 || i % Math.max(1, Math.ceil(labels.length / Math.max(2, Math.floor(width / 120)))) === 0
          ? <text key={i} aria-hidden="true" className="reference-chart-axis" x={x(i)} y={bottom + 22} textAnchor={labels.length === 1 ? 'middle' : i === 0 ? 'start' : i === labels.length - 1 ? 'end' : 'middle'}>{label}</text> : null)}
        {series.map((item, i) => <g key={item.id} className="reference-chart-series" opacity={pinnedId && pinnedId !== item.id ? .18 : 1} aria-hidden="true">
          {pathSegments(item.values).map((part, partIndex) => <g key={partIndex}><path d={part.area} fill={`url(#${id}-area-${i})`} /><path d={part.line} fill="none" stroke={colorAt(i, item.color)} strokeWidth={pinnedId === item.id ? 3 : 2} strokeLinejoin="round" strokeLinecap="round" /></g>)}
          {labels.map((_, pointIndex) => { const value = item.values[pointIndex]; return sample(value) && (labels.length <= 3 || pointIndex === index || (!sample(item.values[pointIndex - 1]) && !sample(item.values[pointIndex + 1]))) ? <circle key={pointIndex} cx={x(pointIndex)} cy={y(value)} r={pointIndex === index ? 4 : 3} fill={colorAt(i, item.color)} stroke="var(--panel)" strokeWidth="1.5" /> : null })}
        </g>)}
        {index !== null ? <line aria-hidden="true" className="reference-chart-crosshair" x1={x(index)} x2={x(index)} y1={top} y2={bottom} /> : null}
      </svg>
      {index !== null ? <div className="reference-chart-tooltip" role="status" aria-live={focused === null ? 'off' : 'polite'}>
        <strong>{labels[index]}</strong>{series.map((item, i) => <span key={item.id} style={toneStyle(colorAt(i, item.color))}><i aria-hidden="true" />{item.label}<b>{valueLabel(item, index)}</b></span>)}
      </div> : null}
    </div>}
    <details className="reference-chart-data"><summary>{t('查看{title}数据', { title })}</summary><div><table><caption>{title}</caption><thead><tr><th scope="col">{xAxisLabel}</th>{series.map(item => <th key={item.id} scope="col">{item.label}</th>)}</tr></thead><tbody>{labels.map((label, i) => <tr key={i}><th scope="row">{label}</th>{series.map(item => <td key={item.id}>{valueLabel(item, i)}</td>)}</tr>)}</tbody></table></div></details>
  </section>
}

export function DonutChart({ title, data, centerLabel = t("合计"), formatValue = formatNumber, emptyText = t("暂无分布数据") }: DonutChartProps) {
  useLocale()
  const id = useId().replace(/:/g, '')
  const [hovered, setHovered] = useState<string | null>(null), [focused, setFocused] = useState<string | null>(null)
  const valid = data.map(item => ({ ...item, value: sample(item.value) ? item.value : 0 }))
  // Normalize before summing so finite large inputs never overflow the geometry.
  const maximum = valid.reduce((max, item) => Math.max(max, item.value), 0)
  const normalizedTotal = valid.reduce((sum, item) => sum + (maximum ? item.value / maximum : 0), 0)
  const total = valid.reduce((sum, item) => sum + item.value, 0)
  const active = valid.find(item => item.id === (focused ?? hovered))
  const circumference = 2 * Math.PI * 72
  let offset = 0
  return <section className="reference-chart reference-donut-chart" aria-label={title}>
    <div className="reference-donut-layout"><div className="reference-donut-graphic">
      <svg viewBox="0 0 220 220" role="group" aria-labelledby={id + '-title'}>
        <title id={id + '-title'}>{title}</title><circle cx="110" cy="110" r="72" className="reference-donut-track" fill="none" strokeWidth="24" />
        {valid.map((item, i) => {
          const fraction = normalizedTotal ? item.value / maximum / normalizedTotal : 0
          const start = offset; offset += fraction * circumference
          if (!fraction) return null
          return <circle key={item.id} role="img" aria-label={`${item.label}：${item.displayValue ?? formatValue(item.value)}，${formatNumber(fraction * 100)}%`}
            tabIndex={0} cx="110" cy="110" r="72" fill="none" stroke={colorAt(i, item.color)} strokeWidth={active?.id === item.id ? 29 : 24}
            strokeDasharray={`${fraction * circumference} ${circumference}`} strokeDashoffset={-start} transform="rotate(-90 110 110)"
            opacity={active && active.id !== item.id ? .3 : 1} className="reference-donut-segment"
            onPointerEnter={() => setHovered(item.id)} onPointerLeave={() => setHovered(null)} onFocus={() => setFocused(item.id)} onBlur={() => setFocused(null)} />
        })}
        <text x="110" y="104" textAnchor="middle" className="reference-donut-total">{active ? formatNumber(normalizedTotal ? active.value / maximum / normalizedTotal * 100 : 0) + '%' : Number.isFinite(total) ? formatValue(total) : '—'}</text>
        <text x="110" y="127" textAnchor="middle" className="reference-chart-axis">{active ? active.label : maximum ? centerLabel : t("暂无占比")}</text>
      </svg>
    </div><div className="reference-donut-legend" aria-label={t('{title}图例', { title })}>{valid.map((item, i) => <button type="button" key={item.id}
      style={toneStyle(colorAt(i, item.color))} className={active?.id === item.id ? 'is-active' : ''}
      onPointerEnter={() => setHovered(item.id)} onPointerLeave={() => setHovered(null)} onFocus={() => setFocused(item.id)} onBlur={() => setFocused(null)}
      aria-label={`${item.label}：${item.displayValue ?? formatValue(item.value)}，${formatNumber(normalizedTotal ? item.value / maximum / normalizedTotal * 100 : 0)}%`}>
      <i aria-hidden="true" /><span>{item.label}</span><strong>{item.displayValue ?? formatValue(item.value)}</strong>
    </button>)}</div></div>
    {active ? <p className="reference-donut-detail" role="status" aria-live={focused ? 'polite' : 'off'}>{active.label}：{active.displayValue ?? formatValue(active.value)} · {formatNumber(normalizedTotal ? active.value / maximum / normalizedTotal * 100 : 0)}%</p> : null}
    {!maximum ? <p className="reference-chart-empty-note">{emptyText}</p> : null}
    <details className="reference-chart-data"><summary>{t('查看{title}数据', { title })}</summary><div><table><caption>{title}</caption><thead><tr><th scope="col">{t("分类")}</th><th scope="col">{t("数值")}</th><th scope="col">{t("占比")}</th></tr></thead><tbody>{valid.map(item => <tr key={item.id}><th scope="row">{item.label}</th><td>{item.displayValue ?? formatValue(item.value)}</td><td>{formatNumber(normalizedTotal ? item.value / maximum / normalizedTotal * 100 : 0)}%</td></tr>)}</tbody></table></div></details>
  </section>
}

export function HorizontalBarChart({ title, data, emptyText = t("暂无节点流量数据") }: HorizontalBarChartProps) {
  useLocale()
  return <section className="reference-chart reference-bar-chart" aria-label={title}>
    {!data.length ? <div className="reference-chart-empty">{emptyText}</div> : <ol className="reference-bar-list">{data.map(row => <li key={row.id}>
      <strong className="reference-bar-label">{row.label}</strong><div className="reference-bar-values">{row.values.map((value, i) => <div key={value.id} className="reference-bar-entry" style={toneStyle(colorAt(i, value.color))}>
        <div className="reference-bar-caption"><span><i aria-hidden="true" />{value.label}</span><strong>{value.displayValue}</strong></div>
        <svg viewBox="0 0 100 6" preserveAspectRatio="none" aria-hidden="true"><rect width="100" height="6" rx="3" className="reference-bar-track" /><rect width={ratio(value.percent)} height="6" rx="3" className="reference-bar-fill" /></svg>
      </div>)}{row.missingLabels?.length ? <p className="reference-chart-missing">{row.missingLabels.join(' · ')}</p> : null}</div>
    </li>)}</ol>}
  </section>
}
