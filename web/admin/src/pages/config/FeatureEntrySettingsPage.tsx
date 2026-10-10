import { zodResolver } from '@hookform/resolvers/zod'
import { useQuery } from '@tanstack/react-query'
import { useEffect } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { fetchSettings } from '../../api/config'
import { ConfigSectionFrame } from '../../components/config/ConfigSectionFrame'
import { SettingsAutosaveStatus, useSettingsAutosave } from '../../components/config/SettingsAutosave'

const schema = z.object({
  invite_enable: z.coerce.boolean().optional(),
  commission_enable: z.coerce.boolean().optional(),
  gift_card_enable: z.coerce.boolean().optional(),
  coupon_enable: z.coerce.boolean().optional(),
  ticket_enable: z.coerce.boolean().optional(),
  knowledge_enable: z.coerce.boolean().optional(),
  traffic_log_enable: z.coerce.boolean().optional(),
  announcement_enable: z.coerce.boolean().optional(),
  register_enable: z.coerce.boolean().optional(),
})

type Values = z.infer<typeof schema>

function validateFeatureSettings(value: unknown) {
  const parsed = schema.safeParse(value)
  if (parsed.success) return { success: true as const, data: parsed.data }
  const issue = parsed.error.issues[0]
  return {
    success: false as const,
    message: issue ? `${issue.path.join('.') || '表单'}：${issue.message}` : '请检查输入内容',
  }
}

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

export function FeatureEntrySettingsPage() {
  const query = useQuery({
    queryKey: ['settings', 'site'],
    queryFn: () => fetchSettings('site'),
  })
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: {},
  })
  const { state: saveState, retry } = useSettingsAutosave({
    watch: form.watch,
    validate: validateFeatureSettings,
    settingKey: 'site',
  })

  useEffect(() => {
    if (query.data && !form.formState.isDirty) {
      form.reset(schema.parse(query.data))
    }
  }, [query.data, form])

  function toggle(key: keyof Values) {
    form.setValue(key, !Boolean(form.getValues(key)), {
      shouldDirty: true,
      shouldTouch: true,
    })
  }

  return (
    <ConfigSectionFrame
      title="功能入口"
      description="控制用户端内置功能入口是否启用。关闭入口不会改变其他系统配置。"
    >
      {query.isLoading ? (
        <p className="config-frame-loading">加载中…</p>
      ) : query.isError ? (
        <div className="query-feedback is-error">
          <span>功能入口配置加载失败</span>
          <button className="button" type="button" onClick={() => query.refetch()}>
            重试
          </button>
        </div>
      ) : (
        <form className="config-form-sections">
          <section className="config-form-section">
            <h3>用户功能</h3>
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

          <SettingsAutosaveStatus state={saveState} onRetry={retry} />
        </form>
      )}
    </ConfigSectionFrame>
  )
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
        aria-label={label}
        aria-checked={checked}
        className={`config-switch ${checked ? 'active' : ''}`}
        onClick={onToggle}
      >
        <span />
      </button>
    </div>
  )
}
