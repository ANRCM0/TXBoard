import { ExternalLink } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { toast } from 'sonner'
import { resolvePluginRenderer } from '../../plugins/registry'
import {
  fetchPluginMenuHtml,
  normalizePluginPath,
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
  const path = normalizePluginPath(menu.path)
  const NativeRenderer = resolvePluginRenderer(menu.renderer)
  const directSrc = useMemo(() => NativeRenderer ? null : iframeSource(menu), [menu, NativeRenderer])
  const [html, setHtml] = useState<string | null>(null)
  const [loading, setLoading] = useState(!directSrc)
  const [failed, setFailed] = useState(false)

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
        title={menu.title || path}
        src={directSrc || undefined}
        srcDoc={directSrc ? undefined : html || undefined}
        className="plugin-menu-frame"
        sandbox="allow-scripts allow-same-origin allow-forms allow-popups"
      /> :
      <div className="empty-state">
        <strong>插件页面内容不可用</strong>
        <p>{failed ? '插件未声明可用的 url / embed / component，或声明的页面当前不可访问。' : '没有可渲染内容。'}</p>
      </div>}
  </div>
}
