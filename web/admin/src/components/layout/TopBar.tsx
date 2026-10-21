import { t, useLocale } from '../../lib/i18n'
import { LogOut, Search, X, UserRound, Settings, ShieldCheck } from 'lucide-react'
import { useQuery } from '@tanstack/react-query'
import { useEffect, useMemo, useState } from 'react'
import { createPortal } from 'react-dom'
import { useLocation, useNavigate } from 'react-router-dom'
import { admin, logoutAdmin } from '../../native/session'
import { toast } from 'sonner'
import { StaleAdminContext } from '@txboard/core-sdk'
import { preloadAdminRoute } from '../../lib/routePreload'
import { useDialog } from '../../lib/useDialog'
import { ThemeToggle } from '../ui/ThemeToggle'
import { Popover } from '../ui/Popover'
import { LanguageSelector } from '../ui/LanguageSelector'
import './account-menu.css'

const commandItems = [
  ['/', '仪表盘'],
  ['/config/system', '系统设置'],
  ['/config/system/features', '功能入口'],
  ['/config/system/safe', '安全设置'],
  ['/config/system/subscribe', '订阅设置'],
  ['/config/system/subscribe-template', '订阅模板'],
  ['/config/system/invite', '邀请设置'],
  ['/system/modules', '模块中心'],
  ['/config/server', '服务器配置'],
  ['/config/email', '邮件设置'],
  ['/config/telegram', 'Telegram'],
  ['/config/app', 'APP 设置'],
  ['/config/payment', '支付配置'],
  ['/config/theme', '主题管理'],
  ['/config/appearance', '管理端外观'],
  ['/config/notice', '通知管理'],
  ['/config/knowledge', '知识库管理'],
  ['/config/plugin', '插件管理'],
  ['/server/machine', '机器管理'],
  ['/server/manage', '节点管理'],
  ['/server/group', '分组管理'],
  ['/server/route', '路由管理'],
  ['/server/access-audit', '访问审计'],
  ['/finance/plan', '套餐管理'],
  ['/finance/order', '订单管理'],
  ['/finance/coupon', '优惠券管理'],
  ['/finance/gift-card', '礼品卡管理'],
  ['/finance/commission', '佣金管理'],
  ['/user/manage', '用户管理'],
  ['/user/traffic-reset', '流量重置'],
  ['/user/ticket', '工单管理'],
  ['/system/audit-log', '审计日志'],
  ['/system/sessions', '登录会话'],
  ['/system/queue', '队列监控'],
  ['/config/email/templates', '邮件模板'],
] as const

export function TopBar(_props: { onOpenMenu: () => void }) {
  const { locale } = useLocale()
  const navigate = useNavigate()
  const location = useLocation()
  const [commandOpen, setCommandOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [activeIndex, setActiveIndex] = useState(0)
  const [accountOpen, setAccountOpen] = useState(false)
  const identity = useQuery({ queryKey: ['native-admin', 'identity'], enabled: accountOpen, retry: false,
    queryFn: ({ signal }) => admin.read('identity.admin.get', {}, signal) })
  const account = !identity.isError ? identity.data?.data : undefined
  const commandRef = useDialog(commandOpen, () => setCommandOpen(false))
  const shortcut = /Mac|iPhone|iPad/.test(navigator.platform) ? '⌘ K' : 'Ctrl K'

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

  useEffect(() => { setCommandOpen(false); setAccountOpen(false) }, [location.pathname])

  useEffect(() => {
    commandRef.current?.querySelector('[aria-selected="true"]')?.scrollIntoView?.({ block: 'nearest' })
  }, [activeIndex, commandOpen, commandRef])

  const commands = useMemo(() => {
    const all = commandItems.map(([path, label]) => [path, t(label)] as const)
    const q = query.trim().toLowerCase()
    if (!q) return all
    return all.filter(([path, label]) => `${label} ${path}`.toLowerCase().includes(q))
  }, [query, locale])

  const [exiting, setExiting] = useState(false)
  async function logout() {
    if (exiting) return
    setExiting(true)
    try {
      const result = await logoutAdmin()
      if (!result.serverConfirmed) toast.warning(t('已退出本机。服务端退出结果未确认，请检查会话。'))
      navigate('/sign-in', { replace: true })
    } catch (error) {
      if (!(error instanceof StaleAdminContext)) toast.error(t('退出请求失败，请重试。'))
    } finally { setExiting(false) }
  }

  function go(path: string) {
    setCommandOpen(false)
    setQuery('')
    navigate(path)
  }

  return (
    <>
      <div className="admin-toolbar admin-sidebar-tools" aria-label={t("控制台工具")}>
        <button type="button" aria-label={t("搜索菜单和功能")} title={t("搜索菜单和功能")} className="admin-command-trigger" onClick={() => setCommandOpen(true)}>
            <Search size={16} />
            <span>{t("搜索菜单和功能…")}</span>
            <kbd>{shortcut}</kbd>
        </button>

        <div className="admin-toolbar-actions">
          <LanguageSelector />
          <ThemeToggle />
          <Popover label={t("账户菜单")} open={accountOpen} onOpenChange={setAccountOpen} closeOnSelect triggerClassName="admin-account-trigger" panelClassName="admin-account-menu"
            trigger={<UserRound size={18} />}>
            <div className="admin-account-identity"><strong>{account?.email ?? t(identity.isFetching ? t('正在读取管理员身份…') : t('管理员身份暂不可用'))}</strong>
              {account ? <small>{t('管理员 #{id}', { id: account.id })}</small> : null}
              {identity.isError ? <button type="button" data-popover-stay-open onClick={() => void identity.refetch()}>{t("重新读取身份")}</button> : null}
            </div>
            <div className="admin-account-actions">
              <button type="button" onClick={() => { _props.onOpenMenu(); navigate('/config/system') }}><Settings size={15} />{t("系统设置")}</button>
              <button type="button" onClick={() => { _props.onOpenMenu(); navigate('/system/sessions') }}><ShieldCheck size={15} />{t("登录会话")}</button>
              <button type="button" disabled={exiting} className="admin-account-logout" onClick={() => void logout()}><LogOut size={15} />{t(exiting ? t('正在退出…') : t('退出登录'))}</button>
            </div>
          </Popover>
        </div>
      </div>

      {commandOpen ? createPortal(
        <div className="admin-command-overlay" onMouseDown={event => event.target === event.currentTarget && setCommandOpen(false)}>
          <div ref={commandRef} tabIndex={-1} className="admin-command-dialog" role="dialog" aria-modal="true" aria-label={t("搜索菜单和功能")}>
            <div className="admin-command-input">
              <Search size={18} />
              <input
                data-autofocus
                aria-label={t("搜索菜单和功能")}
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
                placeholder={t("搜索菜单和功能…")}
              />
              <button type="button" aria-label={t("关闭搜索")} onClick={() => setCommandOpen(false)}><X size={17} /></button>
            </div>
            <div className="admin-command-list" id="admin-command-results" role="listbox" aria-label={t("搜索结果")}>
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
              {!commands.length ? <div className="admin-command-empty">{t("没有匹配项")}</div> : null}
            </div>
            <div className="admin-command-hint">{t("↑ ↓ 选择 · Enter 打开 · Esc 关闭")}</div>
          </div>
        </div>
      , document.body) : null}
    </>
  )
}
