import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreOutput } from '@txboard/core-sdk'
import { admin, useAdminSession } from './session'
import { ManagementFeedback } from './Feedback'
import { ReadFields } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
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
    { key: 'name', header: '模块', render: row => <button className="native-read-link" onClick={() => setSelected(row.id)}>{row.name || row.id}</button> },
    { key: 'type', header: '类型', render: row => row.type },
    { key: 'version', header: '版本', render: row => row.version },
    { key: 'source', header: '来源', render: row => row.source },
    { key: 'installed', header: '安装状态', render: row => row.installed ? '已安装' : '未安装' },
    { key: 'enabled', header: '运行状态', render: row => row.enabled ? '已启用' : '未启用' },
    { key: 'health', header: '健康', render: row => row.health },
  ]
  return <div className="native-management-page">
    <PageHeader title={kind === 'theme' ? '主题管理' : kind === 'plugin' ? '插件管理' : '模块清单'} description="查看已发现模块、兼容性、运行状态和生命周期支持范围。" action={<button className="button" disabled={!session || list.isFetching} onClick={() => void refresh()}>刷新模块清单</button>} />
    <section className="list-card native-read-list">
      <form className="native-filters" onSubmit={event => event.preventDefault()}><label>搜索模块<input maxLength={120} value={keyword} onChange={event => setKeyword(event.target.value)} /></label><label>安装与运行状态<select value={status} onChange={event => setStatus(event.target.value)}><option value="">全部</option><option value="installed">已安装</option><option value="enabled">已启用</option><option value="uninstalled">未安装</option></select></label></form>
      <ManagementFeedback loading={list.isFetching} error={list.error} onRetry={() => void refresh()} />
      {denied ? <p role="alert">无法确认模块读取权限，已隐藏清单和详情。</p> : null}
      {visible && list.data && !list.isError ? <><p>发现 {list.data.data.summary.total} 个模块，发现错误 {list.data.data.summary.discovery_errors} 项；当前筛选 {rows.length} 项。</p><p className="native-scroll-hint">左右滑动表格查看完整信息</p><DataTable label="模块清单" columns={columns} rows={rows} rowKey={row => row.id} empty="没有符合条件的模块" /></> : null}
    </section>
    {selected && visible ? <section className="list-card" aria-label="模块详情"><h2>模块详情</h2><button className="button" onClick={() => setSelected('')}>关闭模块详情</button>
      <ManagementFeedback loading={detail.isFetching || operations.isFetching} error={detail.error || operations.error} onRetry={() => void refresh()} />
      {current ? <ReadFields fields={[
        ['模块 ID', current.id], ['名称', current.name], ['版本', current.version], ['类型', current.type], ['说明', current.description], ['作者', current.author], ['来源', current.source],
        ['兼容 TXBoard', current.compatibility.txboard], ['能力', current.capabilities.join('、') || '未声明'], ['健康', current.health], ['当前激活', current.active === null ? '不适用' : current.active ? '是' : '否'],
      ]} /> : null}
      {operations.data && !operations.isError ? <p>模块声明支持：{operations.data.data.operations.map(operation => operationLabels[operation]).join('、') || '无生命周期操作'}。</p> : null}
      <p>安装、启停、升级及卸载须通过受控部署流程执行；此处展示支持范围，不授予执行权限。</p>
    </section> : null}
  </div>
}
