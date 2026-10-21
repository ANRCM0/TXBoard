import { t, useLocale } from '../lib/i18n'
import { useEffect, useState, type FormEvent } from 'react'
import { useSearchParams } from 'react-router-dom'
import { Input, Select } from '../components/ui/Field'
import { orderPeriod, orderType } from './orderPresentation'
import { orderStatus } from './readPresentation'
import './list-rule-filter.css'

type Kind = 'text' | 'number' | 'timestamp' | 'boolean' | 'enum'
type Field = { label: string; kind: Kind; nullable?: boolean; values?: string[] }
export type ListRule = { field: string; operator: string; value: string }
type Definition = { fields: Record<string, Field>; sorts: string[]; defaultSort?: string }
export const accountRules: Definition = { fields: {
  id: { label: '用户 ID', kind: 'number' }, email: { label: '邮箱', kind: 'text' },
  banned: { label: '封禁状态', kind: 'boolean' }, is_admin: { label: '管理员', kind: 'boolean' },
  created_at: { label: '注册时间', kind: 'timestamp', nullable: true }, last_login_at: { label: '最近登录', kind: 'timestamp', nullable: true },
}, sorts: ['id', 'email', 'created_at', 'last_login_at'] }
export const orderRules: Definition = { fields: {
  id: { label: '订单 ID', kind: 'number' }, user_id: { label: '用户 ID', kind: 'number' }, plan_id: { label: '套餐 ID', kind: 'number' },
  trade_no: { label: '订单号', kind: 'text' }, status: { label: '订单状态', kind: 'enum', values: ['0', '1', '2', '3', '4'] },
  type: { label: '订单类型', kind: 'enum', values: ['1', '2', '3', '4'] },
  period: { label: '购买周期', kind: 'enum', values: ['monthly', 'quarterly', 'half_yearly', 'yearly', 'two_yearly', 'three_yearly', 'onetime', 'reset_traffic'] },
  created_at: { label: '创建时间', kind: 'timestamp' }, paid_at: { label: '支付时间', kind: 'timestamp', nullable: true },
  amount_minor: { label: '金额（最小货币单位）', kind: 'number' }, currency: { label: '币种', kind: 'text' },
}, sorts: ['id', 'created_at', 'paid_at', 'amount_minor'], defaultSort: 'created_at' }
const labels: Record<string, string> = { eq: '等于', contains: '包含', starts_with: '开头为', gt: '大于', gte: '大于等于', lt: '小于', lte: '小于等于', is_null: '未设置', not_null: '已设置' }
function operators(field: Field) {
  return [...(field.kind === 'text' ? ['eq', 'contains', 'starts_with'] : ['boolean', 'enum'].includes(field.kind) ? ['eq'] : ['eq', 'gt', 'gte', 'lt', 'lte']), ...(field.nullable ? ['is_null', 'not_null'] : [])]
}
function choiceLabel(field: string, value: string): string {
  if (field === 'status') return orderStatus(Number(value))
  if (field === 'type') return orderType(Number(value))
  if (field === 'period') return orderPeriod(value)
  return t(value === '1' ? '是' : '否')
}
export function parseListRules(params: URLSearchParams, definition: Definition): { valid: boolean; filters: ListRule[]; sort_by: string; sort_direction: 'asc' | 'desc'; error: string } {
  let filters: ListRule[] = []
  const sort_by = params.get('sort_by') ?? definition.defaultSort ?? 'id', sort_direction = params.get('sort_direction') ?? 'desc'
  let valid = definition.sorts.includes(sort_by) && ['asc', 'desc'].includes(sort_direction)
  try {
    const value: unknown = JSON.parse(params.get('filters') ?? '[]')
    if (!Array.isArray(value) || value.length > 10) valid = false
    else {
      filters = value
      for (const rule of filters) {
        const field = rule && definition.fields[rule.field]
        if (!field || Object.keys(rule).some(key => !['field', 'operator', 'value'].includes(key)) || !operators(field).includes(rule.operator) || typeof rule.value !== 'string') { valid = false; continue }
        if (['is_null', 'not_null'].includes(rule.operator)) { if (rule.value !== '') valid = false; continue }
        if (field.kind === 'text' && (!rule.value || rule.value.length > 254 || /[\x00-\x1f\x7f]/.test(rule.value))) valid = false
        if (field.kind === 'boolean' && !['0', '1'].includes(rule.value)) valid = false
        if (field.kind === 'enum' && !field.values?.includes(rule.value)) valid = false
        if (['number', 'timestamp'].includes(field.kind) && (!/^(0|[1-9][0-9]{0,18})$/.test(rule.value) || BigInt(rule.value) > (field.kind === 'timestamp' ? 9999999999n : 9223372036854775807n))) valid = false
        if (['id', 'user_id', 'plan_id'].includes(rule.field) && rule.value === '0') valid = false
        if (rule.field === 'currency' && (rule.operator !== 'eq' || !/^[A-Z]{3}$/.test(rule.value))) valid = false
      }
    }
  } catch { valid = false }
  const amount = sort_by === 'amount_minor' || filters.some(rule => rule?.field === 'amount_minor')
  if (amount && !filters.some(rule => rule?.field === 'currency' && rule.operator === 'eq' && /^[A-Z]{3}$/.test(rule.value))) return { valid: false, filters, sort_by, sort_direction: 'desc', error: '金额筛选和排序需要添加一个币种等于条件，例如 CNY。' }
  return { valid, filters, sort_by, sort_direction: sort_direction === 'asc' ? 'asc' : 'desc', error: valid ? '' : '高级筛选参数有误，请修正或清除。' }
}
export function ListRuleFilter({ definition }: { definition: Definition }) {
  useLocale()
  const [params, setParams] = useSearchParams(), key = params.get('filters') ?? '[]'
  const applied = parseListRules(params, definition)
  const [rules, setRules] = useState<ListRule[]>(applied.valid ? applied.filters : [])
  const [sort, setSort] = useState(applied.sort_by), [direction, setDirection] = useState(applied.sort_direction), [error, setError] = useState('')
  useEffect(() => { const current = parseListRules(params, definition); setRules(current.valid ? current.filters : []); setSort(current.sort_by); setDirection(current.sort_direction); setError(current.error) }, [key, params.get('sort_by'), params.get('sort_direction'), definition])
  function update(index: number, patch: Partial<ListRule>) { setRules(current => current.map((rule, i) => i === index ? { ...rule, ...patch } : rule)) }
  function submit(event: FormEvent) {
    event.preventDefault()
    const next = new URLSearchParams(params); next.delete('page'); next.set('sort_by', sort); next.set('sort_direction', direction)
    if (rules.length) next.set('filters', JSON.stringify(rules)); else next.delete('filters')
    const result = parseListRules(next, definition); setError(result.error); if (result.valid) setParams(next)
  }
  return <details className="list-rule-filter"><summary>{t("高级筛选与排序")}{applied.filters.length ? t("（{v0} 条 AND 条件）", { v0: applied.filters.length }) : ''}</summary>
    <form onSubmit={submit}><p>{t("所有条件同时满足，查询和排序作用于全部分页。时间值使用 UTC Unix 秒，金额使用精确整数最小货币单位。")}</p>
      {rules.map((rule, index) => <div className="list-rule-row" key={index}>
        <Select aria-label={t("条件 {v0} 字段", { v0: index + 1 })} value={rule.field} onChange={event => update(index, { field: event.target.value, operator: 'eq', value: '' })}>{Object.entries(definition.fields).map(([key, field]) => <option key={key} value={key}>{t(field.label)}</option>)}</Select>
        <Select aria-label={t("条件 {v0} 比较", { v0: index + 1 })} value={rule.operator} onChange={event => update(index, { operator: event.target.value, ...(['is_null', 'not_null'].includes(event.target.value) ? { value: '' } : {}) })}>{operators(definition.fields[rule.field]).map(value => <option key={value} value={value}>{t(labels[value])}</option>)}</Select>
        {['boolean', 'enum'].includes(definition.fields[rule.field].kind) ? <Select aria-label={t("条件 {v0} 值", { v0: index + 1 })} value={rule.value} onChange={event => update(index, { value: event.target.value })}>
          <option value="">{t('请选择')}</option>{(definition.fields[rule.field].values ?? ['1', '0']).map(value => <option key={value} value={value}>{choiceLabel(rule.field, value)}</option>)}
        </Select> : <Input aria-label={t("条件 {v0} 值", { v0: index + 1 })} value={rule.value} maxLength={254} disabled={['is_null', 'not_null'].includes(rule.operator)} onChange={event => update(index, { value: event.target.value })} />}
        <button type="button" className="button" onClick={() => setRules(current => current.filter((_, i) => i !== index))} aria-label={t("删除条件 {v0}", { v0: index + 1 })}>{t("删除")}</button>
      </div>)}
      <div className="list-rule-actions"><button type="button" className="button" disabled={rules.length >= 10} onClick={() => setRules(current => [...current, { field: Object.keys(definition.fields)[0], operator: 'eq', value: '' }])}>{t("添加条件")}</button>
        <label>{t("排序字段")}<Select aria-label={t("排序字段")} value={sort} onChange={event => setSort(event.target.value)}>{definition.sorts.map(key => <option key={key} value={key}>{t(definition.fields[key].label)}</option>)}</Select></label>
        <label>{t("排序方向")}<Select aria-label={t("排序方向")} value={direction} onChange={event => setDirection(event.target.value as 'asc' | 'desc')}><option value="desc">{t("降序")}</option><option value="asc">{t("升序")}</option></Select></label>
        <button className="button primary">{t("应用高级查询")}</button><button type="button" className="button" onClick={() => { const next = new URLSearchParams(params); ['filters', 'sort_by', 'sort_direction', 'page'].forEach(key => next.delete(key)); setParams(next) }}>{t("清除高级查询")}</button>
      </div>{error ? <p role="alert">{t(error)}</p> : null}
    </form>
  </details>
}
