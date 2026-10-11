import type { PlanItem, PlanPrices, PlanSavePayload } from '../../api/finance'

export const planPeriods = [
  ['monthly', '月付'], ['quarterly', '季付'], ['half_yearly', '半年付'],
  ['yearly', '年付'], ['two_yearly', '两年付'], ['three_yearly', '三年付'],
  ['onetime', '一次性'], ['reset_traffic', '重置流量'],
] as const

export type PlanPeriod = (typeof planPeriods)[number][0]
export const primaryPeriods: readonly PlanPeriod[] = ['monthly', 'quarterly', 'yearly']

export type PlanDraft = {
  name: string
  content: string
  group_id: string
  transfer_enable: string
  speed_limit: string
  device_limit: string
  capacity_limit: string
  reset_traffic_method: string
  tags: string
  prices: Record<PlanPeriod, string>
  force_update: boolean
}
export type PlanFieldError = Partial<Record<string, string>>

export function draftFromPlan(plan?: PlanItem | null): PlanDraft {
  return {
    name: plan?.name || '',
    content: plan?.content || '',
    group_id: plan?.group_id == null ? '' : String(plan.group_id),
    transfer_enable: String(plan?.transfer_enable ?? 100),
    speed_limit: plan?.speed_limit == null ? '' : String(plan.speed_limit),
    device_limit: plan?.device_limit == null ? '' : String(plan.device_limit),
    capacity_limit: plan?.capacity_limit == null ? '' : String(plan.capacity_limit),
    reset_traffic_method: plan?.reset_traffic_method == null ? '' : String(plan.reset_traffic_method),
    tags: Array.isArray(plan?.tags) ? plan.tags.join(', ') : '',
    prices: Object.fromEntries(planPeriods.map(([key]) => [key, plan?.prices?.[key] == null ? '' : String(plan.prices[key])])) as Record<PlanPeriod,string>,
    force_update: false,
  }
}

function nonNegative(value: string, integer = false) {
  if (!value.trim()) return true
  const numeric = Number(value)
  return Number.isFinite(numeric) && numeric >= 0 && (!integer || Number.isSafeInteger(numeric))
}

export function validatePlanDraft(draft: PlanDraft): PlanFieldError {
  const errors: PlanFieldError = {}
  if (!draft.name.trim()) errors.name = '请输入套餐名称。'
  const amount = Number(draft.transfer_enable)
  if (!draft.transfer_enable.trim() || !Number.isSafeInteger(amount) || amount < 1) {
    errors.transfer_enable = '流量配额必须是大于 0 的整数（GB）。'
  }
  if (!nonNegative(draft.speed_limit)) errors.speed_limit = '限速必须是大于或等于 0 的数字。'
  if (!nonNegative(draft.device_limit,true)) errors.device_limit = '设备限制必须是大于或等于 0 的整数。'
  if (!nonNegative(draft.capacity_limit,true)) errors.capacity_limit = '人数上限必须是大于或等于 0 的整数。'
  if (!nonNegative(draft.group_id,true)) errors.group_id = '权限组编号无效。'
  if (!nonNegative(draft.reset_traffic_method,true)) errors.reset_traffic_method = '流量重置方式无效。'
  for (const [key,label] of planPeriods) {
    const raw = draft.prices[key].trim()
    if (!raw) continue
    const value = Number(raw)
    if (!Number.isFinite(value) || value <= 0) errors['prices.'+key] = label + '价格必须大于 0。'
    else if (Number(value.toFixed(2)) <= 0) errors['prices.'+key] = label + '价格不能小于 0.01 元。'
  }
  return errors
}

function optionalNumber(value: string) {
  return value.trim() === '' ? null : Number(value)
}

/** Only construct native API payload after validation succeeds. */
export function planPayload(draft: PlanDraft, plan?: PlanItem | null): PlanSavePayload {
  const errors = validatePlanDraft(draft)
  if (Object.keys(errors).length) throw new Error('套餐表单校验失败')
  const prices: PlanPrices = {}
  for (const [key] of planPeriods) {
    if (draft.prices[key].trim()) prices[key] = Number(Number(draft.prices[key]).toFixed(2))
  }
  return {
    ...(plan ? {id:plan.id} : {}),
    name: draft.name.trim(),
    content: draft.content || null,
    group_id: optionalNumber(draft.group_id),
    transfer_enable: Number(draft.transfer_enable),
    speed_limit: optionalNumber(draft.speed_limit),
    device_limit: optionalNumber(draft.device_limit),
    capacity_limit: optionalNumber(draft.capacity_limit),
    reset_traffic_method: optionalNumber(draft.reset_traffic_method),
    prices,
    tags: draft.tags.split(',').map(value => value.trim()).filter(Boolean),
    ...(plan && draft.force_update ? {force_update:true} : {}),
  }
}

export function discountedPlanPrices(monthlyInput: string): Partial<Record<PlanPeriod,string>> | null {
  const monthly = Number(monthlyInput)
  if (!Number.isFinite(monthly) || monthly <= 0) return null
  const discounts = {
    quarterly: 3 * .95,
    half_yearly: 6 * .90,
    yearly: 12 * .85,
    two_yearly: 24 * .80,
    three_yearly: 36 * .75,
  }
  return Object.fromEntries(Object.entries(discounts).map(([key,value]) =>
    [key, (monthly * value).toFixed(2)]))
}
