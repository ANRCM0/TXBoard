import { useMutation, useQuery } from '@tanstack/react-query'
import { Link2, Send } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { toast } from 'sonner'
import { fetchSettings, saveSettings, setTelegramWebhook, type Settings } from '../../api/config'
import { ConfigSectionFrame } from '../../components/config/ConfigSectionFrame'

export function TelegramSettingsPage() {
  const query = useQuery({ queryKey: ['settings', 'telegram'], queryFn: () => fetchSettings('telegram') })
  const [values, setValues] = useState<Settings>({})
  const [webhookResult, setWebhookResult] = useState<unknown>(null)
  const timer = useRef<number | undefined>()

  useEffect(() => {
    if (query.data) setValues(query.data)
  }, [query.data])

  const save = useMutation({
    mutationFn: (payload: Settings) => saveSettings(payload),
    onSuccess: () => toast.success('Telegram 配置已保存'),
  })
  const webhook = useMutation({
    mutationFn: () => setTelegramWebhook(String(values.telegram_bot_token || '')),
    onSuccess: data => {
      setWebhookResult(data)
      toast.success('Telegram Webhook 已设置')
    },
  })

  function patch(key: string, value: unknown) {
    setValues(prev => {
      const next = { ...prev, [key]: value }
      window.clearTimeout(timer.current)
      timer.current = window.setTimeout(() => save.mutate(next), 1000)
      return next
    })
  }

  return (
    <ConfigSectionFrame title="Telegram" description="配置 Bot Token、Webhook 和讨论群入口。">
      {query.isLoading ? <p className="config-frame-loading">加载中…</p> : (
        <div className="config-form-sections">
          <section className="config-form-section">
            <h3>Bot</h3>
            <label className="config-switch-field">
              <div>
                <strong>启用 Telegram Bot</strong>
                <small>启用登录、通知或其他 Telegram 集成功能。</small>
              </div>
              <button
                type="button"
                role="switch"
                aria-checked={Boolean(values.telegram_bot_enable)}
                className={`config-switch ${Boolean(values.telegram_bot_enable) ? 'active' : ''}`}
                onClick={() => patch('telegram_bot_enable', !Boolean(values.telegram_bot_enable))}
              >
                <span />
              </button>
            </label>

            <div className="config-form-fields">
              <label className="config-field config-field-wide">
                <span>Bot Token</span>
                <input
                  type="password"
                  value={String(values.telegram_bot_token ?? '')}
                  onChange={event => patch('telegram_bot_token', event.target.value)}
                  placeholder="123456:ABC..."
                />
                <small>Token 属于敏感凭据，请只在受信任环境中管理。</small>
              </label>
              <label className="config-field">
                <span>Webhook 基地址</span>
                <input
                  value={String(values.telegram_webhook_url ?? '')}
                  onChange={event => patch('telegram_webhook_url', event.target.value)}
                  placeholder="https://example.com"
                />
                <small>后端会自动拼接 /api/v1/guest/telegram/webhook；留空时使用 app_url。</small>
              </label>
              <label className="config-field">
                <span>讨论群链接</span>
                <input
                  value={String(values.telegram_discuss_link ?? '')}
                  onChange={event => patch('telegram_discuss_link', event.target.value)}
                  placeholder="https://t.me/..."
                />
              </label>
            </div>
          </section>

          <div className="config-action-row">
            <span>{save.isPending ? '保存中…' : '修改后 1 秒自动保存'}</span>
            <button
              className="button primary"
              disabled={webhook.isPending || !String(values.telegram_bot_token || '')}
              onClick={() => webhook.mutate()}
            >
              {webhook.isPending ? <Send size={16}/> : <Link2 size={16}/>}
              {webhook.isPending ? '设置中…' : '设置 Webhook'}
            </button>
          </div>

          {webhookResult !== null ? <pre className="code-block">{JSON.stringify(webhookResult, null, 2)}</pre> : null}
        </div>
      )}
    </ConfigSectionFrame>
  )
}
