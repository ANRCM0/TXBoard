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
  return (value / GB).toFixed(3).replace(/\.000$/, '')
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

  return <Modal open={open} title={editing ? '编辑用户' : '创建用户'} onClose={onClose}>
    {editing ? <div className="form-stack">
      <div className="settings-grid user-edit-grid">
        <Field label="邮箱"><input value={edit.email} onChange={e => setEdit(v => ({ ...v, email: e.target.value }))}/></Field>
        <Field label="新密码"><input type="password" value={edit.password} placeholder="留空不修改" onChange={e => setEdit(v => ({ ...v, password: e.target.value }))}/></Field>

        <Field label="套餐"><select value={edit.plan_id} onChange={e => setEdit(v => ({ ...v, plan_id: e.target.value }))}>
          <option value="">无套餐</option>
          {plans.map(plan => <option key={plan.id} value={plan.id}>{plan.name}</option>)}
        </select></Field>
        <Field label="到期时间"><input type="datetime-local" value={edit.expired_at} onChange={e => setEdit(v => ({ ...v, expired_at: e.target.value }))}/></Field>

        <Field label="总流量（GB）"><input type="number" min="0" step="0.001" value={edit.transfer_gb} onChange={e => setEdit(v => ({ ...v, transfer_gb: e.target.value }))}/></Field>
        <Field label="上传已用（GB）"><input type="number" min="0" step="0.001" value={edit.used_up_gb} onChange={e => setEdit(v => ({ ...v, used_up_gb: e.target.value }))}/></Field>
        <Field label="下载已用（GB）"><input type="number" min="0" step="0.001" value={edit.used_down_gb} onChange={e => setEdit(v => ({ ...v, used_down_gb: e.target.value }))}/></Field>
        <Field label="余额"><input type="number" min="0" step="0.01" value={edit.balance} onChange={e => setEdit(v => ({ ...v, balance: e.target.value }))}/></Field>

        <Field label="佣金余额"><input type="number" min="0" step="0.01" value={edit.commission_balance} onChange={e => setEdit(v => ({ ...v, commission_balance: e.target.value }))}/></Field>
        <Field label="佣金比例（%）"><input type="number" min="0" max="100" value={edit.commission_rate} onChange={e => setEdit(v => ({ ...v, commission_rate: e.target.value }))}/></Field>
        <Field label="折扣（%）"><input type="number" min="0" max="100" value={edit.discount} onChange={e => setEdit(v => ({ ...v, discount: e.target.value }))}/></Field>
        <Field label="限速（Mbps）"><input type="number" min="0" value={edit.speed_limit} onChange={e => setEdit(v => ({ ...v, speed_limit: e.target.value }))}/></Field>

        <Field label="设备限制"><input type="number" min="0" value={edit.device_limit} onChange={e => setEdit(v => ({ ...v, device_limit: e.target.value }))}/></Field>
        <Field label="邀请人邮箱"><input value={edit.invite_user_email} onChange={e => setEdit(v => ({ ...v, invite_user_email: e.target.value }))}/></Field>

        <label className="field settings-field-wide"><span>备注</span><textarea value={edit.remarks} onChange={e => setEdit(v => ({ ...v, remarks: e.target.value }))}/></label>
        <label className="check-field settings-field-wide"><input type="checkbox" checked={edit.banned} onChange={e => setEdit(v => ({ ...v, banned: e.target.checked }))}/><span>封禁用户</span></label>
      </div>

      <div className="card-actions">
        <button className="button" onClick={onClose}>取消</button>
        <button className="button primary" disabled={editMutation.isPending} onClick={saveEdit}>{editMutation.isPending ? '保存中…' : '保存用户'}</button>
      </div>
    </div> : <div className="form-stack">
      <Field label="邮箱 *"><input type="email" value={create.email} onChange={e => setCreate(v => ({ ...v, email: e.target.value }))} placeholder="user@example.com"/></Field>
      <Field label="密码"><input type="password" value={create.password} onChange={e => setCreate(v => ({ ...v, password: e.target.value }))} placeholder="留空则默认使用邮箱"/></Field>
      <Field label="套餐"><select value={create.plan_id} onChange={e => setCreate(v => ({ ...v, plan_id: e.target.value }))}>
        <option value="">无套餐</option>
        {plans.map(plan => <option key={plan.id} value={plan.id}>{plan.name}</option>)}
      </select></Field>
      <Field label="到期时间"><input type="datetime-local" value={create.expired_at} onChange={e => setCreate(v => ({ ...v, expired_at: e.target.value }))}/></Field>
      <div className="card-actions">
        <button className="button" onClick={onClose}>取消</button>
        <button className="button primary" disabled={createMutation.isPending || !create.email.trim()} onClick={() => createMutation.mutate()}>{createMutation.isPending ? '创建中…' : '创建用户'}</button>
      </div>
    </div>}
  </Modal>
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <label className="field"><span>{label}</span>{children}</label>
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
