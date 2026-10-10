import { useMutation, useQuery } from '@tanstack/react-query'
import { useEffect, useRef, useState } from 'react'
import { toast } from 'sonner'
import { fetchSettings, saveSettings, testSendMail, type Settings } from '../../api/config'
import { ConfigSectionFrame } from '../../components/config/ConfigSectionFrame'
import { MailTemplateManager } from '../../components/config/MailTemplateManager'

const fields = [
  ['email_host', 'SMTP 主机', 'smtp.example.com'],
  ['email_port', 'SMTP 端口', '465'],
  ['email_encryption', '加密方式', 'ssl / tls'],
  ['email_username', '用户名', ''],
  ['email_password', '密码', ''],
  ['email_from_address', '发件地址', 'noreply@example.com'],
] as const

export function EmailSettingsPage() {
  const query = useQuery({ queryKey: ['settings', 'email'], queryFn: () => fetchSettings('email') })
  const [form, setForm] = useState<Settings>({})
  const [tab, setTab] = useState<'settings' | 'templates'>('settings')
  const timer = useRef<number | undefined>()

  useEffect(() => {
    if (query.data) setForm(query.data)
  }, [query.data])

  const save = useMutation({
    mutationFn: (payload: Settings) => saveSettings(payload),
    onSuccess: () => toast.success('邮件配置已保存'),
  })
  const test = useMutation({
    mutationFn: () => testSendMail(),
    onSuccess: () => toast.success('测试邮件已发送'),
  })

  function patch(key: string, value: unknown) {
    setForm(prev => {
      const next = { ...prev, [key]: value }
      window.clearTimeout(timer.current)
      timer.current = window.setTimeout(() => save.mutate(next), 1000)
      return next
    })
  }

  return (
    <ConfigSectionFrame title="邮件设置" description="配置 SMTP 发送通道并维护邮件模板。">
      <div className="config-inner-tabs">
        <button className={tab === 'settings' ? 'active' : ''} onClick={() => setTab('settings')}>邮件配置</button>
        <button className={tab === 'templates' ? 'active' : ''} onClick={() => setTab('templates')}>邮件模板</button>
      </div>

      {tab === 'settings' ? (
        query.isLoading ? <p className="config-frame-loading">加载中…</p> : (
          <div className="config-form-sections">
            <section className="config-form-section">
              <h3>SMTP</h3>
              <div className="config-form-fields">
                {fields.map(([key, label, placeholder]) => (
                  <label className="config-field" key={key}>
                    <span>{label}</span>
                    <input
                      type={key === 'email_password' ? 'password' : 'text'}
                      value={String(form[key] ?? '')}
                      placeholder={placeholder}
                      onChange={event => patch(key, event.target.value)}
                    />
                  </label>
                ))}
              </div>
            </section>

            <section className="config-form-section">
              <h3>提醒邮件</h3>
              <label className="config-switch-field">
                <div>
                  <strong>启用提醒邮件</strong>
                  <small>用于到期、流量等系统提醒。</small>
                </div>
                <button
                  type="button"
                  role="switch"
                  aria-checked={Boolean(form.remind_mail_enable)}
                  className={`config-switch ${Boolean(form.remind_mail_enable) ? 'active' : ''}`}
                  onClick={() => patch('remind_mail_enable', !Boolean(form.remind_mail_enable))}
                >
                  <span />
                </button>
              </label>
            </section>

            <div className="config-action-row">
              <span>{save.isPending ? '保存中…' : '修改后 1 秒自动保存'}</span>
              <button className="button" disabled={test.isPending} onClick={() => test.mutate()}>
                {test.isPending ? '发送中…' : '测试 SMTP'}
              </button>
            </div>
          </div>
        )
      ) : (
        <div className="config-template-wrap"><MailTemplateManager /></div>
      )}
    </ConfigSectionFrame>
  )
}
