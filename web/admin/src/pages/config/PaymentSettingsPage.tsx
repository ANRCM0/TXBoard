import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ArrowDown,
  ArrowUp,
  Copy,
  CreditCard,
  Pencil,
  Plus,
  Power,
  Trash2,
} from 'lucide-react'
import { useRef, useState } from 'react'
import { toast } from 'sonner'
import {
  deletePayment,
  getPaymentForm,
  getPaymentMethods,
  getPayments,
  savePayment,
  sortPayments,
  togglePayment,
  type PaymentFormField,
  type PaymentItem,
  type PaymentOption,
} from '../../api/payment'
import { Modal } from '../../components/ui/Modal'
import { PageHeader } from '../../components/ui/PageHeader'
import { requestConfirm } from '../../components/ui/ConfirmDialog'

type Draft = {
  name: string
  icon: string
  payment: string
  notify_domain: string
  handling_fee_fixed: string
  handling_fee_percent: string
  config: Record<string, unknown>
}

const emptyDraft: Draft = {
  name: '',
  icon: '',
  payment: '',
  notify_domain: '',
  handling_fee_fixed: '',
  handling_fee_percent: '',
  config: {},
}

export function PaymentSettingsPage() {
  const qc = useQueryClient()
  const paymentsQuery = useQuery({ queryKey: ['payments'], queryFn: getPayments })
  const methodsQuery = useQuery({ queryKey: ['paymentMethods'], queryFn: getPaymentMethods })
  const [editorOpen, setEditorOpen] = useState(false)
  const [editing, setEditing] = useState<PaymentItem | null>(null)
  const [draft, setDraft] = useState<Draft>(emptyDraft)
  const [schema, setSchema] = useState<Record<string, PaymentFormField>>({})
  const [schemaLoading, setSchemaLoading] = useState(false)
  const schemaSeq = useRef(0)

  const refresh = () => qc.invalidateQueries({ queryKey: ['payments'] })

  const saveMutation = useMutation({
    mutationFn: () => savePayment({
      ...(editing?.id ? { id: editing.id } : {}),
      name: draft.name.trim(),
      icon: draft.icon.trim() || null,
      payment: draft.payment,
      config: draft.config,
      notify_domain: draft.notify_domain.trim() || null,
      handling_fee_fixed: draft.handling_fee_fixed.trim() === ''
        ? null
        : Math.round(Number(draft.handling_fee_fixed)),
      handling_fee_percent: draft.handling_fee_percent.trim() === ''
        ? null
        : Number(draft.handling_fee_percent),
    }),
    onSuccess: () => {
      toast.success(editing ? '支付方式已更新' : '支付方式已创建')
      setEditorOpen(false)
      refresh()
    },
  })

  const toggleMutation = useMutation({
    mutationFn: togglePayment,
    onSuccess: () => {
      toast.success('支付方式状态已更新')
      refresh()
    },
  })

  const deleteMutation = useMutation({
    mutationFn: deletePayment,
    onSuccess: () => {
      toast.success('支付方式已删除')
      refresh()
    },
  })

  const sortMutation = useMutation({
    mutationFn: sortPayments,
    onSuccess: refresh,
  })

  async function loadSchema(payment: string, id?: number, seed: Record<string, unknown> = {}) {
    const seq = ++schemaSeq.current
    if (!payment) {
      setSchema({})
      setDraft(value => ({ ...value, config: seed }))
      return
    }

    setSchemaLoading(true)
    try {
      const nextSchema = await getPaymentForm(payment, id)
      if (seq !== schemaSeq.current) return
      setSchema(nextSchema)
      const nextConfig: Record<string, unknown> = {}
      for (const [key, field] of Object.entries(nextSchema)) {
        nextConfig[key] = field.value ?? seed[key] ?? ''
      }
      setDraft(value => ({ ...value, config: nextConfig }))
    } catch {
      if (seq !== schemaSeq.current) return
      const fallback = Object.fromEntries(
        Object.keys(seed).map(key => [key, {
          type: 'string',
          label: key,
          value: seed[key],
          description: '当前网关未返回表单定义，保留已保存配置。',
        } satisfies PaymentFormField]),
      )
      setSchema(fallback)
      setDraft(value => ({ ...value, config: { ...seed } }))
      toast.warning('无法读取该支付网关的动态表单，已使用现有配置作为兼容编辑模式')
    } finally {
      if (seq === schemaSeq.current) setSchemaLoading(false)
    }
  }

  function openCreate() {
    const first = methodsQuery.data?.[0] || ''
    setEditing(null)
    setDraft({ ...emptyDraft, payment: first })
    setSchema({})
    setEditorOpen(true)
    if (first) void loadSchema(first)
  }

  function openEdit(payment: PaymentItem) {
    setEditing(payment)
    setDraft({
      name: payment.name || '',
      icon: payment.icon || '',
      payment: payment.payment || '',
      notify_domain: payment.notify_domain || '',
      handling_fee_fixed: payment.handling_fee_fixed == null ? '' : String(payment.handling_fee_fixed),
      handling_fee_percent: payment.handling_fee_percent == null ? '' : String(payment.handling_fee_percent),
      config: { ...(payment.config || {}) },
    })
    setSchema({})
    setEditorOpen(true)
    void loadSchema(payment.payment, payment.id, payment.config || {})
  }

  function closeEditor() {
    if (saveMutation.isPending) return
    setEditorOpen(false)
  }

  function changeGateway(next: string) {
    setDraft(value => ({ ...value, payment: next, config: {} }))
    void loadSchema(next)
  }

  function updateConfig(key: string, value: unknown) {
    setDraft(current => ({
      ...current,
      config: { ...current.config, [key]: value },
    }))
  }

  function move(index: number, delta: -1 | 1) {
    const rows = [...(paymentsQuery.data || [])]
    const target = index + delta
    if (target < 0 || target >= rows.length) return
    const [item] = rows.splice(index, 1)
    rows.splice(target, 0, item)
    sortMutation.mutate(rows.map(row => row.id))
  }

  const rows = paymentsQuery.data || []
  const methods = Array.from(new Set([
    ...(methodsQuery.data || []),
    ...(editing?.payment ? [editing.payment] : []),
  ]))

  return <>
    <PageHeader
      title="支付配置"
      description="管理支付网关、插件动态配置、手续费、回调地址和启用状态。"
      action={<button className="button primary" onClick={openCreate}>
        <Plus size={16}/>添加支付方式
      </button>}
    />

    <div className="card payment-table-card">
      <div className="table-wrap">
        <table className="data-table">
          <thead>
            <tr>
              <th>排序</th>
              <th>名称</th>
              <th>网关</th>
              <th>手续费</th>
              <th>回调地址</th>
              <th>状态</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((payment, index) => <tr key={payment.id}>
              <td>
                <div className="payment-sort-actions">
                  <button
                    className="icon-button"
                    disabled={index === 0 || sortMutation.isPending}
                    title="上移"
                    onClick={() => move(index, -1)}
                  ><ArrowUp size={14}/></button>
                  <button
                    className="icon-button"
                    disabled={index === rows.length - 1 || sortMutation.isPending}
                    title="下移"
                    onClick={() => move(index, 1)}
                  ><ArrowDown size={14}/></button>
                </div>
              </td>
              <td>
                <div className="payment-name">
                  <PaymentIcon value={payment.icon}/>
                  <div>
                    <strong>{payment.name}</strong>
                    <small>ID {payment.id}</small>
                  </div>
                </div>
              </td>
              <td><code className="payment-code">{payment.payment}</code></td>
              <td>{feeLabel(payment)}</td>
              <td>
                {payment.notify_url ? <div className="payment-notify">
                  <span title={payment.notify_url}>{payment.notify_url}</span>
                  <button
                    className="icon-button"
                    title="复制回调地址"
                    onClick={() => copyText(payment.notify_url || '')}
                  ><Copy size={14}/></button>
                </div> : '-'}
              </td>
              <td>
                <span className={payment.enable ? 'status ok' : 'status off'}>
                  {payment.enable ? '已启用' : '已停用'}
                </span>
              </td>
              <td>
                <div className="actions">
                  <button className="icon-button" title="编辑" onClick={() => openEdit(payment)}>
                    <Pencil size={14}/>
                  </button>
                  <button
                    className={payment.enable ? 'icon-button success' : 'icon-button'}
                    title={payment.enable ? '停用' : '启用'}
                    disabled={toggleMutation.isPending}
                    onClick={() => toggleMutation.mutate(payment.id)}
                  ><Power size={14}/></button>
                  <button
                    className="icon-button danger"
                    title="删除"
                    disabled={deleteMutation.isPending}
                    onClick={() => requestConfirm({ title: '删除支付方式', message: `确认删除支付方式「${payment.name}」？`, danger: true, confirmLabel: '删除', action: () => deleteMutation.mutate(payment.id) })}
                  ><Trash2 size={14}/></button>
                </div>
              </td>
            </tr>)}
            {!rows.length && <tr>
              <td className="empty-cell" colSpan={7}>
                {paymentsQuery.isLoading ? '正在加载支付方式…' : '暂无支付方式。先确认支付插件已启用，再添加网关。'}
              </td>
            </tr>}
          </tbody>
        </table>
      </div>
    </div>

    <Modal
      open={editorOpen}
      title={editing ? `编辑支付方式：${editing.name}` : '添加支付方式'}
      onClose={closeEditor}
    >
      <div className="payment-editor">
        <section className="payment-editor-section">
          <div className="payment-editor-heading">
            <CreditCard size={17}/>
            <div><strong>基础配置</strong><small>显示名称、支付插件与手续费</small></div>
          </div>

          <div className="two-col">
            <label className="field">
              <span>显示名称 *</span>
              <input value={draft.name} onChange={event => setDraft(value => ({ ...value, name: event.target.value }))}/>
            </label>
            <label className="field">
              <span>支付网关 *</span>
              <select
                value={draft.payment}
                disabled={Boolean(editing)}
                onChange={event => changeGateway(event.target.value)}
              >
                <option value="">请选择支付网关</option>
                {methods.map(method => <option value={method} key={method}>{method}</option>)}
              </select>
              {editing ? <small className="field-help">编辑现有支付方式时不切换网关类型。</small> : null}
            </label>
            <label className="field">
              <span>图标</span>
              <input
                value={draft.icon}
                placeholder="URL、emoji 或文本"
                onChange={event => setDraft(value => ({ ...value, icon: event.target.value }))}
              />
            </label>
            <label className="field">
              <span>自定义回调域名</span>
              <input
                value={draft.notify_domain}
                placeholder="https://pay.example.com"
                onChange={event => setDraft(value => ({ ...value, notify_domain: event.target.value }))}
              />
              <small className="field-help">留空时使用站点 app_url。后端要求完整 URL。</small>
            </label>
            <label className="field">
              <span>固定手续费（分）</span>
              <input
                type="number"
                min="0"
                step="1"
                value={draft.handling_fee_fixed}
                onChange={event => setDraft(value => ({ ...value, handling_fee_fixed: event.target.value }))}
              />
            </label>
            <label className="field">
              <span>百分比手续费（%）</span>
              <input
                type="number"
                min="0"
                max="100"
                step="0.01"
                value={draft.handling_fee_percent}
                onChange={event => setDraft(value => ({ ...value, handling_fee_percent: event.target.value }))}
              />
            </label>
          </div>
        </section>

        <section className="payment-editor-section">
          <div className="payment-editor-heading">
            <CreditCard size={17}/>
            <div>
              <strong>网关参数</strong>
              <small>由当前支付插件的 form() 动态生成</small>
            </div>
          </div>

          {schemaLoading ? <div className="empty-state">正在读取支付插件表单…</div> : (
            <div className="payment-config-grid">
              {Object.entries(schema).map(([key, field]) => (
                <PaymentConfigField
                  key={key}
                  name={key}
                  field={field}
                  value={draft.config[key]}
                  onChange={value => updateConfig(key, value)}
                />
              ))}
              {!Object.keys(schema).length ? (
                <div className="empty-state payment-config-empty">
                  {draft.payment ? '该网关没有额外配置项。' : '选择支付网关后显示配置项。'}
                </div>
              ) : null}
            </div>
          )}
        </section>

        <div className="card-actions">
          <button className="button" onClick={closeEditor} disabled={saveMutation.isPending}>取消</button>
          <button
            className="button primary"
            disabled={
              saveMutation.isPending ||
              schemaLoading ||
              !draft.name.trim() ||
              !draft.payment ||
              !validFees(draft)
            }
            onClick={() => saveMutation.mutate()}
          >{saveMutation.isPending ? '保存中…' : '保存支付方式'}</button>
        </div>
      </div>
    </Modal>
  </>
}

function PaymentConfigField({
  name,
  field,
  value,
  onChange,
}: {
  name: string
  field: PaymentFormField
  value: unknown
  onChange: (value: unknown) => void
}) {
  const type = String(field.type || 'string').toLowerCase()
  const options = normalizeOptions(field.options)
  const label = field.label || name
  const description = field.description || ''
  const textValue = value == null ? '' : String(value)

  if (type === 'boolean' || type === 'switch') {
    return <label className="check-field payment-config-field">
      <input
        type="checkbox"
        checked={Boolean(value)}
        onChange={event => onChange(event.target.checked)}
      />
      <span><strong>{label}</strong>{description ? <small className="field-help">{description}</small> : null}</span>
    </label>
  }

  return <label className="field payment-config-field">
    <span>{label}</span>
    {options.length ? (
      <select value={textValue} onChange={event => onChange(event.target.value)}>
        <option value="">请选择</option>
        {options.map(option => <option value={String(option.value)} key={String(option.value)}>
          {option.label}
        </option>)}
      </select>
    ) : type === 'text' || type === 'textarea' ? (
      <textarea
        value={textValue}
        placeholder={field.placeholder || ''}
        onChange={event => onChange(event.target.value)}
      />
    ) : (
      <input
        type={type === 'number' ? 'number' : 'text'}
        value={textValue}
        placeholder={field.placeholder || ''}
        onChange={event => onChange(type === 'number' && event.target.value !== '' ? Number(event.target.value) : event.target.value)}
      />
    )}
    {description ? <small className="field-help">{description}</small> : null}
    <small className="payment-config-key">{name}</small>
  </label>
}

function normalizeOptions(options: PaymentFormField['options']) {
  if (!options) return [] as Array<{ label: string; value: string | number }>
  if (Array.isArray(options)) {
    return options.map((option: PaymentOption) => {
      if (typeof option === 'object' && option !== null) {
        const value = option.value ?? option.label ?? ''
        return { value, label: option.label || String(value) }
      }
      return { value: option, label: String(option) }
    })
  }
  return Object.entries(options).map(([value, label]) => ({ value, label: String(label) }))
}

function feeLabel(payment: PaymentItem) {
  const parts: string[] = []
  if (Number(payment.handling_fee_fixed || 0)) parts.push(`${payment.handling_fee_fixed} 分固定`)
  if (Number(payment.handling_fee_percent || 0)) parts.push(`${payment.handling_fee_percent}%`)
  return parts.length ? parts.join(' + ') : '无'
}

function validFees(draft: Draft) {
  const fixed = draft.handling_fee_fixed.trim() === '' ? 0 : Number(draft.handling_fee_fixed)
  const percent = draft.handling_fee_percent.trim() === '' ? 0 : Number(draft.handling_fee_percent)
  return Number.isFinite(fixed) && fixed >= 0 && Number.isInteger(fixed)
    && Number.isFinite(percent) && percent >= 0 && percent <= 100
}

async function copyText(value: string) {
  try {
    await navigator.clipboard.writeText(value)
    toast.success('回调地址已复制')
  } catch {
    toast.error('复制失败')
  }
}


function PaymentIcon({ value }: { value?: string | null }) {
  const icon = String(value || '').trim()
  if (!icon) return <div className="payment-icon"><CreditCard size={16}/></div>
  if (/^https?:\/\//i.test(icon)) {
    return <div className="payment-icon"><img src={icon} alt="" /></div>
  }
  return <div className="payment-icon" title={icon}>{icon.slice(0, 2)}</div>
}
