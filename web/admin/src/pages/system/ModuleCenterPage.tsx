import { useQuery } from '@tanstack/react-query'
import {
  AlertTriangle,
  Boxes,
  CheckCircle2,
  CircleDot,
  RefreshCw,
  Search,
  ShieldAlert,
} from 'lucide-react'
import { useMemo, useState } from 'react'
import {
  getModuleRegistry,
  type ModuleDescriptor,
  type ModuleHealth,
} from '../../api/module'
import { PageHeader } from '../../components/ui/PageHeader'
import { QueryFeedback } from '../../components/ui/QueryFeedback'
import { SummaryStrip } from '../../components/ui/SummaryStrip'
import { StatusText } from '../../components/ui/StatusText'
import { EmptyState } from '../../components/ui/EmptyState'

export function ModuleCenterPage() {
  const [search, setSearch] = useState('')
  const [type, setType] = useState('all')
  const [source, setSource] = useState('all')
  const [health, setHealth] = useState('all')

  const query = useQuery({
    queryKey: ['moduleRegistry'],
    queryFn: getModuleRegistry,
  })

  const modules = query.data?.modules || []
  const errors = query.data?.errors || []
  const summary = query.data?.summary

  const types = useMemo(() => unique(modules.map(module => module.type)), [modules])
  const sources = useMemo(() => unique(modules.map(module => module.source)), [modules])
  const healthStates = useMemo(() => unique(modules.map(module => module.health)), [modules])

  const rows = useMemo(() => {
    const keyword = search.trim().toLowerCase()

    return modules.filter(module => {
      if (type !== 'all' && module.type !== type) return false
      if (source !== 'all' && module.source !== source) return false
      if (health !== 'all' && module.health !== health) return false

      if (!keyword) return true
      return [
        module.id,
        module.name,
        module.version,
        module.description || '',
        module.author || '',
        module.type,
        module.source,
        ...module.capabilities,
      ].join(' ').toLowerCase().includes(keyword)
    })
  }, [health, modules, search, source, type])

  const attention = (summary?.health.degraded || 0)
    + (summary?.health.failed || 0)
    + (summary?.health.incompatible || 0)
    + (summary?.health.missing_dependency || 0)

  return <>
    <PageHeader
      title="模块中心"
      description="统一读取 Module Registry 的只读库存、运行状态、健康度与能力声明。管理操作仍由各专业 Runtime 页面负责。"
      action={
        <button
          type="button"
          className="button"
          disabled={query.isFetching}
          onClick={() => void query.refetch()}
        >
          <RefreshCw size={15} />
          {query.isFetching ? '刷新中…' : '刷新'}
        </button>
      }
    />

    {query.data ? <SummaryStrip label="模块概览" items={[
      { key:'total', label:'模块总数', value:summary?.total, icon:<Boxes size={15}/> },
      { key:'healthy', label:'健康', value:summary?.health.healthy, tone:'success', icon:<CheckCircle2 size={15}/> },
      { key:'attention', label:'需关注', value:attention, tone:attention > 0 ? 'warning' : 'neutral', icon:<ShieldAlert size={15}/> },
      { key:'disabled', label:'已禁用', value:summary?.health.disabled, icon:<CircleDot size={15}/> },
      { key:'errors', label:'发现错误', value:summary?.discovery_errors, tone:(summary?.discovery_errors || 0) > 0 ? 'danger' : 'neutral', icon:<AlertTriangle size={15}/> },
    ]}/> : null}

    <div className="toolbar">
      <div className="actions">
        <Search size={16} />
        <input
          className="search-input"
          aria-label="搜索模块"
          placeholder="搜索名称、ID、能力…"
          value={search}
          onChange={event => setSearch(event.target.value)}
        />
      </div>
      <select aria-label="模块类型" value={type} onChange={event => setType(event.target.value)}>
        <option value="all">全部类型</option>
        {types.map(value => <option key={value} value={value}>{typeLabel(value)}</option>)}
      </select>
      <select aria-label="模块来源" value={source} onChange={event => setSource(event.target.value)}>
        <option value="all">全部来源</option>
        {sources.map(value => <option key={value} value={value}>{sourceLabel(value)}</option>)}
      </select>
      <select aria-label="健康状态" value={health} onChange={event => setHealth(event.target.value)}>
        <option value="all">全部健康状态</option>
        {healthStates.map(value => <option key={value} value={value}>{healthLabel(value)}</option>)}
      </select>
    </div>

    <QueryFeedback
      loading={query.isFetching && !query.data}
      error={query.isError && !query.data}
      onRetry={() => query.refetch()}
    />

    <div className="form-stack">
      {query.data && rows.length === 0 ? <EmptyState
        className="admin-v2-empty" icon={<Boxes size={22}/>}
        title={modules.length ? '没有符合条件的模块' : 'Registry 暂无模块'}
        description={modules.length ? '请调整搜索或筛选条件。' : '模块清单为空，请确认 Registry 状态后刷新。'}
        action={modules.length ? <button type="button" className="button" onClick={() => {
          setSearch(''); setType('all'); setSource('all'); setHealth('all')
        }}>清除筛选</button> : <button type="button" className="button" onClick={() => void query.refetch()}>刷新列表</button>}
      /> : null}
      {query.data && rows.length ? (
        <section className="admin-v2-module-table">
          <div className="admin-dashboard-card-head">
            <div>
              <h2>统一模块库存</h2>
              <p>显示 {rows.length} / {modules.length} 个模块。数据直接来自 Module Registry。</p>
            </div>
            <span className="admin-v2-muted">只读库存</span>
          </div>
          <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>模块</th>
                <th>类型 / 来源</th>
                <th>版本</th>
                <th>运行状态</th>
                <th>健康</th>
                <th>详情</th>
              </tr>
            </thead>
            <tbody>
              {rows.map(module => <ModuleRow key={module.id} module={module} />)}
            </tbody>
          </table>
          </div>
        </section>
      ) : null}

      {errors.length ? (
        <section className="admin-dashboard-card">
          <div className="admin-dashboard-card-head">
            <div>
              <h2>Discovery errors</h2>
              <p>单个模块发现失败不会阻断其余库存；这里展示 Registry 返回的非敏感诊断。</p>
            </div>
            <span className="badge"><AlertTriangle size={13} /> {errors.length}</span>
          </div>
          <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Adapter</th>
                <th>Module ID</th>
                <th>诊断</th>
              </tr>
            </thead>
            <tbody>
              {errors.map((error, index) => (
                <tr key={`${error.adapter}:${error.module_id || 'unknown'}:${index}`}>
                  <td><code>{error.adapter}</code></td>
                  <td><code>{error.module_id || '-'}</code></td>
                  <td>{error.message}</td>
                </tr>
              ))}
            </tbody>
          </table>
          </div>
        </section>
      ) : null}
    </div>
  </>
}

function ModuleRow({ module }: { module: ModuleDescriptor }) {
  const checks = Object.entries(module.health_details?.checks || {})
  const completed = checks.filter(([, value]) => value === true).length
  return <tr>
    <td><div className="admin-v2-module-name">
      <strong>{module.name}</strong>
      <small>{module.id}{module.description ? ' · ' + module.description : ''}</small>
    </div></td>
    <td><span className="admin-v2-muted">{typeLabel(module.type)} · {sourceLabel(module.source)}</span></td>
    <td><span className="admin-v2-muted">{module.version}</span></td>
    <td><StatusText tone={module.active === true || module.enabled ? 'success' : 'neutral'}>{runtimeState(module)}</StatusText></td>
    <td><div>
      <StatusText tone={module.health === 'healthy' ? 'success' : module.health === 'failed' ? 'danger' : module.health === 'degraded' || module.health === 'incompatible' || module.health === 'missing_dependency' ? 'warning' : 'neutral'}>
        {healthLabel(module.health)}
      </StatusText>
      {checks.length ? <small className="admin-v2-muted" style={{display:'block',marginTop:4}}>{completed} / {checks.length} 项检查通过</small> : null}
    </div></td>
    <td><details className="admin-v2-detail">
      <summary>查看明细</summary>
      <div className="admin-v2-detail-content">
        {module.author ? <div>作者：{module.author}</div> : null}
        <div>兼容性：{module.compatibility.txboard === '*' ? '所有版本' : module.compatibility.txboard}</div>
        <div>能力：{module.capabilities.length ? module.capabilities.join('、') : '无'}</div>
        {module.health_details?.observed_at ? <div>采样时间：{formatEpoch(module.health_details.observed_at)}</div> : null}
        {checks.map(([name, value]) => <div key={name}
          className={'admin-v2-health-check' + (value === false ? ' is-failed' : value === null ? ' is-unknown' : '')}>
          {healthCheckLabel(name)}：{value === true ? '正常' : value === false ? '异常' : '未知'}
        </div>)}
      </div>
    </details></td>
  </tr>
}

function formatEpoch(value: number) {
  const date = new Date(value * 1000)
  return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString()
}

function healthCheckLabel(value: string) {
  return ({
    schedule: 'Scheduler',
    horizon: 'Horizon',
    websocket_server: 'WebSocket',
  } as Record<string, string>)[value] || value.replaceAll('_', ' ')
}

function runtimeState(module: ModuleDescriptor) {
  if (module.active === true) return '当前激活'
  if (module.enabled) return '已启用'
  if (module.installed) return '已安装'
  return '已发现'
}

function typeLabel(value: string) {
  return ({
    core: 'Core',
    plugin: 'Plugin',
    theme: 'Theme',
    integration: 'Integration',
    provider: 'Provider',
    agent: 'Agent',
  } as Record<string, string>)[value] || value
}

function sourceLabel(value: string) {
  return ({
    system: 'System',
    bundled: 'Bundled',
    user: 'User',
    external: 'External',
  } as Record<string, string>)[value] || value
}

function healthLabel(value: string | ModuleHealth) {
  return ({
    healthy: 'Healthy',
    degraded: 'Degraded',
    disabled: 'Disabled',
    failed: 'Failed',
    incompatible: 'Incompatible',
    missing_dependency: 'Missing dependency',
  } as Record<string, string>)[value] || value
}

function unique(values: string[]) {
  return [...new Set(values)].sort()
}
