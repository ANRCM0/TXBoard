import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Boxes, FileUp, Trash2, UploadCloud } from 'lucide-react'
import { useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { toast } from 'sonner'
import {
  deletePlugin,
  disablePlugin,
  enablePlugin,
  getPlugins,
  installPlugin,
  uninstallPlugin,
  upgradePlugin,
  uploadPlugin,
  type PluginItem,
} from '../../api/plugin'
import { PageHeader } from '../../components/ui/PageHeader'
import { requestConfirm } from '../../components/ui/ConfirmDialog'
import { EmptyState } from '../../components/ui/EmptyState'
import { QueryFeedback } from '../../components/ui/QueryFeedback'
import { StatusText } from '../../components/ui/StatusText'

export function PluginsPage() {
  const qc = useQueryClient()
  const inputRef = useRef<HTMLInputElement>(null)
  const [search, setSearch] = useState('')
  const [type, setType] = useState('all')
  const [status, setStatus] = useState('all')

  const query = useQuery({ queryKey: ['pluginList'], queryFn: () => getPlugins() })

  const action = useMutation({
    mutationFn: async ({ kind, code }: { kind: string; code: string }) => {
      if (kind === 'install') return installPlugin(code)
      if (kind === 'uninstall') return uninstallPlugin(code)
      if (kind === 'enable') return enablePlugin(code)
      if (kind === 'disable') return disablePlugin(code)
      if (kind === 'upgrade') return upgradePlugin(code)
      if (kind === 'delete') return deletePlugin(code)
      return null
    },
    onSuccess: () => {
      toast.success('操作成功')
      qc.invalidateQueries({ queryKey: ['pluginList'] })
    },
  })

  const upload = useMutation({
    mutationFn: uploadPlugin,
    onSuccess: () => {
      toast.success('插件包上传成功')
      qc.invalidateQueries({ queryKey: ['pluginList'] })
    },
  })

  const rows = useMemo(() => {
    const list = Array.isArray(query.data) ? query.data : []
    const keyword = search.trim().toLowerCase()
    return list.filter(plugin => {
      if (type !== 'all' && plugin.type !== type) return false
      if (status === 'installed' && !plugin.is_installed) return false
      if (status === 'available' && plugin.is_installed) return false
      if (status === 'enabled' && !plugin.is_enabled) return false
      if (keyword && !`${plugin.name || ''} ${plugin.code} ${plugin.description || ''}`.toLowerCase().includes(keyword)) return false
      return true
    })
  }, [query.data, search, type, status])

  return <>
    <PageHeader
      title="插件管理"
      description="支持安装、卸载、启停、升级、ZIP 上传、删除及 Schema-driven 管理页面。"
      action={<>
        <input
          ref={inputRef}
          type="file"
          accept=".zip"
          hidden
          onChange={event => {
            const file = event.target.files?.[0]
            if (file) upload.mutate(file)
            event.currentTarget.value = ''
          }}
        />
        <button className="button primary" disabled={upload.isPending} onClick={() => inputRef.current?.click()}>
          <UploadCloud size={16}/>{upload.isPending ? '上传中…' : '上传插件'}
        </button>
      </>}
    />

    <div className="toolbar plugin-toolbar">
      <input aria-label="搜索插件" className="search-input" placeholder="搜索插件…" value={search} onChange={e => setSearch(e.target.value)}/>
      <select aria-label="插件类型" value={type} onChange={e => setType(e.target.value)}>
        <option value="all">全部类型</option>
        <option value="feature">功能插件</option>
        <option value="payment">支付插件</option>
      </select>
      <select aria-label="插件状态" value={status} onChange={e => setStatus(e.target.value)}>
        <option value="all">全部状态</option>
        <option value="installed">已安装</option>
        <option value="available">可安装</option>
        <option value="enabled">已启用</option>
      </select>
    </div>

    {query.isLoading ? <QueryFeedback loading /> : query.isError ? (
      <QueryFeedback error onRetry={() => void query.refetch()} />
    ) : !rows.length ? (
      <EmptyState className="admin-v2-empty"
        icon={<Boxes size={23}/>}
        title={query.data?.length ? '没有符合条件的插件' : '还没有插件'}
        description={query.data?.length ? '调整搜索或筛选条件，或者清除筛选。' : '可以上传 ZIP 插件包开始管理。'}
        action={query.data?.length ? <button className="button" type="button" onClick={() => {
          setSearch(''); setType('all'); setStatus('all')
        }}>清除筛选</button> : <button className="button primary" type="button" onClick={() => inputRef.current?.click()}>上传插件</button>}
      />
    ) : <div className="admin-v2-plugin-list" role="list" aria-label="插件列表">
      {rows.map((plugin: PluginItem) => <article className="admin-v2-plugin-row" key={plugin.code} role="listitem">
        <div className="admin-v2-plugin-identity">
          <div className="admin-v2-plugin-icon" aria-hidden="true">{(plugin.name || plugin.code).slice(0,1).toUpperCase()}</div>
          <div className="admin-v2-plugin-copy">
            <h3>{plugin.name || plugin.code}</h3>
            <div className="admin-v2-plugin-meta">
              <span>v{plugin.version || 'unknown'}</span>
              <span>{plugin.type === 'payment' ? '支付插件' : '功能插件'}</span>
              {plugin.is_protected ? <span>Core</span> : null}
            </div>
            <p>{plugin.description || '暂无描述'}</p>
          </div>
        </div>
        <div className="admin-v2-plugin-state">
          {plugin.is_installed ? <StatusText tone={plugin.is_enabled ? 'success' : 'neutral'}>
            {plugin.is_enabled ? '已启用' : '已安装 · 未启用'}
          </StatusText> : <StatusText>未安装</StatusText>}
          {plugin.need_upgrade ? <span className="plugin-upgrade-badge">可升级</span> : null}
        </div>
        <div className="admin-v2-plugin-actions">
          <Link className="button" to={`/plugins/${plugin.code}`}>详情</Link>

          {!plugin.is_installed ? <button
            type="button" className="button primary" disabled={action.isPending}
            onClick={() => action.mutate({kind:'install',code:plugin.code})}
          >安装</button> : <>
            {plugin.need_upgrade ? <button type="button" className="button" disabled={action.isPending}
              onClick={() => action.mutate({kind:'upgrade',code:plugin.code})}><FileUp size={15}/>升级</button> : null}
            <button type="button" className="button" disabled={action.isPending}
              onClick={() => action.mutate({kind:plugin.is_enabled ? 'disable' : 'enable',code:plugin.code})}
            >{plugin.is_enabled ? '禁用' : '启用'}</button>
            {!plugin.is_enabled ? <button type="button" className="button danger" disabled={action.isPending}
              onClick={() => requestConfirm({title:'卸载插件',message:`确认卸载插件「${plugin.name || plugin.code}」？卸载后配置将保留。`,danger:true,confirmLabel:'卸载',action:()=>action.mutate({kind:'uninstall',code:plugin.code})})}
            >卸载</button> : null}
          </>}
          {plugin.can_be_deleted && !plugin.is_installed ? <button
            type="button" className="icon-button danger" aria-label={`删除插件 ${plugin.name || plugin.code}`}
            title="删除插件文件" disabled={action.isPending}
            onClick={() => requestConfirm({title:'删除插件目录',message:'确认永久删除该插件目录？该操作不可恢复。',danger:true,confirmLabel:'删除',action:()=>action.mutate({kind:'delete',code:plugin.code})})}
          ><Trash2 size={15}/></button> : null}
        </div>
      </article>)}
    </div>}

  </>
}
