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

    <div className="dashboard-stats">
      <SummaryCard icon={<Boxes size={18} />} label="模块总数" value={summary?.total} />
      <SummaryCard icon={<CheckCircle2 size={18} />} label="健康" value={summary?.health.healthy} />
      <SummaryCard icon={<ShieldAlert size={18} />} label="需关注" value={attention} />
      <SummaryCard icon={<CircleDot size={18} />} label="Disabled" value={summary?.health.disabled} />
      <SummaryCard icon={<AlertTriangle size={18} />} label="发现错误" value={summary?.discovery_errors} />
    </div>

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
      {query.data ? (
        <section className="admin-dashboard-card">
          <div className="admin-dashboard-card-head">
            <div>
              <h2>统一模块库存</h2>
              <p>显示 {rows.length} / {modules.length} 个模块。数据直接来自 Module Registry。</p>
            </div>
            <span className="badge"><CircleDot size={13} /> Read only</span>
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
                <th>能力</th>
                <th>TXBoard 兼容性</th>
              </tr>
            </thead>
            <tbody>
              {rows.map(module => <ModuleRow key={module.id} module={module} />)}
              {!rows.length ? (
                <tr>
                  <td colSpan={7} className="empty-cell">
                    {modules.length ? '没有符合筛选条件的模块' : 'Registry 当前没有返回模块'}
                  </td>
                </tr>
              ) : null}
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
  return <tr>
    <td>
      <strong>{module.name}</strong>
      <small className="table-sub"><code>{module.id}</code></small>
    </td>
    <td>
      <span className="badge">{typeLabel(module.type)}</span>
      {' '}
      <span className="badge">{sourceLabel(module.source)}</span>
    </td>
    <td><code>{module.version}</code></td>
    <td><span className={runtimeStateClass(module)}>{runtimeState(module)}</span></td>
    <td><span className={healthClass(module.health)}>{healthLabel(module.health)}</span></td>
    <td>
      <div className="actions">
        {module.capabilities.length
          ? module.capabilities.map(capability => <span className="badge" key={capability}>{capability}</span>)
          : <span className="text-muted">-</span>}
      </div>
    </td>
    <td><code>{module.compatibility.txboard}</code></td>
  </tr>
}

function SummaryCard({
  icon,
  label,
  value,
}: {
  icon: React.ReactNode
  label: string
  value?: number
}) {
  return <div className="admin-stat-card">
    <div className="admin-stat-card-head">
      <span>{label}</span>
      <span className="admin-stat-icon">{icon}</span>
    </div>
    <strong>{value ?? '—'}</strong>
  </div>
}

function runtimeState(module: ModuleDescriptor) {
  if (module.active === true) return '当前激活'
  if (module.enabled) return '已启用'
  if (module.installed) return '已安装'
  return '已发现'
}

function runtimeStateClass(module: ModuleDescriptor) {
  if (module.active === true || module.enabled) return 'status ok'
  if (module.installed) return 'badge'
  return 'status off'
}

function healthClass(value: ModuleHealth) {
  if (value === 'healthy') return 'status ok'
  if (value === 'disabled') return 'status off'
  if (value === 'failed') return 'badge fleet-critical'
  return 'badge fleet-degraded'
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
