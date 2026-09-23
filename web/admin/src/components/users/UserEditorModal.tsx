import { useMutation } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import type { PlanItem } from '../../api/finance'
import {
  generateUser,
  updateUser,
  type AdminUser,
  type UserUpdatePayload,
} from '../../api/user-admin'
import { Modal } from '../ui/Modal'

const GB = 1024 * 1024 * 1024

type EditDraft = {
  email: string
  password: string
  plan_id: string
  transfer_gb: string
  used_up_gb: string
  used_down_gb: string
  expired_at: string
  balance: string
  commission_balance: string
  commission_rate: string
  discount: string
  speed_limit: string
  device_limit: string
  invite_user_email: string
  remarks: string
  banned: boolean
}

type CreateDraft = {
  email: string
  password: string
  plan_id: string
  expired_at: string
}

function toLocalInput(seconds?: number | null) {
  if (!seconds) return ''
  const date = new Date(seconds * 1000)
  const offset = date.getTimezoneOffset() * 60_000
  return new Date(date.getTime() - offset).toISOString().slice(0, 16)
}

function toEpoch(value: string) {
  if (!value) return null
  const time = new Date(value).getTime()
  return Number.isFinite(time) ? Math.floor(time / 1000) : null
}

function bytesToGb(value?: number | null) {
  if (!value) return ''
  return (value / GB).toFixed(3).replace(/.000$/, '')
}

function gbToBytes(value: string) {
  if (value.trim() === '') return null
  const n = Number(value)
  return Number.isFinite(n) && n >= 0 ? Math.round(n * GB) : null
}

function nullableNumber(value: string) {
  if (value.trim() === '') return null
  const n = Number(value)
  return Number.isFinite(n) ? n : null
}

export function UserEditorModal({
  open,
  user,
  plans,
  onClose,
  onSaved,
}: {
  open: boolean
  user: AdminUser | null
  plans: PlanItem[]
  onClose: () => void
  onSaved: () => void
}) {
  const editing = Boolean(user)
  const [edit, setEdit] = useState<EditDraft>(() => emptyEdit())
  const [create, setCreate] = useState<CreateDraft>(() => emptyCreate())

  useEffect(() => {
    if (!open) return
    if (user) {
      setEdit({
        email: user.email || '',
        password: '',
        plan_id: user.plan_id == null ? '' : String(user.plan_id),
        transfer_gb: bytesToGb(user.transfer_enable),
        used_up_gb: bytesToGb(user.u),
        used_down_gb: bytesToGb(user.d),
        expired_at: toLocalInput(user.expired_at),
        balance: String(user.balance ?? 0),
        commission_balance: String(user.commission_balance ?? 0),
        commission_rate: user.commission_rate == null ? '' : String(user.commission_rate),
        discount: user.discount == null ? '' : String(user.discount),
        speed_limit: user.speed_limit == null ? '' : String(user.speed_limit),
        device_limit: user.device_limit == null ? '' : String(user.device_limit),
        invite_user_email: user.invite_user?.email || '',
        remarks: user.remarks || '',
        banned: Boolean(user.banned),
      })
    } else {
      setCreate(emptyCreate())
    }
  }, [open, user])

  const editMutation = useMutation({
    mutationFn: (payload: UserUpdatePayload) => updateUser(payload),
    onSuccess: () => {
      toast.success('用户已更新')
      onSaved()
      onClose()
    },
  })

  const createMutation = useMutation({
    mutationFn: () => {
      const email = create.email.trim()
      const at = email.lastIndexOf('@')
      if (at <= 0 || at === email.length - 1) throw new Error('邮箱格式不正确')
      return generateUser({
        email_prefix: email.slice(0, at),
        email_suffix: email.slice(at + 1),
        password: create.password || undefined,
        plan_id: create.plan_id ? Number(create.plan_id) : null,
        expired_at: toEpoch(create.expired_at),
        return_credentials: true,
      })
    },
    onSuccess: () => {
      toast.success('用户已创建')
      onSaved()
      onClose()
    },
    onError: error => toast.error(error instanceof Error ? error.message : '创建失败'),
  })

  function saveEdit() {
    if (!user) return
    if (!edit.email.trim()) return toast.error('请输入邮箱')

    const payload: UserUpdatePayload = {
      id: user.id,
      email: edit.email.trim(),
      plan_id: edit.plan_id ? Number(edit.plan_id) : null,
      expired_at: toEpoch(edit.expired_at),
      balance: Number(edit.balance || 0),
      commission_balance: Number(edit.commission_balance || 0),
      commission_rate: nullableNumber(edit.commission_rate),
      discount: nullableNumber(edit.discount),
      speed_limit: nullableNumber(edit.speed_limit),
      device_limit: nullableNumber(edit.device_limit),
      invite_user_email: edit.invite_user_email.trim(),
      remarks: edit.remarks,
      banned: edit.banned,
    }

    const transferEnable = gbToBytes(edit.transfer_gb)
    const usedUp = gbToBytes(edit.used_up_gb)
    const usedDown = gbToBytes(edit.used_down_gb)
    if (transferEnable != null) payload.transfer_enable = transferEnable
    if (usedUp != null) payload.u = usedUp
    if (usedDown != null) payload.d = usedDown
    if (edit.password.trim()) payload.password = edit.password.trim()
    editMutation.mutate(payload)
  }

  const pending = editMutation.isPending || createMutation.isPending

  return (
    <Modal
      open={open}
      title={editing ? '编辑用户' : '创建用户'}
      subtitle={editing && user
        ? `User #${user.id} · ${user.email || '未设置邮箱'}`
        : '创建新的 TXBoard 用户账户，并可直接分配套餐与到期时间。'}
      placement="right"
      className="user-editor-drawer"
      onClose={onClose}
      footer={
        <div className="user-editor-footer">
          <button className="button" onClick={onClose} disabled={pending}>取消</button>
          {editing ? (
            <button className="button primary" disabled={editMutation.isPending} onClick={saveEdit}>
              {editMutation.isPending ? '保存中…' : '保存用户'}
            </button>
          ) : (
            <button
              className="button primary"
              disabled={createMutation.isPending || !create.email.trim()}
              onClick={() => createMutation.mutate()}
            >
              {createMutation.isPending ? '创建中…' : '创建用户'}
            </button>
          )}
        </div>
      }
    >
      {editing ? (
        <div className="user-editor-form">
          <div className="user-editor-summary">
            <span className={edit.banned ? 'status off' : 'status ok'}>{edit.banned ? '已封禁' : '正常'}</span>
            <strong>{user?.plan?.name || (edit.plan_id ? `Plan #${edit.plan_id}` : '无套餐')}</strong>
            <small>{edit.expired_at ? `到期 ${new Date(edit.expired_at).toLocaleDateString()}` : '长期有效'}</small>
          </div>

          <FormSection title="账户" description="登录身份和密码。留空新密码不会修改现有凭据。">
            <div className="admin-form-grid">
              <Field label="邮箱" className="full">
                <input
                  type="email"
                  value={edit.email}
                  onChange={event => setEdit(value => ({ ...value, email: event.target.value }))}
                />
              </Field>
              <Field label="新密码" className="full" help="只在需要重置密码时填写。">
                <input
                  type="password"
                  autoComplete="new-password"
                  value={edit.password}
                  placeholder="留空不修改"
                  onChange={event => setEdit(value => ({ ...value, password: event.target.value }))}
                />
              </Field>
            </div>
          </FormSection>

          <FormSection title="订阅与流量" description="套餐、有效期与用户流量事实。">
            <div className="admin-form-grid">
              <Field label="套餐">
                <select value={edit.plan_id} onChange={event => setEdit(value => ({ ...value, plan_id: event.target.value }))}>
                  <option value="">无套餐</option>
                  {plans.map(plan => <option key={plan.id} value={plan.id}>{plan.name}</option>)}
                </select>
              </Field>
              <Field label="到期时间" help="留空表示长期有效。">
                <input
                  type="datetime-local"
                  value={edit.expired_at}
                  onChange={event => setEdit(value => ({ ...value, expired_at: event.target.value }))}
                />
              </Field>
              <UnitField label="总流量" unit="GB" value={edit.transfer_gb} min="0" step="0.001" onChange={value => setEdit(current => ({ ...current, transfer_gb: value }))} />
              <UnitField label="上传已用" unit="GB" value={edit.used_up_gb} min="0" step="0.001" onChange={value => setEdit(current => ({ ...current, used_up_gb: value }))} />
              <UnitField label="下载已用" unit="GB" value={edit.used_down_gb} min="0" step="0.001" onChange={value => setEdit(current => ({ ...current, used_down_gb: value }))} />
            </div>
          </FormSection>

          <FormSection title="资金与佣金" description="账户余额、佣金账户与个性化折扣。">
            <div className="admin-form-grid">
              <UnitField label="余额" unit="¥" value={edit.balance} min="0" step="0.01" onChange={value => setEdit(current => ({ ...current, balance: value }))} />
              <UnitField label="佣金余额" unit="¥" value={edit.commission_balance} min="0" step="0.01" onChange={value => setEdit(current => ({ ...current, commission_balance: value }))} />
              <UnitField label="佣金比例" unit="%" value={edit.commission_rate} min="0" max="100" onChange={value => setEdit(current => ({ ...current, commission_rate: value }))} />
              <UnitField label="折扣" unit="%" value={edit.discount} min="0" max="100" onChange={value => setEdit(current => ({ ...current, discount: value }))} />
            </div>
          </FormSection>

          <FormSection title="限制与归属" description="覆盖套餐默认限制时使用；留空表示沿用系统或套餐设置。">
            <div className="admin-form-grid">
              <UnitField label="限速" unit="Mbps" value={edit.speed_limit} min="0" onChange={value => setEdit(current => ({ ...current, speed_limit: value }))} />
              <Field label="设备限制" help="留空表示不覆盖套餐设置。">
                <input
                  type="number"
                  min="0"
                  value={edit.device_limit}
                  onChange={event => setEdit(value => ({ ...value, device_limit: event.target.value }))}
                />
              </Field>
              <Field label="邀请人邮箱" className="full">
                <input
                  type="email"
                  value={edit.invite_user_email}
                  placeholder="邀请关系不存在时留空"
                  onChange={event => setEdit(value => ({ ...value, invite_user_email: event.target.value }))}
                />
              </Field>
            </div>
          </FormSection>

          <FormSection title="备注与账户状态" description="管理员备注不会展示给用户。">
            <div className="admin-form-grid">
              <Field label="备注" className="full">
                <textarea
                  value={edit.remarks}
                  placeholder="记录特殊处理、来源或其他管理信息…"
                  onChange={event => setEdit(value => ({ ...value, remarks: event.target.value }))}
                />
              </Field>
              <div className="admin-switch-row user-editor-danger full">
                <div>
                  <strong>封禁用户</strong>
                  <small>封禁后用户将无法正常登录和使用账户能力。</small>
                </div>
                <button
                  type="button"
                  role="switch"
                  aria-label="封禁用户"
                  aria-checked={edit.banned}
                  className={`config-switch ${edit.banned ? 'active' : ''}`}
                  onClick={() => setEdit(value => ({ ...value, banned: !value.banned }))}
                >
                  <span />
                </button>
              </div>
            </div>
          </FormSection>
        </div>
      ) : (
        <div className="user-editor-form">
          <FormSection title="账户" description="邮箱是登录身份；密码留空时沿用现有后端生成逻辑。">
            <div className="admin-form-grid">
              <Field label="邮箱 *" className="full">
                <input
                  autoFocus
                  type="email"
                  value={create.email}
                  onChange={event => setCreate(value => ({ ...value, email: event.target.value }))}
                  placeholder="user@example.com"
                />
              </Field>
              <Field label="密码" className="full" help="留空则默认使用邮箱。">
                <input
                  type="password"
                  autoComplete="new-password"
                  value={create.password}
                  onChange={event => setCreate(value => ({ ...value, password: event.target.value }))}
                  placeholder="可选"
                />
              </Field>
            </div>
          </FormSection>

          <FormSection title="初始订阅" description="可以创建空账户，也可以立即分配套餐和到期时间。">
            <div className="admin-form-grid">
              <Field label="套餐">
                <select value={create.plan_id} onChange={event => setCreate(value => ({ ...value, plan_id: event.target.value }))}>
                  <option value="">无套餐</option>
                  {plans.map(plan => <option key={plan.id} value={plan.id}>{plan.name}</option>)}
                </select>
              </Field>
              <Field label="到期时间" help="留空表示长期有效。">
                <input
                  type="datetime-local"
                  value={create.expired_at}
                  onChange={event => setCreate(value => ({ ...value, expired_at: event.target.value }))}
                />
              </Field>
            </div>
          </FormSection>
        </div>
      )}
    </Modal>
  )
}

function FormSection({
  title,
  description,
  children,
}: {
  title: string
  description: string
  children: React.ReactNode
}) {
  return (
    <section className="admin-form-section">
      <div className="admin-form-section-head">
        <div>
          <strong>{title}</strong>
          <small>{description}</small>
        </div>
      </div>
      <div className="admin-form-section-body">{children}</div>
    </section>
  )
}

function Field({
  label,
  help,
  className = '',
  children,
}: {
  label: string
  help?: string
  className?: string
  children: React.ReactNode
}) {
  return (
    <label className={`field ${className}`.trim()}>
      <span>{label}</span>
      {children}
      {help ? <small className="field-help">{help}</small> : null}
    </label>
  )
}

function UnitField({
  label,
  unit,
  value,
  onChange,
  min,
  max,
  step,
}: {
  label: string
  unit: string
  value: string
  onChange: (value: string) => void
  min?: string
  max?: string
  step?: string
}) {
  return (
    <label className="field">
      <span>{label}</span>
      <span className="input-with-unit">
        <input
          type="number"
          value={value}
          min={min}
          max={max}
          step={step}
          onChange={event => onChange(event.target.value)}
        />
        <span>{unit}</span>
      </span>
    </label>
  )
}

function emptyEdit(): EditDraft {
  return {
    email: '', password: '', plan_id: '', transfer_gb: '', used_up_gb: '', used_down_gb: '',
    expired_at: '', balance: '0', commission_balance: '0', commission_rate: '', discount: '',
    speed_limit: '', device_limit: '', invite_user_email: '', remarks: '', banned: false,
  }
}

function emptyCreate(): CreateDraft {
  return { email: '', password: '', plan_id: '', expired_at: '' }
}
