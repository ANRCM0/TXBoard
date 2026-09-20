import { useQuery } from '@tanstack/react-query'
import { ChevronRight, Settings2 } from 'lucide-react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import {
  getPlugins,
  normalizePluginPath,
  type PluginAdminCrudSchema,
  type PluginAdminMenu,
} from '../../api/plugin'
import { PluginCrudPanel } from '../../components/plugin/PluginCrudPanel'
import { PluginMenuPanel } from '../../components/plugin/PluginMenuPanel'
import { PluginSettingsPanel } from '../../components/plugin/PluginSettingsPanel'
import { PageHeader } from '../../components/ui/PageHeader'

export function PluginRoutePage() {
  const navigate = useNavigate()
  const params = useParams()
  const pluginCode = params.pluginCode || ''
  const subpath = normalizePluginPath(params['*'])
  const query = useQuery({ queryKey: ['pluginList'], queryFn: () => getPlugins() })
  const plugin = (Array.isArray(query.data) ? query.data : []).find(item => item.code === pluginCode)

  if (query.isLoading) return <div className="card">加载插件…</div>

  if (!plugin) return <div className="card">
    <h3>插件不存在</h3>
    <p className="muted">当前插件列表中没有找到 <code>{pluginCode}</code>。</p>
    <Link className="button" to="/config/plugin">返回插件管理</Link>
  </div>

  if (!plugin.is_enabled) {
    return <>
      <PageHeader title={plugin.name || plugin.code} description="该插件当前未启用。"/>
      <div className="card">
        <p>启用插件后才能访问插件后台页面。</p>
        <Link className="button" to="/config/plugin">返回插件管理</Link>
      </div>
    </>
  }

  const menu = findMenu(plugin.admin_menus, subpath)
  const crud = findCrud(plugin.admin_crud, subpath)
  const hasConfig = Boolean(plugin.config && Object.keys(plugin.config).length)
  const showSettings = subpath === 'settings' && hasConfig
  const showOverview = !subpath
  const unknown = Boolean(subpath && !menu && !crud && !showSettings)

  const title = crud?.title || menu?.title || plugin.name || plugin.code
  const description = crud?.description || menu?.description || plugin.description || `${plugin.author || 'Unknown'} · ${plugin.version || 'unknown'}`

  return <>
    <PageHeader
      title={title}
      description={description}
      action={<Link className="button" to="/config/plugin">插件列表</Link>}
    />

    {crud && <PluginCrudPanel plugin={plugin} subpath={subpath} schema={crud}/>}
    {menu && <PluginMenuPanel plugin={plugin} menu={menu}/>}
    {showSettings && <PluginSettingsPanel plugin={plugin}/>}

    {unknown && <div className="card empty-state">
      <strong>插件页面不存在</strong>
      <p>插件没有声明路径 <code>{subpath}</code>。</p>
      <button className="button" onClick={() => navigate(`/plugins/${plugin.code}`)}>返回插件概览</button>
    </div>}

    {showOverview && <div className="plugin-overview">
      <div className="two-col">
        <section className="card">
          <div className="plugin-panel-head">
            <div><h3>插件信息</h3><p>{plugin.description || '暂无描述'}</p></div>
            {plugin.version && <span className="badge">v{plugin.version}</span>}
          </div>
          <dl className="meta-list">
            <div><dt>Code</dt><dd><code>{plugin.code}</code></dd></div>
            <div><dt>作者</dt><dd>{plugin.author || '-'}</dd></div>
            <div><dt>类型</dt><dd>{plugin.type || '-'}</dd></div>
            <div><dt>状态</dt><dd><span className="status ok">已启用</span></dd></div>
            <div><dt>CRUD 页面</dt><dd>{Object.keys(plugin.admin_crud || {}).length}</dd></div>
            <div><dt>菜单页面</dt><dd>{plugin.admin_menus?.length || 0}</dd></div>
          </dl>
        </section>

        <section className="card">
          <div className="plugin-panel-head"><div><h3>运行时入口</h3><p>由插件 config.json 动态声明。</p></div></div>
          <div className="plugin-runtime-links">
            {hasConfig && <Link className="runtime-link" to={`/plugins/${plugin.code}/settings`}>
              <Settings2 size={17}/><span><strong>插件设置</strong><small>动态配置表单</small></span><ChevronRight size={16}/>
            </Link>}

            {Object.entries(plugin.admin_crud || {}).map(([path, schema]) => <Link className="runtime-link" key={path} to={`/plugins/${plugin.code}/${normalizePluginPath(path)}`}>
              <span className="runtime-icon">C</span>
              <span><strong>{schema.title || path}</strong><small>{schema.description || 'CRUD 管理页面'}</small></span>
              <ChevronRight size={16}/>
            </Link>)}

            {(plugin.admin_menus || []).map((item, index) => {
              const path = normalizePluginPath(item.path)
              if (!path) return null
              return <Link className="runtime-link" key={item.id || `${path}-${index}`} to={`/plugins/${plugin.code}/${path}`}>
                <span className="runtime-icon">{item.icon || 'M'}</span>
                <span><strong>{item.title || path}</strong><small>{item.description || item.label || '插件菜单页面'}</small></span>
                <ChevronRight size={16}/>
              </Link>
            })}

            {!hasConfig && !Object.keys(plugin.admin_crud || {}).length && !plugin.admin_menus?.length &&
              <div className="empty-state">该插件没有声明后台配置或管理页面。</div>}
          </div>
        </section>
      </div>

      {plugin.readme && <section className="card">
        <div className="plugin-panel-head"><div><h3>README</h3><p>插件随包文档</p></div></div>
        <pre className="readme-pre">{plugin.readme}</pre>
      </section>}
    </div>}
  </>
}

function findMenu(menus: PluginAdminMenu[] | null | undefined, subpath: string) {
  if (!subpath || !menus?.length) return null
  return menus.find(menu => normalizePluginPath(menu.path) === subpath) || null
}

function findCrud(cruds: Record<string, PluginAdminCrudSchema> | null | undefined, subpath: string) {
  if (!subpath || !cruds) return null
  return cruds[subpath] || cruds[normalizePluginPath(subpath)] || null
}
