import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useEffect, useRef } from 'react'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { fetchSettings, saveSettings } from '../../api/config'
import { ConfigSectionFrame } from '../../components/config/ConfigSectionFrame'

const schema = z.object({
  app_name: z.string().optional(),
  app_description: z.string().optional(),
  app_url: z.string().optional(),
  logo: z.string().optional(),
  subscribe_url: z.string().optional(),
  try_out_plan_id: z.coerce.number().optional(),
  try_out_hour: z.coerce.number().optional(),
  tos_url: z.string().optional(),
  currency: z.string().optional(),
  currency_symbol: z.string().optional(),
  force_https: z.coerce.boolean().optional(),
  stop_register: z.coerce.boolean().optional(),
  ticket_must_wait_reply: z.coerce.boolean().optional(),
})
type Values = z.infer<typeof schema>

export function SystemSettingsPage() {
  const query = useQuery({ queryKey: ['settings', 'site'], queryFn: () => fetchSettings('site') })
  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: {} })
  const timer = useRef<number | undefined>(undefined)
  const mutation = useMutation({
    mutationFn: (values: Values) => saveSettings(values),
    onSuccess: () => toast.success('已自动保存'),
  })

  useEffect(() => {
    if (query.data) form.reset(query.data as Values)
  }, [query.data, form])

  useEffect(() => {
    const sub = form.watch(value => {
      window.clearTimeout(timer.current)
      timer.current = window.setTimeout(() => mutation.mutate(value as Values), 1000)
    })
    return () => sub.unsubscribe()
  }, [form, mutation])

  function toggle(key: 'force_https' | 'stop_register' | 'ticket_must_wait_reply') {
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
