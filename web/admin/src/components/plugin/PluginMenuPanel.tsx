import { ExternalLink } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { toast } from 'sonner'
import { resolvePluginRenderer } from '../../plugins/registry'
import { buildPluginBridgeInit, normalizePluginNavigationTarget } from '../../plugins/bridge'
import {
  fetchPluginMenuHtml,
  normalizePluginPath,
  resolvePluginAppUrl,
  type PluginAdminMenu,
  type PluginItem,
} from '../../api/plugin'

function iframeSource(menu: PluginAdminMenu) {
  const value = menu.url?.trim() || menu.embed?.trim()
  if (!value) return null
  if (/^https?:\/\//i.test(value) || value.startsWith('/')) return value
  return '/' + value.replace(/^\/+/, '')
}

export function PluginMenuPanel({
  plugin,
  menu,
}: {
  plugin: PluginItem
  menu: PluginAdminMenu
}) {
  const navigate = useNavigate()
  const frameRef = useRef<HTMLIFrameElement>(null)
  const path = normalizePluginPath(menu.path)
  const NativeRenderer = resolvePluginRenderer(menu.renderer)
  const pluginAppSrc = useMemo(
    () => NativeRenderer ? null : resolvePluginAppUrl(plugin, menu),
    [plugin, menu, NativeRenderer],
  )
  const directSrc = useMemo(
    () => NativeRenderer ? null : pluginAppSrc || iframeSource(menu),
    [menu, NativeRenderer, pluginAppSrc],
  )
  const [html, setHtml] = useState<string | null>(null)
  const [loading, setLoading] = useState(!directSrc)
  const [failed, setFailed] = useState(false)

  const sendBridgeInit = useCallback(() => {
    if (!pluginAppSrc || !frameRef.current?.contentWindow) return
    frameRef.current.contentWindow.postMessage(
      buildPluginBridgeInit(plugin, path),
      window.location.origin,
    )
  }, [path, plugin, pluginAppSrc])

  useEffect(() => {
    if (!pluginAppSrc) return

    const onMessage = (event: MessageEvent) => {
      if (event.origin !== window.location.origin) return
      if (event.source !== frameRef.current?.contentWindow) return
      if (!event.data || typeof event.data !== 'object') return

      if (event.data.type === 'txboard:plugin:ready' && event.data.version === 1) {
        sendBridgeInit()
        return
      }

      if (event.data.type === 'txboard:plugin:navigate' && event.data.version === 1) {
        const target = normalizePluginNavigationTarget(event.data.path)
        if (target) navigate(`/plugins/${plugin.code}/${target}`)
      }
    }

    window.addEventListener('message', onMessage)
    return () => window.removeEventListener('message', onMessage)
  }, [navigate, plugin.code, pluginAppSrc, sendBridgeInit])

  useEffect(() => {
    if (NativeRenderer) {
      setLoading(false)
      setFailed(false)
      setHtml(null)
      return
    }
    if (directSrc) {
      setLoading(false)
      setFailed(false)
      setHtml(null)
      return
    }
    let cancelled = false
    setLoading(true)
    setFailed(false)
    setHtml(null)
    if (!menu.component?.trim()) {
      setFailed(true)
      setLoading(false)
      return
    }
    fetchPluginMenuHtml(menu.component).then(result => {
      if (cancelled) return
      setHtml(result)
      setFailed(!result)
      setLoading(false)
    })
    return () => { cancelled = true }
  }, [directSrc, menu.component, NativeRenderer])

  if (NativeRenderer) {
    return <NativeRenderer plugin={plugin} menu={menu}/>
  }

  function openContent() {
    if (pluginAppSrc) {
      window.open(window.location.href, '_blank', 'noopener,noreferrer')
      return
    }
    if (directSrc) {
      window.open(directSrc, '_blank', 'noopener,noreferrer')
      return
    }
    if (!html) {
      toast.error('插件页面内容不可用')
      return
    }
    const popup = window.open('', '_blank', 'noopener,noreferrer')
    if (!popup) {
      toast.error('浏览器阻止了弹窗')
      return
    }
    popup.document.open()
    popup.document.write(html)
    popup.document.close()
  }

  return <div className="card plugin-menu-panel">
    <div className="plugin-panel-head">
      <div><h3>{menu.title || path}</h3><p>{menu.description || '该页面由插件提供。'}</p></div>
      <button className="button" onClick={openContent} disabled={!directSrc && !html}>
        <ExternalLink size={15}/>新窗口打开
      </button>
    </div>

    {loading ? <div className="empty-state">正在加载插件页面…</div> :
      directSrc || html ? <iframe
        ref={frameRef}
        title={menu.title || path}
        src={directSrc || undefined}
        srcDoc={directSrc ? undefined : html || undefined}
        onLoad={pluginAppSrc ? sendBridgeInit : undefined}
        className="plugin-menu-frame"
        sandbox="allow-scripts allow-same-origin allow-forms allow-popups"
      /> :
      <div className="empty-state">
        <strong>插件页面内容不可用</strong>
        <p>{failed ? '插件未声明可用的 app / url / embed / component，或声明的页面当前不可访问。' : '没有可渲染内容。'}</p>
      </div>}
  </div>
}
