import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BarChart3, Copy, Gift, Pencil, Plus, RefreshCw, Sparkles, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { toast } from 'sonner'
import { getPlans } from '../../api/finance'
import {
  createGiftTemplate,
  deleteGiftCode,
  deleteGiftTemplate,
  generateGiftCodes,
  getGiftCodes,
  getGiftStatistics,
  getGiftTemplates,
  getGiftTypes,
  getGiftUsages,
  toggleGiftCode,
  updateGiftTemplate,
  type GiftCode,
  type GiftTemplate,
} from '../../api/gift-card'
import { JsonEditor } from '../../components/ui/JsonEditor'
import { Modal } from '../../components/ui/Modal'
import { PageHeader } from '../../components/ui/PageHeader'

const GB = 1024 * 1024 * 1024

type TemplateDraft = {
  name: string
  description: string
  type: number
  status: boolean
  sort: number
  balance: string
  trafficGb: string
  expireDays: string
  deviceLimit: string
  resetPackage: boolean
  planId: string
  planValidityDays: string
  randomRewards: Array<{ weight: number; balance?: number; transfer_enable?: number }>
  conditions: Record<string, unknown>
  limits: Record<string, unknown>
  special_config: Record<string, unknown>
  icon: string
  background_image: string
  theme_color: string
}

function emptyTemplate(): TemplateDraft {
  return {
    name: '',
    description: '',
    type: 1,
    status: true,
    sort: 0,
    balance: '',
    trafficGb: '',
    expireDays: '',
    deviceLimit: '',
    resetPackage: false,
    planId: '',
    planValidityDays: '',
    randomRewards: [{ weight: 100, balance: 1 }],
    conditions: {},
    limits: {},
    special_config: {},
    icon: '',
    background_image: '',
    theme_color: '#1890ff',
  }
}

export function GiftCardPage() {
  const [tab, setTab] = useState<'templates' | 'codes' | 'usages' | 'stats'>('templates')

  return <>
    <PageHeader title="礼品卡" description="模板、兑换码、使用记录和统计。"/>
    <div className="tabs gift-tabs">
      <Tab active={tab === 'templates'} onClick={() => setTab('templates')}>模板</Tab>
      <Tab active={tab === 'codes'} onClick={() => setTab('codes')}>兑换码</Tab>
      <Tab active={tab === 'usages'} onClick={() => setTab('usages')}>使用记录</Tab>
      <Tab active={tab === 'stats'} onClick={() => setTab('stats')}>统计</Tab>
    </div>

    {tab === 'templates' && <TemplatesPanel onGenerated={() => setTab('codes')}/>}
    {tab === 'codes' && <CodesPanel/>}
    {tab === 'usages' && <UsagesPanel/>}
    {tab === 'stats' && <StatsPanel/>}
  </>
}

function TemplatesPanel({ onGenerated }: { onGenerated: () => void }) {
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [editorOpen, setEditorOpen] = useState(false)
  const [editing, setEditing] = useState<GiftTemplate | null>(null)
  const [generateTemplate, setGenerateTemplate] = useState<GiftTemplate | null>(null)

  const query = useQuery({
    queryKey: ['giftTemplates', page],
    queryFn: () => getGiftTemplates({ page, per_page: 20 }),
  })
  const typesQuery = useQuery({ queryKey: ['giftTypes'], queryFn: getGiftTypes })
  const plansQuery = useQuery({ queryKey: ['plans'], queryFn: getPlans })

  const remove = useMutation({
    mutationFn: deleteGiftTemplate,
    onSuccess: () => {
      toast.success('模板已删除')
      qc.invalidateQueries({ queryKey: ['giftTemplates'] })
    },
  })

  const rows = query.data?.data || []
  const plans = Array.isArray(plansQuery.data) ? plansQuery.data : []

  return <>
    <div className="panel-toolbar">
      <button className="button primary" onClick={() => { setEditing(null); setEditorOpen(true) }}>
        <Plus size={15}/>新建模板
      </button>
      <button className="button" onClick={() => query.refetch()}><RefreshCw size={15}/>刷新</button>
    </div>

    <div className="gift-template-grid">
      {rows.map(template => <article className="gift-template-card" key={template.id}>
        <div className="gift-template-visual" style={{ background: template.theme_color || '#1890ff' }}>
          <Gift size={28}/>
          <span>{template.type_name || (typesQuery.data?.[String(template.type)] ?? ('类型 ' + template.type))}</span>
        </div>
        <div className="gift-template-body">
          <div className="gift-template-title">
            <div><h3>{template.name}</h3><p>{template.description || '暂无描述'}</p></div>
            <span className={template.status ? 'status ok' : 'status off'}>{template.status ? '启用' : '停用'}</span>
          </div>
          <RewardSummary template={template}/>
          <div className="gift-template-meta">
            <span>兑换码 {template.codes_count || 0}</span>
            <span>已使用 {template.used_count || 0}</span>
            <span>排序 {template.sort || 0}</span>
          </div>
          <div className="card-actions">
            <button className="button" onClick={() => setGenerateTemplate(template)}><Sparkles size={14}/>生成兑换码</button>
            <button className="icon-button" title="编辑" onClick={() => { setEditing(template); setEditorOpen(true) }}><Pencil size={15}/></button>
            <button
              className="icon-button danger"
              title="删除"
              disabled={Number(template.codes_count || 0) > 0}
              onClick={() => confirm('只有没有兑换码的模板可以删除。确认删除？') && remove.mutate(template.id)}
            ><Trash2 size={15}/></button>
          </div>
        </div>
      </article>)}
      {!rows.length && <div className="card empty-state">暂无礼品卡模板。</div>}
    </div>

    <Pager page={page} last={query.data?.last_page || 1} total={query.data?.total || 0} onPage={setPage}/>

    <TemplateEditor
      open={editorOpen}
      template={editing}
      plans={plans}
      types={typesQuery.data || {}}
      onClose={() => setEditorOpen(false)}
      onSaved={() => qc.invalidateQueries({ queryKey: ['giftTemplates'] })}
    />
    <GenerateCodesModal
      template={generateTemplate}
      onClose={() => setGenerateTemplate(null)}
      onGenerated={() => {
        qc.invalidateQueries({ queryKey: ['giftTemplates'] })
        qc.invalidateQueries({ queryKey: ['giftCodes'] })
        setGenerateTemplate(null)
        onGenerated()
      }}
    />
  </>
}

function TemplateEditor({
  open,
  template,
  plans,
  types,
  onClose,
  onSaved,
}: {
  open: boolean
  template: GiftTemplate | null
  plans: Array<{ id: number; name: string }>
  types: Record<string, string>
  onClose: () => void
  onSaved: () => void
}) {
  const [draft, setDraft] = useState<TemplateDraft>(emptyTemplate())

  useEffect(() => {
    if (!open) return
    if (!template) {
      setDraft(emptyTemplate())
      return
    }
    const rewards = template.rewards || {}
    setDraft({
      name: template.name || '',
      description: template.description || '',
      type: Number(template.type || 1),
      status: Boolean(template.status),
      sort: Number(template.sort || 0),
      balance: rewards.balance == null ? '' : String(Number(rewards.balance) / 100),
      trafficGb: rewards.transfer_enable == null ? '' : String(Number(rewards.transfer_enable) / GB),
      expireDays: rewards.expire_days == null ? '' : String(rewards.expire_days),
      deviceLimit: rewards.device_limit == null ? '' : String(rewards.device_limit),
      resetPackage: Boolean(rewards.reset_package),
      planId: rewards.plan_id == null ? '' : String(rewards.plan_id),
      planValidityDays: rewards.plan_validity_days == null ? '' : String(rewards.plan_validity_days),
      randomRewards: Array.isArray(rewards.random_rewards)
        ? rewards.random_rewards.map((item: any) => ({
            weight: Number(item.weight || 1),
            balance: item.balance == null ? undefined : Number(item.balance) / 100,
            transfer_enable: item.transfer_enable == null ? undefined : Number(item.transfer_enable) / GB,
          }))
        : [{ weight: 100, balance: 1 }],
      conditions: template.conditions || {},
      limits: template.limits || {},
      special_config: template.special_config || {},
      icon: template.icon || '',
      background_image: template.background_image || '',
      theme_color: template.theme_color || '#1890ff',
    })
  }, [open, template])

  const mutation = useMutation({
    mutationFn: async () => {
      const rewards: Record<string, unknown> = {}
      if (draft.type === 1) {
        if (Number(draft.balance) > 0) rewards.balance = Math.round(Number(draft.balance) * 100)
        if (Number(draft.trafficGb) > 0) rewards.transfer_enable = Math.round(Number(draft.trafficGb) * GB)
        if (Number(draft.expireDays) > 0) rewards.expire_days = Number(draft.expireDays)
        if (Number(draft.deviceLimit) > 0) rewards.device_limit = Number(draft.deviceLimit)
        if (draft.resetPackage) rewards.reset_package = true
      } else if (draft.type === 2) {
        rewards.plan_id = Number(draft.planId)
        if (Number(draft.planValidityDays) > 0) rewards.plan_validity_days = Number(draft.planValidityDays)
      } else {
        rewards.random_rewards = draft.randomRewards.map(item => ({
          weight: Number(item.weight || 1),
          ...(Number(item.balance || 0) > 0 ? { balance: Math.round(Number(item.balance) * 100) } : {}),
          ...(Number(item.transfer_enable || 0) > 0 ? { transfer_enable: Math.round(Number(item.transfer_enable) * GB) } : {}),
        }))
      }

      const payload = {
        name: draft.name.trim(),
        description: draft.description || null,
        type: draft.type,
        status: draft.status,
        sort: draft.sort,
        rewards,
        conditions: draft.conditions,
        limits: draft.limits,
        special_config: draft.special_config,
        icon: draft.icon || null,
        background_image: draft.background_image || null,
        theme_color: draft.theme_color || '#1890ff',
      }

      if (template) return updateGiftTemplate(template.id, payload)
      return createGiftTemplate(payload as any)
    },
    onSuccess: () => {
      toast.success(template ? '模板已更新' : '模板已创建')
      onSaved()
      onClose()
    },
  })

  function save() {
    if (!draft.name.trim()) return toast.error('请输入模板名称')
    if (draft.type === 2 && !draft.planId) return toast.error('套餐奖励必须选择套餐')
    if (draft.type === 3 && !draft.randomRewards.length) return toast.error('随机奖励池不能为空')
    mutation.mutate()
  }

  return <Modal open={open} title={template ? '编辑礼品卡模板' : '新建礼品卡模板'} onClose={onClose}>
    <div className="gift-template-editor">
      <div className="settings-grid">
        <Field label="模板名称 *"><input value={draft.name} onChange={e => setDraft(v => ({ ...v, name: e.target.value }))}/></Field>
        <Field label="类型"><select value={draft.type} onChange={e => setDraft(v => ({ ...v, type: Number(e.target.value) }))}>
          {Object.keys(types).length
            ? Object.entries(types).map(([id, label]) => <option key={id} value={id}>{label}</option>)
            : <><option value={1}>固定奖励</option><option value={2}>套餐奖励</option><option value={3}>随机奖励</option></>}
        </select></Field>
        <Field label="排序"><input type="number" min="0" value={draft.sort} onChange={e => setDraft(v => ({ ...v, sort: Number(e.target.value) }))}/></Field>
        <Field label="主题色"><input type="color" value={draft.theme_color} onChange={e => setDraft(v => ({ ...v, theme_color: e.target.value }))}/></Field>
        <label className="field settings-field-wide"><span>描述</span><textarea value={draft.description} onChange={e => setDraft(v => ({ ...v, description: e.target.value }))}/></label>
      </div>

      <section className="gift-editor-section">
        <h4>奖励配置</h4>
        {draft.type === 1 && <div className="settings-grid">
          <Field label="余额奖励（元）"><input type="number" min="0" step="0.01" value={draft.balance} onChange={e => setDraft(v => ({ ...v, balance: e.target.value }))}/></Field>
          <Field label="流量奖励（GB）"><input type="number" min="0" step="0.1" value={draft.trafficGb} onChange={e => setDraft(v => ({ ...v, trafficGb: e.target.value }))}/></Field>
          <Field label="延长有效期（天）"><input type="number" min="0" value={draft.expireDays} onChange={e => setDraft(v => ({ ...v, expireDays: e.target.value }))}/></Field>
          <Field label="设备限制"><input type="number" min="0" value={draft.deviceLimit} onChange={e => setDraft(v => ({ ...v, deviceLimit: e.target.value }))}/></Field>
          <label className="check-field"><input type="checkbox" checked={draft.resetPackage} onChange={e => setDraft(v => ({ ...v, resetPackage: e.target.checked }))}/><span>重置套餐流量</span></label>
        </div>}
        {draft.type === 2 && <div className="settings-grid">
          <Field label="奖励套餐 *"><select value={draft.planId} onChange={e => setDraft(v => ({ ...v, planId: e.target.value }))}>
            <option value="">请选择套餐</option>{plans.map(plan => <option key={plan.id} value={plan.id}>{plan.name}</option>)}
          </select></Field>
          <Field label="套餐有效期（天）"><input type="number" min="1" value={draft.planValidityDays} onChange={e => setDraft(v => ({ ...v, planValidityDays: e.target.value }))}/></Field>
        </div>}
        {draft.type === 3 && <RandomRewards value={draft.randomRewards} onChange={randomRewards => setDraft(v => ({ ...v, randomRewards }))}/>}
      </section>

      <section className="gift-editor-section">
        <h4>使用条件</h4>
        <ConditionSwitch label="仅新用户" value={Boolean(draft.conditions.new_user_only)} onChange={value => setDraft(v => ({ ...v, conditions: { ...v.conditions, new_user_only: value } }))}/>
        <ConditionSwitch label="仅付费用户" value={Boolean(draft.conditions.paid_user_only)} onChange={value => setDraft(v => ({ ...v, conditions: { ...v.conditions, paid_user_only: value } }))}/>
        <ConditionSwitch label="必须存在邀请关系" value={Boolean(draft.conditions.require_invite)} onChange={value => setDraft(v => ({ ...v, conditions: { ...v.conditions, require_invite: value } }))}/>
      </section>

      <section className="gift-editor-section">
        <h4>高级配置</h4>
        <div className="two-col">
          <div><div className="subtle-label">限制（JSON）</div><JsonEditor value={draft.limits} onChange={limits => setDraft(v => ({ ...v, limits }))}/></div>
          <div><div className="subtle-label">活动配置（JSON）</div><JsonEditor value={draft.special_config} onChange={special_config => setDraft(v => ({ ...v, special_config }))}/></div>
        </div>
      </section>

      <div className="settings-grid">
        <Field label="图标"><input value={draft.icon} onChange={e => setDraft(v => ({ ...v, icon: e.target.value }))}/></Field>
        <Field label="背景图 URL"><input value={draft.background_image} onChange={e => setDraft(v => ({ ...v, background_image: e.target.value }))}/></Field>
        <label className="check-field settings-field-wide"><input type="checkbox" checked={draft.status} onChange={e => setDraft(v => ({ ...v, status: e.target.checked }))}/><span>启用模板</span></label>
      </div>

      <div className="card-actions">
        <button className="button" onClick={onClose}>取消</button>
        <button className="button primary" disabled={mutation.isPending} onClick={save}>{mutation.isPending ? '保存中…' : '保存模板'}</button>
      </div>
    </div>
  </Modal>
}

function GenerateCodesModal({
  template,
  onClose,
  onGenerated,
}: {
  template: GiftTemplate | null
  onClose: () => void
  onGenerated: () => void
}) {
  const [count, setCount] = useState('10')
  const [prefix, setPrefix] = useState('GC')
  const [expires, setExpires] = useState('')
  const [maxUsage, setMaxUsage] = useState('1')

  useEffect(() => {
    if (template) {
      setCount('10')
      setPrefix('GC')
      setExpires('')
      setMaxUsage('1')
    }
  }, [template])

  const mutation = useMutation({
    mutationFn: () => generateGiftCodes({
      template_id: template!.id,
      count: Math.min(10000, Math.max(1, Number(count))),
      prefix: prefix.toUpperCase(),
      ...(Number(expires) > 0 ? { expires_hours: Number(expires) } : {}),
      max_usage: Math.max(1, Number(maxUsage)),
    }),
    onSuccess: data => {
      toast.success('已生成 ' + (data?.count || count) + ' 个兑换码')
      onGenerated()
    },
  })

  return <Modal open={Boolean(template)} title={template ? '生成兑换码 · ' + template.name : '生成兑换码'} onClose={onClose}>
    <div className="form-stack">
      <Field label="生成数量"><input type="number" min="1" max="10000" value={count} onChange={e => setCount(e.target.value)}/></Field>
      <Field label="前缀"><input maxLength={10} value={prefix} onChange={e => setPrefix(e.target.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase())}/></Field>
      <Field label="过期小时数"><input type="number" min="1" value={expires} placeholder="留空 = 永不过期" onChange={e => setExpires(e.target.value)}/></Field>
      <Field label="最大使用次数"><input type="number" min="1" max="1000" value={maxUsage} onChange={e => setMaxUsage(e.target.value)}/></Field>
      <button className="button primary" disabled={mutation.isPending} onClick={() => mutation.mutate()}>
        <Sparkles size={15}/>{mutation.isPending ? '生成中…' : '生成兑换码'}
      </button>
    </div>
  </Modal>
}

function CodesPanel() {
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [status, setStatus] = useState('')
  const query = useQuery({
    queryKey: ['giftCodes', page, status],
    queryFn: () => getGiftCodes({ page, per_page: 20, ...(status !== '' ? { status: Number(status) } : {}) }),
  })

  const toggle = useMutation({
    mutationFn: ({ id, action }: { id: number; action: 'disable' | 'enable' }) => toggleGiftCode(id, action),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['giftCodes'] }),
  })
  const remove = useMutation({
    mutationFn: deleteGiftCode,
    onSuccess: () => {
      toast.success('兑换码已删除')
      qc.invalidateQueries({ queryKey: ['giftCodes'] })
    },
  })

  const rows = query.data?.data || []
  return <>
    <div className="panel-toolbar">
      <select value={status} onChange={e => { setStatus(e.target.value); setPage(1) }}>
        <option value="">全部状态</option>
        <option value={0}>未使用</option>
        <option value={1}>已使用</option>
        <option value={2}>已过期</option>
        <option value={3}>已禁用</option>
      </select>
      <button className="button" onClick={() => query.refetch()}><RefreshCw size={15}/>刷新</button>
    </div>
    <div className="card gift-table-card"><div className="table-wrap"><table className="data-table">
      <thead><tr><th>兑换码</th><th>模板</th><th>状态</th><th>使用次数</th><th>用户</th><th>过期</th><th>批次</th><th>操作</th></tr></thead>
      <tbody>{rows.map(code => <tr key={code.id}>
        <td><code>{code.code}</code><button className="mini-copy" onClick={() => copyText(code.code || '')}><Copy size={12}/></button></td>
        <td>{code.template_name || '-'}</td>
        <td><span className={code.status === 0 ? 'status ok' : 'status off'}>{code.status_name || giftCodeStatus(code.status)}</span></td>
        <td>{code.usage_count || 0} / {code.max_usage || 1}</td>
        <td>{code.user_email || '-'}</td>
        <td>{formatTime(code.expires_at, true)}</td>
        <td><code className="small-code">{code.batch_id || '-'}</code></td>
        <td><div className="actions">
          {(code.status === 0 || code.status === 3) && <button className="button compact-button" onClick={() => toggle.mutate({ id: code.id, action: code.status === 3 ? 'enable' : 'disable' })}>{code.status === 3 ? '启用' : '禁用'}</button>}
          {code.status !== 1 && <button className="icon-button danger" onClick={() => confirm('确认删除该兑换码？') && remove.mutate(code.id)}><Trash2 size={14}/></button>}
        </div></td>
      </tr>)}
      {!rows.length && <tr><td colSpan={8} className="empty-cell">{query.isLoading ? '加载中…' : '暂无兑换码'}</td></tr>}
      </tbody>
    </table></div></div>
    <Pager page={page} last={query.data?.last_page || 1} total={query.data?.total || 0} onPage={setPage}/>
  </>
}

function UsagesPanel() {
  const [page, setPage] = useState(1)
  const query = useQuery({
    queryKey: ['giftUsages', page],
    queryFn: () => getGiftUsages({ page, per_page: 20 }),
  })
  const rows = query.data?.data || []
  return <>
    <div className="card gift-table-card"><div className="table-wrap"><table className="data-table">
      <thead><tr><th>ID</th><th>兑换码</th><th>模板</th><th>用户</th><th>邀请人</th><th>奖励</th><th>时间</th></tr></thead>
      <tbody>{rows.map(row => <tr key={row.id}>
        <td>{row.id}</td><td><code>{row.code || '-'}</code></td><td>{row.template_name || '-'}</td><td>{row.user_email || '-'}</td><td>{row.invite_user_email || '-'}</td>
        <td><code className="json-summary">{summaryJson(row.rewards_given)}</code></td><td>{formatTime(row.created_at)}</td>
      </tr>)}
      {!rows.length && <tr><td colSpan={7} className="empty-cell">{query.isLoading ? '加载中…' : '暂无使用记录'}</td></tr>}
      </tbody>
    </table></div></div>
    <Pager page={page} last={query.data?.last_page || 1} total={query.data?.total || 0} onPage={setPage}/>
  </>
}

function StatsPanel() {
  const [start, setStart] = useState(() => dateOffset(-30))
  const [end, setEnd] = useState(() => dateOffset(0))
  const query = useQuery({
    queryKey: ['giftStats', start, end],
    queryFn: () => getGiftStatistics(start, end),
  })
  const stats = query.data?.total_stats || {}
  const daily = query.data?.daily_usages || []
  const typeStats = query.data?.type_stats || []

  return <div className="gift-stats">
    <div className="panel-toolbar">
      <input type="date" value={start} onChange={e => setStart(e.target.value)}/>
      <span>至</span>
      <input type="date" value={end} onChange={e => setEnd(e.target.value)}/>
      <button className="button" onClick={() => query.refetch()}><BarChart3 size={15}/>刷新统计</button>
    </div>
    <div className="stats-cards">
      <StatCard label="模板总数" value={stats.templates_count}/>
      <StatCard label="启用模板" value={stats.active_templates_count}/>
      <StatCard label="兑换码总数" value={stats.codes_count}/>
      <StatCard label="已使用兑换码" value={stats.used_codes_count}/>
      <StatCard label="使用记录" value={stats.usages_count}/>
    </div>
    <div className="two-col">
      <section className="card chart-card">
        <h3>每日使用量</h3>
        <ResponsiveContainer width="100%" height={300}>
          <BarChart data={daily}><CartesianGrid strokeDasharray="3 3" vertical={false}/><XAxis dataKey="date" tick={{fontSize:10}}/><YAxis allowDecimals={false}/><Tooltip/><Bar dataKey="count" fill="#2563eb"/></BarChart>
        </ResponsiveContainer>
      </section>
      <section className="card">
        <h3>模板使用排行</h3>
        <div className="rank-list">{typeStats.map((item, index) => <div key={(item.template_name || '') + index}><span>{item.template_name || item.type_name || '-'}</span><strong>{item.count || 0}</strong></div>)}</div>
      </section>
    </div>
  </div>
}

function RandomRewards({ value, onChange }: { value: TemplateDraft['randomRewards']; onChange: (value: TemplateDraft['randomRewards']) => void }) {
  return <div className="random-rewards">
    {value.map((item, index) => <div className="random-reward-row" key={index}>
      <Field label="权重"><input type="number" min="1" value={item.weight} onChange={e => update(index, { weight: Number(e.target.value) })}/></Field>
      <Field label="余额（元）"><input type="number" min="0" step="0.01" value={item.balance ?? ''} onChange={e => update(index, { balance: e.target.value === '' ? undefined : Number(e.target.value) })}/></Field>
      <Field label="流量（GB）"><input type="number" min="0" step="0.1" value={item.transfer_enable ?? ''} onChange={e => update(index, { transfer_enable: e.target.value === '' ? undefined : Number(e.target.value) })}/></Field>
      <button className="icon-button danger" disabled={value.length <= 1} onClick={() => onChange(value.filter((_, i) => i !== index))}><Trash2 size={14}/></button>
    </div>)}
    <button className="button" onClick={() => onChange([...value, { weight: 1, balance: 1 }])}><Plus size={14}/>添加奖励项</button>
  </div>
  function update(index: number, patch: Partial<TemplateDraft['randomRewards'][number]>) {
    onChange(value.map((item, i) => i === index ? { ...item, ...patch } : item))
  }
}

function RewardSummary({ template }: { template: GiftTemplate }) {
  const r = template.rewards || {}
  const parts: string[] = []
  if (r.balance) parts.push('¥ ' + (Number(r.balance) / 100).toFixed(2))
  if (r.transfer_enable) parts.push((Number(r.transfer_enable) / GB).toFixed(1) + ' GB')
  if (r.plan_id) parts.push('套餐 #' + r.plan_id)
  if (Array.isArray(r.random_rewards)) parts.push('随机池 ' + r.random_rewards.length + ' 项')
  return <div className="reward-summary">{parts.length ? parts.join(' · ') : '未配置奖励摘要'}</div>
}

function ConditionSwitch({ label, value, onChange }: { label: string; value: boolean; onChange: (value: boolean) => void }) {
  return <label className="check-field"><input type="checkbox" checked={value} onChange={e => onChange(e.target.checked)}/><span>{label}</span></label>
}
function Field({ label, children }: { label: string; children: React.ReactNode }) { return <label className="field"><span>{label}</span>{children}</label> }
function Tab({ active, children, onClick }: { active: boolean; children: React.ReactNode; onClick: () => void }) { return <button className={active ? 'tab active' : 'tab'} onClick={onClick}>{children}</button> }
function Pager({ page, last, total, onPage }: { page: number; last: number; total: number; onPage: (page: number) => void }) {
  return <div className="pagination-bar standalone-pager"><span className="pagination-meta">共 {total} 条 · 第 {page} / {last} 页</span><div className="pagination-actions"><button className="button" disabled={page <= 1} onClick={() => onPage(page - 1)}>上一页</button><button className="button" disabled={page >= last} onClick={() => onPage(page + 1)}>下一页</button></div></div>
}
function StatCard({ label, value }: { label: string; value?: number }) { return <div className="stat-card"><span>{label}</span><strong>{Number(value || 0).toLocaleString()}</strong></div> }
function giftCodeStatus(status?: number) { return ({0:'未使用',1:'已使用',2:'已过期',3:'已禁用'} as Record<number,string>)[Number(status)] || '-' }
function formatTime(value?: number | null, dateOnly=false) { if (!value) return '永不过期'; const d=new Date(value*1000); return dateOnly?d.toLocaleDateString():d.toLocaleString() }
function summaryJson(value: unknown) { try { const s=JSON.stringify(value || {}); return s.length>90?s.slice(0,87)+'…':s } catch { return '-' } }
function dateOffset(days:number) { const d=new Date(); d.setDate(d.getDate()+days); return d.toISOString().slice(0,10) }
async function copyText(value:string) { try { await navigator.clipboard.writeText(value); toast.success('已复制') } catch { toast.error('复制失败') } }
