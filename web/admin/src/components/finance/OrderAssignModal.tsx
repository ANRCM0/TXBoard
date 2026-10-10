import { useMutation } from '@tanstack/react-query'
import { useEffect, useMemo, useState } from 'react'
import { toast } from 'sonner'
import { assignOrder, type PlanItem } from '../../api/finance'
import { Modal } from '../ui/Modal'

const periodMap = {
  monthly: 'month_price',
  quarterly: 'quarter_price',
  half_yearly: 'half_year_price',
  yearly: 'year_price',
  two_yearly: 'two_year_price',
  three_yearly: 'three_year_price',
  onetime: 'onetime_price',
  reset_traffic: 'reset_price',
} as const

const labels: Record<string, string> = {
  monthly: '月付',
  quarterly: '季付',
  half_yearly: '半年付',
  yearly: '年付',
  two_yearly: '两年付',
  three_yearly: '三年付',
  onetime: '一次性',
  reset_traffic: '重置流量',
}

export function OrderAssignModal({
  open,
  plans,
  onClose,
  onCreated,
}: {
  open: boolean
  plans: PlanItem[]
  onClose: () => void
  onCreated: () => void
}) {
  const [email, setEmail] = useState('')
  const [planId, setPlanId] = useState('')
  const [period, setPeriod] = useState('')
  const [amount, setAmount] = useState('')

  const selectedPlan = useMemo(
    () => plans.find(plan => String(plan.id) === planId),
    [plans, planId],
  )

  const periods = useMemo(() => {
    if (!selectedPlan?.prices) return []
    return Object.entries(selectedPlan.prices)
      .filter(([key, value]) => key in periodMap && Number(value) > 0)
      .map(([key, value]) => ({
        key,
        legacy: periodMap[key as keyof typeof periodMap],
        price: Number(value),
      }))
  }, [selectedPlan])

  useEffect(() => {
    if (!open) return
    setEmail('')
    setPlanId('')
    setPeriod('')
    setAmount('')
  }, [open])

  useEffect(() => {
    if (!period) return
    const item = periods.find(entry => entry.key === period)
    if (item) setAmount(item.price.toFixed(2))
  }, [period, periods])

  const mutation = useMutation({
    mutationFn: () => {
      const entry = periods.find(item => item.key === period)
      if (!entry) throw new Error('请选择有效周期')
      return assignOrder({
        email: email.trim(),
        plan_id: Number(planId),
        period: entry.legacy,
        total_amount: Math.round(Number(amount) * 100),
      })
    },
    onSuccess: tradeNo => {
      toast.success(`订单已创建：${tradeNo}`)
      onCreated()
      onClose()
    },
  })

  function submit() {
    if (!periods.some(item => item.key === period)) {
      toast.error('请选择有效周期')
      return
    }
    mutation.mutate()
  }

  return (
    <Modal
      open={open}
      title="手动创建订单"
      subtitle="为指定用户直接创建已有套餐订单，金额提交时换算为后端使用的分。"
      onClose={onClose}
      footer={
        <div className="content-editor-footer">
          <button className="button" onClick={onClose} disabled={mutation.isPending}>取消</button>
          <button
            className="button primary"
            disabled={mutation.isPending || !email.trim() || !planId || !period || !amount.trim() || !(Number(amount) >= 0)}
            onClick={submit}
          >
            {mutation.isPending ? '创建中…' : '创建订单'}
          </button>
        </div>
      }
    >
      <div className="form-stack">
        <label className="field">
          <span>用户邮箱 *</span>
          <input autoFocus type="email" value={email} onChange={event => setEmail(event.target.value)} placeholder="user@example.com"/>
        </label>
        <div className="two-col">
          <label className="field">
            <span>套餐 *</span>
            <select value={planId} onChange={event => { setPlanId(event.target.value); setPeriod(''); setAmount('') }}>
              <option value="">请选择套餐</option>
              {plans.map(plan => <option key={plan.id} value={plan.id}>{plan.name}</option>)}
            </select>
          </label>
          <label className="field">
            <span>周期 *</span>
            <select value={period} onChange={event => setPeriod(event.target.value)} disabled={!selectedPlan}>
              <option value="">请选择周期</option>
              {periods.map(item => (
                <option key={item.key} value={item.key}>
                  {labels[item.key] || item.key} · ¥ {item.price.toFixed(2)}
                </option>
              ))}
            </select>
          </label>
        </div>
        <label className="field">
          <span>订单金额 *</span>
          <span className="input-with-unit">
            <input type="number" min="0" step="0.01" value={amount} onChange={event => setAmount(event.target.value)}/>
            <span>¥</span>
          </span>
          <small className="field-help">默认跟随套餐周期价格，也可以在创建前调整。</small>
        </label>
      </div>
    </Modal>
  )
}
