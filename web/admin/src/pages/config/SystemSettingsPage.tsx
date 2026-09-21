import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useEffect, useRef } from 'react'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { fetchSettings, saveSettings } from '../../api/config'
import { ConfigSectionFrame } from '../../components/config/ConfigSectionFrame'

const optionalNumber = z.preprocess(value => value === '' ? null : value, z.coerce.number().nullish())

const schema = z.object({
  app_name: z.string().optional(),
  app_description: z.string().optional(),
  app_url: z.string().optional(),
  logo: z.string().optional(),
  subscribe_url: z.string().optional(),
  try_out_plan_id: optionalNumber,
  try_out_hour: optionalNumber,
  tos_url: z.string().optional(),
  currency: z.string().optional(),
  currency_symbol: z.string().optional(),
  force_https: z.coerce.boolean().optional(),
  stop_register: z.coerce.boolean().optional(),
  ticket_must_wait_reply: z.coerce.boolean().optional(),
  invite_enable: z.coerce.boolean().optional(),
  commission_enable: z.coerce.boolean().optional(),
  gift_card_enable: z.coerce.boolean().optional(),
  coupon_enable: z.coerce.boolean().optional(),
  ticket_enable: z.coerce.boolean().optional(),
  knowledge_enable: z.coerce.boolean().optional(),
  traffic_log_enable: z.coerce.boolean().optional(),
  announcement_enable: z.coerce.boolean().optional(),
  register_enable: z.coerce.boolean().optional(),
  traffic_warn_rate: optionalNumber,
})
type Values = z.infer<typeof schema>

// Built-in user routes an operator can hide. Missing/never-set flags default to
// enabled on the backend, so an untouched install keeps every entry.
const featureSwitches = [
  ['invite_enable', '邀请'],
  ['commission_enable', '佣金 / 提现'],
  ['gift_card_enable', '礼品卡'],
  ['coupon_enable', '优惠券'],
  ['ticket_enable', '工单'],
  ['knowledge_enable', '知识库'],
  ['traffic_log_enable', '流量日志'],
  ['announcement_enable', '公告'],
  ['register_enable', '注册'],
] as const satisfies ReadonlyArray<readonly [keyof Values, string]>

export function SystemSettingsPage() {
  const query = useQuery({ queryKey: ['settings', 'site'], queryFn: () => fetchSettings('site') })
  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: {} })
  const timer = useRef<number | undefined>(undefined)
  const mutation = useMutation({
    mutationFn: (values: Values) => saveSettings(values),
    onSuccess: () => toast.success('已自动保存'),
  })

  useEffect(() => {
    if (query.data && !form.formState.isDirty) form.reset(query.data as Values)
  }, [query.data, form])

  useEffect(() => {
    const sub = form.watch((value, info) => {
      // reset() notifications carry neither name nor type; ignore them so
      // hydrating the form never triggers an autosave.
      if (!info.type && !info.name) return
      const parsed = schema.safeParse(value)
      if (!parsed.success) return
      window.clearTimeout(timer.current)
      timer.current = window.setTimeout(() => mutation.mutate(parsed.data), 1000)
    })
    return () => sub.unsubscribe()
  }, [form, mutation])

  function toggle(key: keyof Values) {
    form.setValue(key, !Boolean(form.getValues(key)), { shouldDirty: true })
  }

  return (
    <ConfigSectionFrame
      title="站点设置"
      description="配置站点名称、访问地址、品牌信息、货币与基础注册行为。"
    >
      {query.isLoading ? (
        <p className="config-frame-loading">加载中…</p>
      ) : (
        <form className="config-form-sections">
          <section className="config-form-section">
            <h3>基础信息</h3>
            <div className="config-form-fields">
              <Field label="站点名称"><input {...form.register('app_name')} /></Field>
              <Field label="站点描述"><input {...form.register('app_description')} /></Field>
              <Field label="站点 URL"><input {...form.register('app_url')} /></Field>
              <Field label="Logo URL"><input {...form.register('logo')} /></Field>
              <Field label="订阅 URL"><textarea {...form.register('subscribe_url')} /></Field>
              <Field label="服务条款 URL"><input {...form.register('tos_url')} /></Field>
            </div>
          </section>

          <section className="config-form-section">
            <h3>试用与货币</h3>
            <div className="config-form-fields">
              <Field label="试用套餐 ID"><input type="number" {...form.register('try_out_plan_id')} /></Field>
              <Field label="试用时长（小时）"><input type="number" {...form.register('try_out_hour')} /></Field>
              <Field label="货币"><input {...form.register('currency')} /></Field>
              <Field label="货币符号"><input {...form.register('currency_symbol')} /></Field>
            </div>
          </section>

          <section className="config-form-section">
            <h3>站点行为</h3>
            <div className="config-switch-list">
              <SwitchField label="强制 HTTPS" checked={Boolean(form.watch('force_https'))} onToggle={() => toggle('force_https')} />
              <SwitchField label="停止注册" checked={Boolean(form.watch('stop_register'))} onToggle={() => toggle('stop_register')} />
              <SwitchField label="工单必须等待回复" checked={Boolean(form.watch('ticket_must_wait_reply'))} onToggle={() => toggle('ticket_must_wait_reply')} />
            </div>
            <div className="config-form-fields">
              <Field label="流量预警比例（%）"><input type="number" min="0" max="100" {...form.register('traffic_warn_rate')} /></Field>
            </div>
          </section>

          <section className="config-form-section">
            <h3>功能入口</h3>
            <div className="config-switch-list">
              {featureSwitches.map(([key, label]) => (
                <SwitchField
                  key={key}
                  label={label}
                  checked={Boolean(form.watch(key))}
                  onToggle={() => toggle(key)}
                />
              ))}
            </div>
          </section>

          <div className="config-autosave">
            {mutation.isPending ? '正在保存…' : '修改后 1 秒自动保存'}
          </div>
        </form>
      )}
    </ConfigSectionFrame>
  )
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return <label className="config-field"><span>{label}</span>{children}</label>
}

function SwitchField({
  label,
  checked,
  onToggle,
}: {
  label: string
  checked: boolean
  onToggle: () => void
}) {
  return (
    <div className="config-switch-field">
      <strong>{label}</strong>
      <button
        type="button"
        role="switch"
        aria-checked={checked}
        className={`config-switch ${checked ? 'active' : ''}`}
        onClick={onToggle}
      >
        <span />
      </button>
    </div>
  )
}
