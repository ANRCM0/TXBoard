import { Blocks, RefreshCw, X } from 'lucide-react'
import './observation-reference.css'
import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreOutput } from '@txboard/core-sdk'
import { admin, useAdminSession } from './session'
import { ManagementFeedback } from './Feedback'
import { ReadFields } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import { ReadOnlyNotice } from '../components/ui/ReadOnlyNotice'
import { StatusBadge } from '../components/ui/StatusBadge'
import { Popover } from '../components/ui/Popover'
import { DataTable, type Column } from '../components/ui/DataTable'

type Module = CoreOutput<'platform.modules.get'>
const operationLabels = { install: '安装', enable: '启用', disable: '停用', upgrade: '升级', uninstall: '卸载' }
export function ModulesPage({ kind }: { kind?: 'plugin' | 'theme' }) {
  const session = useAdminSession()
  const [selected, setSelected] = useState(''), [keyword, setKeyword] = useState(''), [status, setStatus] = useState('')
  useEffect(() => { setSelected(''); setKeyword(''); setStatus('') }, [kind])
  const list = useQuery({ queryKey: ['native-admin', 'modules'], enabled: session,
    queryFn: ({ signal }) => admin.read('platform.modules.list', {}, signal) })
  const detail = useQuery({ queryKey: ['native-admin', 'modules', selected], enabled: session && !!selected,
    queryFn: ({ signal }) => admin.read('platform.modules.get', { module_id: selected }, signal) })
  const operations = useQuery({ queryKey: ['native-admin', 'module-operations', selected], enabled: session && !!selected,
    queryFn: ({ signal }) => admin.read('platform.modules.operations.list', { module_id: selected }, signal) })
  const denied = [list.error, detail.error, operations.error].some(error => error instanceof CoreAdminApiError && [401, 403].includes(error.status))
  const visible = session && !denied
  const rows = visible && !list.isError ? (list.data?.data.modules ?? []).filter(row => (!kind || row.type === kind)
    && (!status || (status === 'enabled' ? row.enabled : status === 'installed' ? row.installed : !row.installed))
    && [row.id, row.name, row.description ?? ''].some(value => value.toLowerCase().includes(keyword.toLowerCase()))) : []
  const current = visible && !detail.isError ? detail.data?.data : undefined
  async function refresh() { await Promise.all([list.refetch(), ...(selected ? [detail.refetch(), operations.refetch()] : [])]) }
  const columns: Column<Module>[] = [
    { key: 'name', header: '模块', render: row => <button className="native-read-link" onClick={() => setSelected(row.id)}><span className="observation-identity"><Blocks size={20} /><span><strong>{row.name || row.id}</strong><small>{row.description || row.id}</small></span></span></button> },
    { key: 'type', header: '类型', render: row => <span className="observation-tag">{row.type === 'theme' ? '主题' : row.type === 'plugin' ? '插件' : row.type}</span> },
    { key: 'version', header: '版本', render: row => <span className="observation-mono">{row.version}</span> },
    { key: 'source', header: '来源', render: row => row.source },
    { key: 'installed', header: '安装状态', render: row => <StatusBadge tone={row.installed ? 'success' : 'neutral'}>{row.installed ? '已安装' : '未安装'}</StatusBadge> },
    { key: 'enabled', header: '运行状态', render: row => <StatusBadge tone={row.enabled ? 'success' : 'neutral'}>{row.enabled ? '已启用' : '未启用'}</StatusBadge> },
    { key: 'health', header: '健康', render: row => <StatusBadge tone={row.health === 'healthy' ? 'success' : row.health === 'unhealthy' ? 'danger' : 'neutral'}>{({ healthy: '正常', unhealthy: '异常', unknown: '未知' } as Record<string, string>)[row.health] ?? row.health}</StatusBadge> },
  ]
  return <div className="native-management-page observation-page observation-modules-page">
    <PageHeader title={kind === 'theme' ? '主题管理' : kind === 'plugin' ? '插件管理' : '模块清单'} description="管理模块版本与运行状态" action={<>
      <Popover label="如何变更模块"><p>安装、启停、升级及卸载需在部署环境中执行 · 此页面仅供查看</p></Popover>
      <button className="button" disabled={!session || list.isFetching} onClick={() => void refresh()}><RefreshCw size={15} />刷新</button>
    </>} />
    <ReadOnlyNotice title="运行时清单只读">安装、启停、升级及卸载由受控部署流程负责 · 此页面只展示当前注册状态与声明能力</ReadOnlyNotice>
    <section className="list-card native-read-list"><div className="observation-card-heading"><div><h2>{kind === 'theme' ? '主题目录' : kind === 'plugin' ? '插件目录' : '模块目录'}</h2><p>查看版本、来源与可用状态</p></div><span className="observation-tag">{visible && list.data && !list.isError ? rows.length + ' 项' : '等待加载'}</span></div>
      <form className="native-filters observation-module-filters" onSubmit={event => event.preventDefault()}><label>搜索<input placeholder="搜索名称、ID 或描述" maxLength={120} value={keyword} onChange={event => setKeyword(event.target.value)} /></label><label>状态<select value={status} onChange={event => setStatus(event.target.value)}><option value="">全部</option><option value="installed">已安装</option><option value="enabled">已启用</option><option value="uninstalled">未安装</option></select></label>{keyword || status ? <button className="button observation-reset" type="button" onClick={() => { setKeyword(''); setStatus('') }}>清除筛选</button> : null}</form>
      <ManagementFeedback loading={list.isFetching} error={list.error} onRetry={() => void refresh()} />
      {denied ? <p role="alert">无法确认模块读取权限，已隐藏清单和详情。</p> : null}
      {visible && list.data && !list.isError ? <>{list.data.data.summary.discovery_errors > 0 ? <p className="observation-tag" data-tone="warning">{list.data.data.summary.discovery_errors} 项模块发现异常</p> : null}<p className="native-scroll-hint">左右滑动表格查看完整信息</p><DataTable label="模块清单" columns={columns} rows={rows} rowKey={row => row.id} empty="没有符合条件的模块" /></> : null}
    </section>
    {selected && visible ? <section className="list-card observation-detail" aria-label="模块详情"><div className="observation-card-heading"><div><h2>{current?.name || '模块详情'}</h2><p>版本信息与支持的操作</p></div><button className="button" aria-label="关闭模块详情" onClick={() => setSelected('')}><X size={15} />关闭</button></div>
      <ManagementFeedback loading={detail.isFetching || operations.isFetching} error={detail.error || operations.error} onRetry={() => void refresh()} />
      {current ? <ReadFields fields={[
        ['模块 ID', current.id], ['名称', current.name], ['版本', current.version], ['类型', current.type], ['说明', current.description], ['作者', current.author], ['来源', current.source],
        ['兼容 TXBoard', current.compatibility.txboard], ['能力', current.capabilities.join('、') || '未声明'], ['健康', current.health], ['当前激活', current.active === null ? '不适用' : current.active ? '是' : '否'],
      ]} /> : null}
      {operations.data && !operations.isError ? <p>模块声明支持：{operations.data.data.operations.map(operation => operationLabels[operation]).join('、') || '无生命周期操作'}。</p> : null}
    </section> : null}
  </div>
}
