import { useMutation } from '@tanstack/react-query'
import { useEffect, useId, useRef, useState } from 'react'
import { ChevronDown, ChevronUp, Save, WandSparkles } from 'lucide-react'
import { toast } from 'sonner'
import { savePlan, type PlanItem, type PlanSavePayload } from '../../api/finance'
import type { GroupItem } from '../../api/server'
import { requestConfirm } from '../ui/ConfirmDialog'
import { Modal } from '../ui/Modal'
import {
  discountedPlanPrices, draftFromPlan, planPayload, planPeriods, primaryPeriods, validatePlanDraft,
  type PlanDraft, type PlanFieldError, type PlanPeriod,
} from './planEditorModel'
import './PlanEditor.css'

const extraPeriods = planPeriods.filter(([key]) => !primaryPeriods.includes(key))

function hasExtendedPricing(draft: PlanDraft) {
  return extraPeriods.some(([key]) => draft.prices[key].trim() !== '')
}
function hasExtendedLimits(draft: PlanDraft) {
  return Boolean(draft.speed_limit || draft.device_limit || draft.capacity_limit || draft.reset_traffic_method)
}

export function PlanEditor({
  open, plan, groups, onClose, onSaved,
}: {
  open: boolean
  plan?: PlanItem | null
  groups: GroupItem[]
  onClose: () => void
  onSaved: () => void
}) {
  const [draft, setDraft] = useState<PlanDraft>(() => draftFromPlan(plan))
  const [attempted, setAttempted] = useState(false)
  const [morePrices, setMorePrices] = useState(() => hasExtendedPricing(draftFromPlan(plan)))
  const [moreLimits, setMoreLimits] = useState(() => hasExtendedLimits(draftFromPlan(plan)))
  const [preview, setPreview] = useState(false)
  const [focusRequest, setFocusRequest] = useState(0)
  const formRef = useRef<HTMLFormElement>(null)
  const formId = useId()
  const extrasId = useId()
  const limitsId = useId()
  const initial = draftFromPlan(plan)
  const dirty = JSON.stringify(draft) !== JSON.stringify(initial)
  const errors: PlanFieldError = attempted ? validatePlanDraft(draft) : {}
  const activePrices = planPeriods.filter(([key]) => draft.prices[key].trim() !== '')
  const unknownReset = Boolean(draft.reset_traffic_method) &&
    !['0','1','2','3','4'].includes(draft.reset_traffic_method)
  const groupUnknown = Boolean(draft.group_id) &&
    !groups.some(item => String(item.id) === draft.group_id)

  useEffect(() => {
    if (!open) return
    const next = draftFromPlan(plan)
    setDraft(next)
    setMorePrices(hasExtendedPricing(next))
    setMoreLimits(hasExtendedLimits(next))
    setAttempted(false)
    setPreview(false)
  }, [open, plan])

  useEffect(() => {
    if (focusRequest === 0) return
    formRef.current?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus()
  }, [focusRequest])

  const mutation = useMutation({
    mutationFn: (payload: PlanSavePayload) => savePlan(payload),
    onSuccess: () => {
      toast.success(plan ? '套餐已更新' : '套餐已创建')
      onSaved()
      onClose()
    },
    onError: () => toast.error('保存失败，请核对表单并重试'),
  })

  function patch<K extends keyof PlanDraft>(key: K, value: PlanDraft[K]) {
    setDraft(current => ({...current, [key]: value}))
  }
  function patchPrice(key: PlanPeriod, value: string) {
    setDraft(current => ({...current, prices: {...current.prices, [key]: value}}))
  }

  function attemptClose() {
    if (mutation.isPending) return
    if (!dirty) return onClose()
    requestConfirm({
      title: '放弃未保存的套餐修改？',
      message: '离开后本次填写的内容不会保存。',
      confirmLabel: '放弃修改',
      danger: true,
      action: onClose,
    })
  }

  function generateDiscountPrices() {
    const result = discountedPlanPrices(draft.prices.monthly)
    if (!result) {
      setAttempted(true)
      toast.error('请先填写有效的月付价格')
      setFocusRequest(number => number + 1)
      return
    }
    setDraft(current => ({...current, prices: {...current.prices, ...result}}))
    setMorePrices(true)
    toast.success('已根据月付价格生成折扣价，请检查后保存')
  }

  function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (mutation.isPending) return
    const failures = validatePlanDraft(draft)
    const invalid = Object.keys(failures)
    if (invalid.length) {
      setAttempted(true)
      if (invalid.some(key => key.startsWith('prices.') && !primaryPeriods.includes(key.slice(7) as PlanPeriod))) {
        setMorePrices(true)
      }
      if (invalid.some(key => ['speed_limit', 'device_limit', 'capacity_limit', 'reset_traffic_method'].includes(key))) {
        setMoreLimits(true)
      }
      setFocusRequest(number => number + 1)
      return
    }
    mutation.mutate(planPayload(draft, plan))
  }

  function errorFor(key: string) {
    return errors[key] ? <small className="plan-v4-error" role="alert">{errors[key]}</small> : null
  }
  function priceField(key: PlanPeriod, label: string) {
    const error = Boolean(errors['prices.'+key])
    return <label className="field" key={key}>
      <span>{label}</span>
      <div className="plan-v4-price-input">
        <span aria-hidden="true">¥</span>
        <input type="number" name={'price-'+key} min="0.01" step="0.01" inputMode="decimal"
          value={draft.prices[key]} aria-invalid={error}
          onChange={event => patchPrice(key,event.target.value)} placeholder="不提供此周期…"/>
      </div>
      {errorFor('prices.'+key)}
    </label>
  }

  return <Modal
    open={open}
    title={plan ? '编辑套餐：'+plan.name : '创建套餐'}
    subtitle="先配置基础信息与售卖周期，其余设置按需展开。"
    placement="right"
    className="plan-v4-drawer"
    onClose={attemptClose}
    footer={<div className="plan-v4-footer">
      <span className="plan-v4-dirty" role="status">{dirty ? '有未保存的修改' : '尚未修改'}</span>
      <button type="button" className="button" onClick={attemptClose} disabled={mutation.isPending}>取消</button>
      <button type="submit" form={formId} className="button primary" disabled={mutation.isPending}>
        <Save size={15} aria-hidden="true"/>{mutation.isPending ? '保存中…' : '保存套餐'}
      </button>
    </div>}
  >
    <form id={formId} ref={formRef} className="plan-editor plan-v4-form" onSubmit={submit} noValidate>
      <section className="plan-v4-section" aria-labelledby={formId+'-basic'}>
        <div className="plan-v4-heading">
          <strong id={formId+'-basic'}>基础信息</strong>
          <small>套餐名称、权限组与流量额度</small>
        </div>
        <div className="plan-v4-grid">
          <label className="field plan-v4-full">
            <span>套餐名称 *</span>
            <input name="plan-name" autoComplete="off" value={draft.name} aria-invalid={Boolean(errors.name)}
              onChange={event => patch('name',event.target.value)} placeholder="例如：Pro 100G…"/>
            {errorFor('name')}
          </label>
          <label className="field">
            <span>权限组</span>
            <select name="group_id" value={draft.group_id} aria-invalid={Boolean(errors.group_id)}
              onChange={event => patch('group_id', event.target.value)}>
              <option value="">不指定</option>
              {groupUnknown ? <option value={draft.group_id}>现有权限组 #{draft.group_id}</option> : null}
              {groups.map(group => <option key={group.id} value={group.id}>{group.name || 'Group '+group.id}</option>)}
            </select>
            {errorFor('group_id')}
          </label>
          <label className="field">
            <span>流量配额（GB）*</span>
            <input name="transfer_enable" type="number" min="1" step="1" inputMode="numeric"
              value={draft.transfer_enable} aria-invalid={Boolean(errors.transfer_enable)}
              onChange={event => patch('transfer_enable',event.target.value)}/>
            {errorFor('transfer_enable')}
          </label>
          <label className="field plan-v4-full">
            <span>标签</span>
            <input name="tags" autoComplete="off" value={draft.tags}
              onChange={event => patch('tags',event.target.value)} placeholder="例如：热门, 推荐, 高速…"/>
            <small className="field-help">用逗号分隔；不会影响套餐的业务规则。</small>
          </label>
        </div>
      </section>

      <section className="plan-v4-section" aria-labelledby={formId+'-prices'}>
        <div className="plan-v4-heading plan-v4-heading-actions">
          <div><strong id={formId+'-prices'}>售卖周期与价格</strong><small>留空即不提供该周期；金额单位为元。</small></div>
          <button className="button" type="button" onClick={generateDiscountPrices}>
            <WandSparkles size={15} aria-hidden="true"/>按月价生成
          </button>
        </div>
        <div className="plan-v4-grid plan-v4-prices">
          {planPeriods.filter(([key]) => primaryPeriods.includes(key)).map(([key,label]) => priceField(key,label))}
        </div>
        <button className="plan-v4-disclosure" type="button" aria-expanded={morePrices}
          aria-controls={extrasId} onClick={() => setMorePrices(value => !value)}>
          <span>其他计费周期{hasExtendedPricing(draft) ? ' · 已设置' : ''}</span>
          {morePrices ? <ChevronUp size={16} aria-hidden="true"/> : <ChevronDown size={16} aria-hidden="true"/>}
        </button>
        {morePrices ? <div id={extrasId} className="plan-v4-grid plan-v4-prices plan-v4-extra-prices">
          {extraPeriods.map(([key,label]) => priceField(key,label))}
        </div> : null}
        <div className="plan-v4-summary" role="status">
          已设置 {activePrices.length} 个周期
          {activePrices.length === 0 ? ' · 尚未填写售卖价格' : ''}
        </div>
      </section>

      <section className="plan-v4-section plan-v4-section-optional">
        <button className="plan-v4-disclosure plan-v4-disclosure-top" type="button"
          aria-expanded={moreLimits} aria-controls={limitsId}
          onClick={() => setMoreLimits(value => !value)}>
          <span><strong>访问与使用限制</strong><small>限速、设备、人数与流量重置方式；默认跟随系统设置。</small></span>
          {moreLimits ? <ChevronUp size={16} aria-hidden="true"/> : <ChevronDown size={16} aria-hidden="true"/>}
        </button>
        {moreLimits ? <div id={limitsId} className="plan-v4-grid plan-v4-limits">
          <label className="field">
            <span>限速（Mbps）</span>
            <input name="speed_limit" type="number" min="0" step="any" inputMode="decimal"
              aria-invalid={Boolean(errors.speed_limit)} value={draft.speed_limit}
              onChange={event => patch('speed_limit',event.target.value)} placeholder="不限制…"/>
            {errorFor('speed_limit')}
          </label>
          <label className="field">
            <span>设备限制</span>
            <input name="device_limit" type="number" min="0" step="1" inputMode="numeric"
              aria-invalid={Boolean(errors.device_limit)} value={draft.device_limit}
              onChange={event => patch('device_limit',event.target.value)} placeholder="不限制…"/>
            {errorFor('device_limit')}
          </label>
          <label className="field">
            <span>人数上限</span>
            <input name="capacity_limit" type="number" min="0" step="1" inputMode="numeric"
              aria-invalid={Boolean(errors.capacity_limit)} value={draft.capacity_limit}
              onChange={event => patch('capacity_limit',event.target.value)} placeholder="不限制…"/>
            {errorFor('capacity_limit')}
          </label>
          <label className="field">
            <span>流量重置方式</span>
            <select name="reset_traffic_method" value={draft.reset_traffic_method}
              aria-invalid={Boolean(errors.reset_traffic_method)}
              onChange={event => patch('reset_traffic_method',event.target.value)}>
              <option value="">跟随系统设置</option>
              {unknownReset ? <option value={draft.reset_traffic_method}>现有设置 #{draft.reset_traffic_method}</option> : null}
              <option value="0">每月 1 号</option><option value="1">按月重置</option>
              <option value="2">不重置</option><option value="3">每年 1 月 1 日</option>
              <option value="4">按年重置</option>
            </select>
            {errorFor('reset_traffic_method')}
          </label>
        </div> : null}
      </section>

      <section className="plan-v4-section">
        <div className="plan-v4-heading plan-v4-heading-actions">
          <div><strong>套餐说明</strong><small>支持 Markdown 与已有模板变量。</small></div>
          <button className="button" type="button" aria-pressed={preview} onClick={() => setPreview(value => !value)}>
            {preview ? '继续编辑' : '预览说明'}
          </button>
        </div>
        {preview ? <MarkdownLite content={draft.content}/> :
          <label className="field">
            <span className="plan-v4-sr-only">套餐描述</span>
            <textarea name="content" className="plan-content-editor plan-v4-description"
              value={draft.content} onChange={event => patch('content',event.target.value)}
              placeholder={'# 套餐说明\n\n- 每月 {{transfer}} GB\n- 最高 {{speed}} Mbps'}/>
          </label>}
        <small className="field-help">模板变量：{'{{transfer}}'}、{'{{speed}}'}、{'{{devices}}'}、{'{{reset_method}}'}</small>
      </section>

      {plan ? <section className="plan-v4-section plan-v4-impact">
        <label className="check-field force-update">
          <input type="checkbox" name="force_update" checked={draft.force_update}
            onChange={event => patch('force_update',event.target.checked)}/>
          <span><strong>同步更新当前套餐用户</strong><small>仅在主动勾选时同步权限组、流量、限速和设备限制；请确认影响范围。</small></span>
        </label>
      </section> : null}
    </form>
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
