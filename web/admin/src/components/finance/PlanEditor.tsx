import { useMutation } from '@tanstack/react-query'
import { useEffect, useMemo, useState } from 'react'
import { Save, WandSparkles } from 'lucide-react'
import { toast } from 'sonner'
import { savePlan, type PlanItem, type PlanPrices, type PlanSavePayload } from '../../api/finance'
import type { GroupItem } from '../../api/server'
import { Modal } from '../ui/Modal'

const periods = [
  ['monthly', '月付'],
  ['quarterly', '季付'],
  ['half_yearly', '半年付'],
  ['yearly', '年付'],
  ['two_yearly', '两年付'],
  ['three_yearly', '三年付'],
  ['onetime', '一次性'],
  ['reset_traffic', '重置流量'],
] as const

type Draft = {
  name: string
  content: string
  group_id: string
  transfer_enable: string
  speed_limit: string
  device_limit: string
  capacity_limit: string
  reset_traffic_method: string
  tags: string
  prices: Record<string, string>
  force_update: boolean
}

function emptyDraft(): Draft {
  return {
    name: '',
    content: '',
    group_id: '',
    transfer_enable: '100',
    speed_limit: '',
    device_limit: '',
    capacity_limit: '',
    reset_traffic_method: '',
    tags: '',
    prices: Object.fromEntries(periods.map(([key]) => [key, ''])),
    force_update: false,
  }
}

function toDraft(plan?: PlanItem | null): Draft {
  if (!plan) return emptyDraft()
  return {
    name: plan.name || '',
    content: plan.content || '',
    group_id: plan.group_id == null ? '' : String(plan.group_id),
    transfer_enable: String(plan.transfer_enable ?? ''),
    speed_limit: plan.speed_limit == null ? '' : String(plan.speed_limit),
    device_limit: plan.device_limit == null ? '' : String(plan.device_limit),
    capacity_limit: plan.capacity_limit == null ? '' : String(plan.capacity_limit),
    reset_traffic_method: plan.reset_traffic_method == null ? '' : String(plan.reset_traffic_method),
    tags: Array.isArray(plan.tags) ? plan.tags.join(', ') : '',
    prices: Object.fromEntries(periods.map(([key]) => [key, plan.prices?.[key] == null ? '' : String(plan.prices[key])])),
    force_update: false,
  }
}

function nullableNumber(value: string) {
  if (value.trim() === '') return null
  const n = Number(value)
  return Number.isFinite(n) ? n : null
}

export function PlanEditor({
  open,
  plan,
  groups,
  onClose,
  onSaved,
}: {
  open: boolean
  plan?: PlanItem | null
  groups: GroupItem[]
  onClose: () => void
  onSaved: () => void
}) {
  const [draft, setDraft] = useState<Draft>(() => toDraft(plan))
  const [preview, setPreview] = useState(false)

  useEffect(() => {
    if (open) setDraft(toDraft(plan))
  }, [open, plan])

  const mutation = useMutation({
    mutationFn: (payload: PlanSavePayload) => savePlan(payload),
    onSuccess: () => {
      toast.success(plan ? '套餐已更新' : '套餐已创建')
      onSaved()
      onClose()
    },
  })

  const activePrices = useMemo(
    () => periods.filter(([key]) => draft.prices[key] !== ''),
    [draft.prices],
  )

  function patch<K extends keyof Draft>(key: K, value: Draft[K]) {
    setDraft(current => ({ ...current, [key]: value }))
  }

  function patchPrice(key: string, value: string) {
    setDraft(current => ({ ...current, prices: { ...current.prices, [key]: value } }))
  }

  function applyQuickPrices() {
    const monthly = Number(draft.prices.monthly)
    if (!Number.isFinite(monthly) || monthly <= 0) {
      toast.error('请先填写月付价格')
      return
    }
    const multipliers: Record<string, number> = {
      quarterly: 3 * 0.95,
      half_yearly: 6 * 0.9,
      yearly: 12 * 0.85,
      two_yearly: 24 * 0.8,
      three_yearly: 36 * 0.75,
    }
    setDraft(current => ({
      ...current,
      prices: {
        ...current.prices,
        ...Object.fromEntries(Object.entries(multipliers).map(([key, factor]) => [key, (monthly * factor).toFixed(2)])),
      },
    }))
  }

  function submit() {
    const transfer = Number(draft.transfer_enable)
    if (!draft.name.trim()) {
      toast.error('请输入套餐名称')
      return
    }
    if (!Number.isInteger(transfer) || transfer < 1) {
      toast.error('流量配额必须是大于 0 的整数')
      return
    }

    const prices: PlanPrices = {}
    for (const [key] of periods) {
      const raw = draft.prices[key]
      if (raw === '') continue
      const n = Number(raw)
      if (Number.isFinite(n) && n > 0) prices[key] = Number(n.toFixed(2))
    }

    mutation.mutate({
      ...(plan ? { id: plan.id } : {}),
      name: draft.name.trim(),
      content: draft.content || null,
      group_id: nullableNumber(draft.group_id),
      transfer_enable: transfer,
      speed_limit: nullableNumber(draft.speed_limit),
      device_limit: nullableNumber(draft.device_limit),
      capacity_limit: nullableNumber(draft.capacity_limit),
      reset_traffic_method: nullableNumber(draft.reset_traffic_method),
      prices,
      tags: draft.tags.split(',').map(item => item.trim()).filter(Boolean),
      ...(plan && draft.force_update ? { force_update: true } : {}),
    })
  }

  return <Modal open={open} title={plan ? `编辑套餐：${plan.name}` : '添加套餐'} onClose={onClose}>
    <div className="plan-editor">
      <div className="plan-editor-grid">
        <label className="field">
          <span>套餐名称 *</span>
          <input value={draft.name} onChange={e => patch('name', e.target.value)} placeholder="Pro 100G"/>
        </label>
        <label className="field">
          <span>权限组</span>
          <select value={draft.group_id} onChange={e => patch('group_id', e.target.value)}>
            <option value="">不指定</option>
            {groups.map(group => <option key={group.id} value={group.id}>{group.name || `Group ${group.id}`}</option>)}
          </select>
        </label>
        <label className="field">
          <span>流量配额（GB）*</span>
          <input type="number" min="1" step="1" value={draft.transfer_enable} onChange={e => patch('transfer_enable', e.target.value)}/>
        </label>
        <label className="field">
          <span>限速（Mbps）</span>
          <input type="number" min="0" value={draft.speed_limit} onChange={e => patch('speed_limit', e.target.value)} placeholder="留空 = 不限制"/>
        </label>
        <label className="field">
          <span>设备限制</span>
          <input type="number" min="0" value={draft.device_limit} onChange={e => patch('device_limit', e.target.value)} placeholder="留空 = 不限制"/>
        </label>
        <label className="field">
          <span>人数上限</span>
          <input type="number" min="0" value={draft.capacity_limit} onChange={e => patch('capacity_limit', e.target.value)} placeholder="0 / 留空 = 不限制"/>
        </label>
        <label className="field">
          <span>流量重置方式</span>
          <select value={draft.reset_traffic_method} onChange={e => patch('reset_traffic_method', e.target.value)}>
            <option value="">跟随系统设置</option>
            <option value="0">每月 1 号</option>
            <option value="1">按月重置</option>
            <option value="2">不重置</option>
            <option value="3">每年 1 月 1 日</option>
            <option value="4">按年重置</option>
          </select>
        </label>
        <label className="field">
          <span>标签</span>
          <input value={draft.tags} onChange={e => patch('tags', e.target.value)} placeholder="热门, 推荐, 高速"/>
        </label>
      </div>

      <section className="plan-editor-section">
        <div className="section-row">
          <div><strong>周期价格</strong><small>单位：元；留空表示不提供该周期</small></div>
          <button className="button" type="button" onClick={applyQuickPrices}><WandSparkles size={15}/>按月价生成折扣价</button>
        </div>
        <div className="price-grid">
          {periods.map(([key, label]) => <label className="field" key={key}>
            <span>{label}</span>
            <input type="number" min="0" step="0.01" value={draft.prices[key]} onChange={e => patchPrice(key, e.target.value)} placeholder="未启用"/>
          </label>)}
        </div>
        <div className="price-summary">{activePrices.length ? `已启用 ${activePrices.length} 个计费周期` : '尚未配置价格'}</div>
      </section>

      <section className="plan-editor-section">
        <div className="section-row">
          <div><strong>套餐描述</strong><small>{'支持 Markdown / 模板变量：{{transfer}}、{{speed}}、{{devices}}、{{reset_method}}'}</small></div>
          <button className="button" type="button" onClick={() => setPreview(value => !value)}>{preview ? '继续编辑' : '预览'}</button>
        </div>
        {preview
          ? <MarkdownLite content={draft.content}/>
          : <textarea className="plan-content-editor" value={draft.content} onChange={e => patch('content', e.target.value)} placeholder={"# 套餐说明\n\n- 每月 {{transfer}} GB\n- 最高 {{speed}} Mbps"}/>}
      </section>

      {plan && <label className="check-field force-update">
        <input type="checkbox" checked={draft.force_update} onChange={e => patch('force_update', e.target.checked)}/>
        <span><strong>同步更新当前套餐用户</strong><small>会同步用户的权限组、流量、限速和设备限制。</small></span>
      </label>}

      <div className="card-actions">
        <button className="button" onClick={onClose}>取消</button>
        <button className="button primary" disabled={mutation.isPending} onClick={submit}><Save size={15}/>{mutation.isPending ? '保存中…' : '保存套餐'}</button>
      </div>
    </div>
  </Modal>
}

function MarkdownLite({ content }: { content: string }) {
  if (!content.trim()) return <div className="markdown-preview muted">暂无内容</div>
  return <div className="markdown-preview">
    {content.split('\n').map((line, index) => {
      const text = line.trim()
      if (!text) return <div className="md-gap" key={index}/>
      if (text.startsWith('### ')) return <h4 key={index}>{inline(text.slice(4))}</h4>
      if (text.startsWith('## ')) return <h3 key={index}>{inline(text.slice(3))}</h3>
      if (text.startsWith('# ')) return <h2 key={index}>{inline(text.slice(2))}</h2>
      if (text.startsWith('- ')) return <div className="md-bullet" key={index}>• {inline(text.slice(2))}</div>
      return <p key={index}>{inline(text)}</p>
    })}
  </div>
}

function inline(text: string) {
  const parts = text.split(/(\*\*[^*]+\*\*|`[^`]+`)/g)
  return parts.map((part, index) => {
    if (part.startsWith('**') && part.endsWith('**')) return <strong key={index}>{part.slice(2, -2)}</strong>
    if (part.startsWith('`') && part.endsWith('`')) return <code key={index}>{part.slice(1, -1)}</code>
    return part
  })
}
