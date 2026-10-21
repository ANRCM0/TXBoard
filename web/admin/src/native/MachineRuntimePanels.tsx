import { t, useLocale, formatNumber } from '../lib/i18n'
import { useEffect, useLayoutEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery } from '@tanstack/react-query'
import type { CoreOutput } from '@txboard/core-sdk'
import { CoreAdminApiError } from '@txboard/core-sdk'
import { Activity, Eye, EyeOff, KeyRound, RefreshCw, Terminal } from 'lucide-react'
import { admin, workflowActions } from './session'
import { managementError, ManagementFeedback } from './Feedback'
import { formatTimestamp } from './Inventory'
import { CopyButton } from '../components/ui/CopyButton'
import { DetailSection } from '../components/ui/DetailSection'
import { LineChart } from '../components/charts/ReferenceCharts'
import './machine-runtime.css'

type Credentials = CoreOutput<'network.machines.credentials.get'>
type HistoryPoint = CoreOutput<'network.machines.history.get'>['points'][number]
type SecretState = { scope: object; data?: Credentials; error?: string }

export function MachineCredentialsPanel({ machineId, disabled, onAuthorizationFailure }: { machineId: string; disabled: boolean; onAuthorizationFailure?: (error: unknown) => void }) {
  useLocale()
  const workflow = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const generation = workflowActions.generation()
  const scope = useMemo(() => ({}), [machineId, generation])
  const active = useRef<object>()
  const request = useRef<AbortController>()
  const [state, setState] = useState<SecretState>()
  const [reading, setReading] = useState(false)
  const data = !disabled && state?.scope === scope ? state.data : undefined
  const error = state?.scope === scope ? state.error : undefined
  const locked = disabled || reading || workflow.busy || !!workflow.pending

  function hide() { request.current?.abort(); request.current = undefined; setState(undefined); setReading(false) }
  useLayoutEffect(() => {
    active.current = scope
    hide()
    return () => { active.current = undefined; request.current?.abort() }
  }, [scope])
  useEffect(() => { if (disabled) hide() }, [disabled])
  useEffect(() => {
    if (!data) return
    const timer = window.setTimeout(hide, 180000)
    const conceal = () => { if (document.visibilityState === 'hidden') hide() }
    document.addEventListener('visibilitychange', conceal)
    return () => { window.clearTimeout(timer); document.removeEventListener('visibilitychange', conceal) }
  }, [data])

  async function reveal() {
    const snapshot = workflowActions.snapshot()
    if (disabled || reading || snapshot.busy || snapshot.pending || active.current !== scope) return
    hide()
    const controller = new AbortController()
    request.current = controller
    const current = () => active.current === scope && request.current === controller && !controller.signal.aborted
    setReading(true)
    try {
      const result = await admin.read('network.machines.credentials.get', { machine_id: machineId }, controller.signal)
      if (current()) setState({ scope, data: result.data })
    } catch (failure) {
      if (current()) {
        setState({ scope, error: managementError(failure, false) })
        if (failure instanceof CoreAdminApiError && [401, 403].includes(failure.status)) onAuthorizationFailure?.(failure)
      }
    } finally { if (current()) { request.current = undefined; setReading(false) } }
  }
  async function rotate() {
    if (locked || !window.confirm(t("确定重置服务器 Token？旧 Token 将立即失效，需要使用新认证信息重新配置服务器。"))) return
    hide()
    try {
      await workflowActions.run('network.machines.token.rotate', { machine_id: machineId })
      if (active.current === scope && generation === workflowActions.generation()) await reveal()
    } catch (failure) {
      if (active.current === scope && generation === workflowActions.generation()) { setState({ scope, error: managementError(failure, false) }); if (failure instanceof CoreAdminApiError && [401, 403].includes(failure.status)) onAuthorizationFailure?.(failure) }
    }
  }
  return <DetailSection label={t("服务器部署认证")} title={t("部署认证")} icon={<KeyRound size={17} />}
    description={t("Token 用于服务器向面板认证。主动查看后保留 3 分钟；切换页面、账号或隐藏标签页后清除。")}
    actions={<button type="button" className="button" disabled={locked} onClick={() => void rotate()}><RefreshCw size={15} aria-hidden="true" />{t("重置 Token")}</button>}>
    {error ? <p role="alert">{t(error)}</p> : null}
    <div className="machine-secret-header"><h3>{t("服务器 Token")}</h3>{data ? <button type="button" className="button" onClick={hide}><EyeOff size={15} aria-hidden="true" />{t("隐藏认证信息")}</button>
      : <button type="button" className="button" disabled={locked} onClick={() => void reveal()}><Eye size={15} aria-hidden="true" />{reading ? t("读取中…") : t("查看认证信息")}</button>}</div>
    {data ? <>
      <div className="machine-secret-value"><code>{data.token}</code><CopyButton value={data.token} label={t("复制服务器 Token")} /></div>
      <div className="machine-secret-header"><h3><Terminal size={15} aria-hidden="true" />{t("安装命令")}</h3><CopyButton value={data.install_command} label={t("复制服务器安装命令")} /></div>
      <pre className="machine-install-command">{data.install_command}</pre>
      <p className="network-muted">{t("安装命令包含 Token，请仅在对应服务器执行并妥善保管。")}</p>
    </> : <p className="machine-secret-placeholder">{t("认证信息已隐藏，查看后可复制 Token 和安装命令。")}</p>}
  </DetailSection>
}

function usage(used: string, total: string): number | null {
  const capacity = BigInt(total), current = BigInt(used)
  return capacity === 0n || current > capacity ? null : Number(current * 10000n / capacity) / 100
}
const percent = (value: number) => formatNumber(value, { maximumFractionDigits: 2 }) + '%'
function speed(value: number) {
  if (value >= 1000000000) return (value / 1000000000).toFixed(2) + ' GB/s'
  if (value >= 1000000) return (value / 1000000).toFixed(2) + ' MB/s'
  if (value >= 1000) return (value / 1000).toFixed(2) + ' KB/s'
  return formatNumber(value, { maximumFractionDigits: 2 }) + ' B/s'
}
const readingSpeed = (value: string | null) => value === null ? null : Number(value)
const cpu = (point: HistoryPoint) => Number(point.cpu)

export function MachineHistoryPanel({ machineId, onAuthorizationFailure }: { machineId: string; onAuthorizationFailure?: (error: unknown) => void }) {
  useLocale()
  const [hours, setHours] = useState<1 | 6 | 12 | 24>(1)
  useEffect(() => { setHours(1) }, [machineId])
  const query = useQuery({ queryKey: ['native-admin', 'machine-history', machineId, hours],
    queryFn: ({ signal }) => admin.read('network.machines.history.get', { machine_id: machineId, range_hours: hours, limit: Math.min(1000, hours * 60) }, signal) })
  useEffect(() => {
    if (query.error instanceof CoreAdminApiError && [401, 403].includes(query.error.status)) onAuthorizationFailure?.(query.error)
  }, [query.error, onAuthorizationFailure])
  const points = query.isError ? [] : query.data?.data.points ?? []
  const latest = points.at(-1)
  const memory = latest ? usage(latest.mem_used, latest.mem_total) : null
  const disk = latest ? usage(latest.disk_used, latest.disk_total) : null
  return <DetailSection label={t("服务器负载历史")} title={t("运行负载")} icon={<Activity size={17} />}
    description={t("来自服务器实际上报的负载记录；缺失指标保留为空，心跳时间与管理启用状态分别展示。")}
    actions={<button type="button" className="button" disabled={query.isFetching} onClick={() => void query.refetch()}><RefreshCw size={15} aria-hidden="true" />{t("刷新负载")}</button>}>
    <div className="machine-history-ranges" role="group" aria-label={t("服务器负载时间范围")}>{([1, 6, 12, 24] as const).map(value =>
      <button key={value} type="button" className="button" aria-pressed={hours === value} onClick={() => setHours(value)}>{t("{hours}小时", { hours: value })}</button>)}</div>
    <ManagementFeedback loading={query.isFetching} error={query.error} onRetry={() => void query.refetch()} notFoundMessage={t("服务器不存在或已被删除。")} />
    {latest ? <div className="machine-latest-load" aria-label={t("最近一次负载")}>
      <span>CPU <strong>{percent(cpu(latest))}</strong></span><span>{t("内存")}<strong>{memory === null ? t("未提供有效总量") : percent(memory)}</strong></span>
      <span>{t("磁盘")}<strong>{disk === null ? t("未提供有效总量") : percent(disk)}</strong></span><small>{t("上报于")}{formatTimestamp(latest.recorded_at)}</small>
    </div> : null}
    <div className="machine-history-charts">
      <LineChart title={t("CPU、内存与磁盘负载")} labels={points.map(point => formatTimestamp(point.recorded_at))} formatValue={percent}
        series={[
          { id: 'cpu', label: 'CPU', values: points.map(cpu), displayValues: points.map(point => point.cpu + '%'), color: 'var(--primary)' },
          { id: 'memory', label: t("内存"), values: points.map(point => usage(point.mem_used, point.mem_total)), displayValues: points.map(point => usage(point.mem_used, point.mem_total) === null ? null : t('{used} / {total} 字节', { used: point.mem_used ?? '', total: point.mem_total ?? '' })), color: 'var(--data-orange)' },
          { id: 'disk', label: t("磁盘"), values: points.map(point => usage(point.disk_used, point.disk_total)), displayValues: points.map(point => usage(point.disk_used, point.disk_total) === null ? null : t('{used} / {total} 字节', { used: point.disk_used ?? '', total: point.disk_total ?? '' })), color: 'var(--data-red)' },
        ]} emptyText={t("所选时段暂无负载记录")} />
      <LineChart title={t("服务器网络速率")} labels={points.map(point => formatTimestamp(point.recorded_at))} formatValue={speed}
        series={[
          { id: 'in', label: t("入站"), values: points.map(point => readingSpeed(point.net_in_speed)), displayValues: points.map(point => point.net_in_speed === null ? null : point.net_in_speed + ' B/s'), color: 'var(--data-green)' },
          { id: 'out', label: t("出站"), values: points.map(point => readingSpeed(point.net_out_speed)), displayValues: points.map(point => point.net_out_speed === null ? null : point.net_out_speed + ' B/s'), color: 'var(--data-cyan)' },
        ]} emptyText={t("所选时段暂无网络速率记录")} />
    </div>
    <p className="network-muted">{t("最近{hours}小时中最新的最多{count}个上报样本；不是按分钟补零或全时段连续采样。", { hours, count: Math.min(1000, hours * 60) })}</p>
  </DetailSection>
}
