import { t, useLocale, formatNumber } from '../lib/i18n'
import { ChevronDown } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import type { CoreOutput } from '@txboard/core-sdk'
import { Popover } from '../components/ui/Popover'
import { StatusBadge } from '../components/ui/StatusBadge'
import { formatTimestamp } from './Inventory'
import { NetworkBytes } from './NetworkValues'
import './server-quick.css'

export type ServerQuickMachine = CoreOutput<'network.machines.list'>[number]

export function ServerQuickCard({ machine }: { machine: ServerQuickMachine }) {
  useLocale()
  const [open, setOpen] = useState(false)
  const openTimer = useRef<number>(), closeTimer = useRef<number>()
  function clearTimers() {
    window.clearTimeout(openTimer.current)
    window.clearTimeout(closeTimer.current)
  }
  useEffect(() => clearTimers, [])
  function scheduleOpen() {
    clearTimers()
    openTimer.current = window.setTimeout(() => setOpen(true), 140)
  }
  function scheduleClose() {
    window.clearTimeout(openTimer.current)
    window.clearTimeout(closeTimer.current)
    closeTimer.current = window.setTimeout(() => setOpen(false), 180)
  }
  function keepOpen() { clearTimers() }
  const observation = machine.observation
  const status = !machine.is_active ? 'inactive' : observation?.online ? 'online' : observation ? 'offline' : 'unknown'
  const load = observation?.telemetry_fresh ? observation : undefined
  return <div className="server-quick" onPointerEnter={scheduleOpen} onPointerLeave={scheduleClose}
    onBlurCapture={event => { if (!event.currentTarget.contains(event.relatedTarget as Node)) scheduleClose() }}
    onKeyDown={event => { if (event.key === 'Escape' && open) { event.stopPropagation(); keepOpen(); setOpen(false) } }}>
    <span className="server-quick-name">
      <span className={'server-quick-dot is-' + status} aria-hidden="true" />
      <span className="native-row-identity">
        <Link to={'/server/machine/' + machine.id}><strong>{machine.name}</strong></Link>
        <small>#{machine.id}</small>
      </span>
    </span>
    <Popover label={t("服务器详情")} open={open} onOpenChange={next => { keepOpen(); setOpen(next) }}
      triggerClassName="server-quick-toggle" panelClassName="server-quick-panel"
      trigger={<ChevronDown size={14} aria-hidden="true" />}>
      <div className="server-quick-content" onPointerEnter={keepOpen} onPointerLeave={scheduleClose}>
        <header className="server-quick-head">
          <div><strong>{machine.name}</strong><small>#{machine.id} · {machine.image_channel}</small></div>
          <StatusBadge tone={machine.is_active ? 'success' : 'neutral'}>{machine.is_active ? t("已启用") : t("已停用")}</StatusBadge>
        </header>
        <div className="server-quick-status">
          {status === 'online' ? <StatusBadge tone="success">{t("在线")}</StatusBadge>
            : status === 'offline' ? <StatusBadge tone="danger">{t("离线")}</StatusBadge>
              : status === 'inactive' ? <StatusBadge tone="neutral">{t("已停用")}</StatusBadge>
                : <StatusBadge tone="neutral">{t("未上报")}</StatusBadge>}
          {observation?.high_load ? <StatusBadge tone="warning">{t("高负载")}</StatusBadge> : null}
        </div>
        <dl className="server-quick-facts">
          <div><dt>{t("绑定节点")}</dt><dd>{formatNumber(machine.servers_count)}</dd></div>
          <div><dt>{t("最近心跳")}</dt><dd>{machine.last_seen_at === null ? t("尚未上报心跳") : formatTimestamp(machine.last_seen_at)}</dd></div>
        </dl>
        {load ? <div className="server-quick-load">
          <div><span>CPU</span><strong>{load.cpu_percent === null ? t("未上报") : load.cpu_percent + '%'}</strong></div>
          <div><span>{t("内存")}</span><strong><NetworkBytes value={load.memory_used_bytes} /> / <NetworkBytes value={load.memory_total_bytes} /></strong></div>
          <div><span>{t("磁盘")}</span><strong><NetworkBytes value={load.disk_used_bytes} /> / <NetworkBytes value={load.disk_total_bytes} /></strong></div>
        </div> : <p className="server-quick-empty">{t("负载未上报或已过期")}</p>}
        <footer className="server-quick-actions">
          <Link className="button" to={'/server/machine/' + machine.id}>{t("查看详情")}</Link>
          <Link className="button" to={'/server/manage?machine_id=' + machine.id}>{t("绑定节点")}</Link>
        </footer>
      </div>
    </Popover>
  </div>
}
