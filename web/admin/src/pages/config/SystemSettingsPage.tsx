import { zodResolver } from '@hookform/resolvers/zod'
import { useQuery } from '@tanstack/react-query'
import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { fetchSettings, type Settings } from '../../api/config'
import { ConfigSectionFrame } from '../../components/config/ConfigSectionFrame'
import { SettingsAutosaveStatus, useSettingsAutosave } from '../../components/config/SettingsAutosave'

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
  traffic_warn_rate: optionalNumber,
})
type Values = z.infer<typeof schema>

// Empty optional text settings are stored as null by the API. Form input
// values must be strings; otherwise an unrelated null blocks every autosave.
const nullableTextKeys = [
  'app_name', 'app_description', 'app_url', 'logo',
  'subscribe_url', 'tos_url', 'currency', 'currency_symbol',
] as const

export function normalizeSystemSettings(settings: Settings): Values {
  const values = { ...settings }
  for (const key of nullableTextKeys) {
    if (values[key] == null) values[key] = ''
  }
  return schema.parse(values)
}

function validateSystemSettings(value: unknown) {
  const parsed = schema.safeParse(value)
  if (parsed.success) return { success: true as const, data: parsed.data }
  const issue = parsed.error.issues[0]
  return {
    success: false as const,
    message: issue ? `${issue.path.join('.') || '表单'}：${issue.message}` : '请检查输入内容',
  }
}

export function SystemSettingsPage() {
  const query = useQuery({ queryKey: ['settings', 'site'], queryFn: () => fetchSettings('site') })
  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: {} })
  const { state: saveState, retry } = useSettingsAutosave({
    watch: form.watch,
    validate: validateSystemSettings,
    settingKey: 'site',
  })

  useEffect(() => {
    if (query.data && !form.formState.isDirty) form.reset(normalizeSystemSettings(query.data))
  }, [query.data, form])

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
      ) : query.isError ? (
        <div className="query-feedback is-error" role="alert">
          <span>站点配置加载失败，无法保存修改。</span>
          <button type="button" className="button" onClick={() => query.refetch()}>重试加载</button>
        </div>
      ) : (
        <form className="config-form-sections">
          <section className="config-form-section">
            <h3>基础信息</h3>
            <div className="config-form-fields">
              <Field label="站点名称"><input {...form.register('app_name')} /></Field>
              <Field label="站点描述"><input {...form.register('app_description')} /></Field>
              <Field label="站点 URL"><input {...form.register('app_url')} /></Field>
              <Field label="Logo URL"><input {...form.register('logo')} /></Field>
              <Field label="订阅 URL" className="config-field-wide"><textarea {...form.register('subscribe_url')} /></Field>
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

          <SettingsAutosaveStatus state={saveState} onRetry={retry} />
        </form>
      )}
    </ConfigSectionFrame>
  )
}

function Field({
  label,
  className = '',
  children,
}: {
  label: string
  className?: string
  children: React.ReactNode
}) {
  return <label className={`config-field ${className}`.trim()}><span>{label}</span>{children}</label>
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
