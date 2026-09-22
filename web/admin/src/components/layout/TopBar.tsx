import { useQueryClient } from '@tanstack/react-query'
import { LogOut, Menu, Moon, Package, Search, Sun, X } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { createPortal } from 'react-dom'
import { useLocation, useNavigate } from 'react-router-dom'
import { removeAccessToken } from '../../lib/storage'
import { preloadAdminRoute } from '../../lib/routePreload'
import { useDialog } from '../../lib/useDialog'

const commandItems = [
  ['/', '仪表盘'],
  ['/config/system', '系统设置'],
  ['/config/system/safe', '安全设置'],
  ['/config/system/subscribe', '订阅设置'],
  ['/config/system/invite', '邀请设置'],
  ['/config/frontend', '前端设置'],
  ['/config/server', '服务器配置'],
  ['/config/email', '邮件设置'],
  ['/config/telegram', 'Telegram'],
  ['/config/APP', 'APP 设置'],
  ['/config/payment', '支付配置'],
  ['/config/theme', '主题管理'],
  ['/config/notice', '通知管理'],
  ['/config/knowledge', '知识库管理'],
  ['/config/plugin', '插件管理'],
  ['/server/machine', '机器管理'],
  ['/server/manage', '节点管理'],
  ['/server/group', '分组管理'],
  ['/server/route', '路由管理'],
  ['/finance/plan', '套餐管理'],
  ['/finance/order', '订单管理'],
  ['/finance/coupon', '优惠券管理'],
  ['/finance/gift-card', '礼品卡管理'],
  ['/user/manage', '用户管理'],
  ['/user/traffic-reset', '流量重置'],
  ['/user/ticket', '工单管理'],
  ['/system/audit-log', '审计日志'],
] as const

const titleRules: Array<[RegExp, string]> = [
  [/^\/$/, '仪表盘'],
  [/^\/config\/system/, '系统配置'],
  [/^\/config\/frontend/, '前端配置'],
  [/^\/config\/server/, '服务器配置'],
  [/^\/config\/email/, '邮件配置'],
  [/^\/config\/telegram/, 'Telegram'],
  [/^\/config\/APP/, 'APP 配置'],
  [/^\/config\/payment/, '支付配置'],
  [/^\/config\/theme/, '主题管理'],
  [/^\/config\/notice/, '通知管理'],
  [/^\/config\/knowledge/, '知识库管理'],
  [/^\/config\/plugin/, '插件管理'],
  [/^\/server\/machine\/\d+/, '机器详情'],
  [/^\/server\/machine/, '机器管理'],
  [/^\/server\/node\/\d+/, '节点详情'],
  [/^\/server\/manage/, '节点管理'],
  [/^\/server\/group/, '分组管理'],
  [/^\/server\/route/, '路由管理'],
  [/^\/finance\/plan/, '套餐管理'],
  [/^\/finance\/order/, '订单管理'],
  [/^\/finance\/coupon/, '优惠券管理'],
  [/^\/finance\/gift-card/, '礼品卡管理'],
  [/^\/user\/manage/, '用户管理'],
  [/^\/user\/traffic-reset/, '流量重置'],
  [/^\/user\/ticket/, '工单管理'],
  [/^\/system\/audit-log/, '审计日志'],
  [/^\/plugins\//, '插件'],
]

export function TopBar({ onOpenMenu }: { onOpenMenu: () => void }) {
  const navigate = useNavigate()
  const location = useLocation()
  const queryClient = useQueryClient()
  const [dark, setDark] = useState(() => localStorage.getItem('theme') === 'dark')
  const [commandOpen, setCommandOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [activeIndex, setActiveIndex] = useState(0)
  const commandRef = useDialog(commandOpen, () => setCommandOpen(false))
  const shortcut = /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘ K' : 'Ctrl K'

  useEffect(() => {
    document.documentElement.dataset.theme = dark ? 'dark' : 'light'
    localStorage.setItem('theme', dark ? 'dark' : 'light')
  }, [dark])

  useEffect(() => {
    function onKeyDown(event: KeyboardEvent) {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault()
        setCommandOpen(true)
      }
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [])

  useEffect(() => {
    if (!commandOpen) {
      setQuery('')
      setActiveIndex(0)
    }
  }, [commandOpen])

  useEffect(() => { setCommandOpen(false) }, [location.pathname])

  useEffect(() => {
    commandRef.current?.querySelector('[aria-selected="true"]')?.scrollIntoView?.({ block: 'nearest' })
  }, [activeIndex, commandOpen, commandRef])

  const title = useMemo(
    () => titleRules.find(([pattern]) => pattern.test(location.pathname))?.[1] || 'TXBoard',
    [location.pathname],
  )
  const toolbarMode = useMemo(() => {
    const path = location.pathname.replace(/\/$/, '') || '/'
    if (path === '/') return 'dashboard' as const
    if (path === '/config/plugin' || path.startsWith('/plugins/')) return 'icon-title' as const
    return 'search-only' as const
  }, [location.pathname])

  const commands = useMemo(() => {
    const q = query.trim().toLowerCase()
    if (!q) return commandItems
    return commandItems.filter(([path, label]) => `${label} ${path}`.toLowerCase().includes(q))
  }, [query])

  function logout() {
    removeAccessToken()
    queryClient.clear()
    navigate('/sign-in')
  }

  function go(path: string) {
    setCommandOpen(false)
    setQuery('')
    navigate(path)
  }

  return (
    <>
      <header className={`admin-toolbar mode-${toolbarMode}`}>
        <button type="button" className="admin-toolbar-mobile" aria-label="打开导航" onClick={onOpenMenu}>
          <Menu size={20} />
        </button>

        {toolbarMode === 'dashboard' ? <h1>{title}</h1> : null}
        {toolbarMode === 'icon-title' ? (
          <div className="admin-toolbar-icon-title">
            <Package size={24} />
            <h1>{title}</h1>
          </div>
        ) : null}

        <button type="button" aria-label="搜索菜单和功能" className="admin-command-trigger" onClick={() => setCommandOpen(true)}>
            <Search size={16} />
            <span>搜索菜单和功能…</span>
            <kbd>{shortcut}</kbd>
        </button>

        <div className="admin-toolbar-actions">
          <button type="button" className="admin-toolbar-icon" aria-label={dark ? '切换浅色模式' : '切换深色模式'} title={dark ? '切换浅色模式' : '切换深色模式'} onClick={() => setDark(value => !value)}>
            {dark ? <Sun size={20} /> : <Moon size={20} />}
          </button>
          <button type="button" className="admin-avatar-button" onClick={logout} title="退出">
            <span className="admin-avatar">A</span>
          </button>
          <button type="button" className="admin-logout-link" onClick={logout}>
            <LogOut size={16} />
            <span>退出</span>
          </button>
        </div>
      </header>

      {commandOpen ? createPortal(
        <div className="admin-command-overlay" onMouseDown={event => event.target === event.currentTarget && setCommandOpen(false)}>
          <div ref={commandRef} tabIndex={-1} className="admin-command-dialog" role="dialog" aria-modal="true" aria-label="搜索菜单和功能">
            <div className="admin-command-input">
              <Search size={18} />
              <input
                data-autofocus
                aria-label="搜索菜单和功能"
                role="combobox"
                aria-expanded="true"
                aria-autocomplete="list"
                aria-controls="admin-command-results"
                aria-activedescendant={commands.length ? `admin-command-${activeIndex}` : undefined}
                value={query}
                onChange={event => { setQuery(event.target.value); setActiveIndex(0) }}
                onKeyDown={event => {
                  if (event.nativeEvent.isComposing || !commands.length) return
                  if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                    event.preventDefault()
                    setActiveIndex(index => (index + (event.key === 'ArrowDown' ? 1 : -1) + commands.length) % commands.length)
                  }
                  if (event.key === 'Enter') {
                    event.preventDefault()
                    go(commands[activeIndex][0])
                  }
                }}
                placeholder="搜索菜单和功能…"
              />
              <button type="button" aria-label="关闭搜索" onClick={() => setCommandOpen(false)}><X size={17} /></button>
            </div>
            <div className="admin-command-list" id="admin-command-results" role="listbox" aria-label="搜索结果">
              {commands.map(([path, label], index) => (
                <button
                  type="button"
                  key={path}
                  role="option"
                  id={`admin-command-${index}`}
                  aria-selected={index === activeIndex}
                  tabIndex={-1}
                  onMouseEnter={() => { setActiveIndex(index); preloadAdminRoute(path) }}
                  onFocus={() => preloadAdminRoute(path)}
                  onClick={() => go(path)}
                >
                  <span>{label}</span>
                  <small>{path}</small>
                </button>
              ))}
              {!commands.length ? <div className="admin-command-empty">没有匹配项</div> : null}
            </div>
            <div className="admin-command-hint">↑ ↓ 选择 · Enter 打开 · Esc 关闭</div>
          </div>
        </div>
      , document.body) : null}
    </>
  )
}
