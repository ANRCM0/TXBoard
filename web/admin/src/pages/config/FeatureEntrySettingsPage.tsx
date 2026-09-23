import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useEffect, useRef } from 'react'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { fetchSettings, saveSettings } from '../../api/config'
import { ConfigSectionFrame } from '../../components/config/ConfigSectionFrame'

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
  const timer = useRef<number | undefined>(undefined)

  const mutation = useMutation({
    mutationFn: (values: Values) => saveSettings(values),
    onSuccess: () => toast.success('已自动保存'),
  })

  useEffect(() => {
    if (query.data && !form.formState.isDirty) {
      form.reset(schema.parse(query.data))
    }
  }, [query.data, form])

  useEffect(() => {
    const sub = form.watch((value, info) => {
      if (!info.type && !info.name) return
      const parsed = schema.safeParse(value)
      if (!parsed.success) return
      window.clearTimeout(timer.current)
      timer.current = window.setTimeout(() => mutation.mutate(parsed.data), 1000)
    })

    return () => {
      sub.unsubscribe()
      window.clearTimeout(timer.current)
    }
  }, [form, mutation])

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

          <div className="config-autosave" role="status" aria-live="polite">
            {mutation.isPending
              ? '正在保存…'
              : mutation.isError
                ? '保存失败，请再次修改或重试'
                : '修改后 1 秒自动保存'}
          </div>
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
