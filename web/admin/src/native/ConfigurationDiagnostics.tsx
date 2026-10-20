import { t, useLocale } from '../lib/i18n'
import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { CoreAdminApiError, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { managementError } from './Feedback'
import { requestConfirm } from '../components/ui/ConfirmDialog'

type TelegramStatus = CoreOutput<'settings.telegram.webhook.inspect'>

export function SmtpDiagnostics({ version, disabled }: { version: string; disabled: boolean }) {
  useLocale()
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const epoch = workflowActions.generation()
  const [result, setResult] = useState<CoreOutput<'settings.smtp.inspect'>>(), [message, setMessage] = useState(''), [busy, setBusy] = useState(false)
  const view = useRef({ version, epoch, mounted: true }); view.current = { version, epoch, mounted: true }
  useEffect(() => () => { view.current.mounted = false }, [])
  useEffect(() => { setResult(undefined); setMessage(''); setBusy(false) }, [version, epoch])
  async function inspect() {
    if (disabled || state.busy || busy) return
    const origin = { version, epoch }
    const current = () => view.current.mounted && view.current.version === origin.version && view.current.epoch === origin.epoch && workflowActions.generation() === origin.epoch
    setBusy(true); setResult(undefined); setMessage('')
    try {
      const result = await admin.read('settings.smtp.inspect', { expected_version: version })
      if (current()) setResult(result.data)
    } catch (error) { if (current()) setMessage(managementError(error, false)) }
    finally { if (current()) setBusy(false) }
  }
  return <section className="list-card settings-section settings-smtp-diagnostics" aria-label={t("SMTP 诊断")}>
    <header className="settings-section-heading"><div><h2>{t("SMTP 连接诊断")}</h2><p>{t("使用已保存的服务器、加密方式和认证凭据建立连接，不发送邮件。")}</p></div></header>
    {message ? <p role="alert">{t(message)}</p> : null}
    {result && !disabled ? <p className="settings-success-feedback" role="status">{t("SMTP 连接握手已完成")}{result.authentication_used ? t("，服务器已接受已保存账号的认证") : t("；本次未使用账号认证")}{t("。此结果不代表邮件已投递。")}</p> : null}
    <footer className="settings-form-actions"><span>{t("收件与投递可在邮件模板页另行测试。")}</span><button className="button" disabled={disabled || state.busy || busy} onClick={() => void inspect()}>{busy ? t("正在连接…") : t("诊断已保存的 SMTP")}</button></footer>
  </section>
}

export function ConfigurationDiagnostics({ version, disabled }: { version: string; disabled: boolean }) {
  useLocale()
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const epoch = workflowActions.generation()
  const [status, setStatus] = useState<TelegramStatus>(), [message, setMessage] = useState(''), [busy, setBusy] = useState(false)
  const view = useRef({ version, epoch, mounted: true }); view.current = { version, epoch, mounted: true }
  useEffect(() => () => { view.current.mounted = false }, [])
  useEffect(() => { setStatus(undefined); setMessage(''); setBusy(false) }, [version, epoch])
  const pending = state.pending?.operation === 'settings.telegram.webhook.register' ? state.pending : undefined
  const actionError = !state.errorOperation || state.errorOperation.startsWith('settings.telegram.') ? state.error : undefined
  const blocked = actionError instanceof CoreAdminApiError && [401, 403].includes(actionError.status)
  async function perform(action: 'inspect' | 'register' | 'recover') {
    if (disabled || busy || state.busy || blocked || action === 'register' && state.pending) return
    const origin = { version, epoch }
    const current = () => view.current.mounted && view.current.version === origin.version && view.current.epoch === origin.epoch && workflowActions.generation() === origin.epoch
    setBusy(true); setMessage(''); setStatus(undefined)
    try {
      if (action === 'inspect') {
        const result = await admin.read('settings.telegram.webhook.inspect', { expected_version: version })
        if (current()) { setStatus(result.data); workflowActions.confirmReadAuthorization('settings.telegram.') }
      } else {
        if (action === 'recover' && pending) await workflowActions.run(pending.operation, pending.input)
        else await workflowActions.run('settings.telegram.webhook.register', { expected_version: version })
        if (current()) setMessage('Telegram 已确认保存配置对应的 Webhook 注册，请检查状态核对投递情况。')
      }
    } catch (error) {
      if (current()) { setStatus(undefined); setMessage(managementError(error, false)) }
    } finally { if (current()) setBusy(false) }
  }
  return <section className="list-card settings-section settings-telegram-diagnostics" aria-label="Telegram Webhook">
    <header className="settings-section-heading"><div><h2>{t("Webhook 注册与检查")}</h2><p>{t("仅使用已保存的机器人凭据。自定义地址留空时使用站点地址；回调地址必须为 HTTPS。")}</p></div></header>
    <p>{t("保存设置只更新本地配置。注册按钮会联系 Telegram 并设置原生回调；状态检查会读取 Telegram 当前记录。")}</p>
    {message ? <p role="status">{t(message)}</p> : null}
    {pending ? <div className="settings-diagnostic-pending"><p>{t("注册结果尚未确认，可先检查 Telegram 状态，或使用原操作确认结果。")}</p><button className="button" disabled={disabled || busy || state.busy || blocked} onClick={() => void perform('recover')}>{t("使用原 Webhook 操作确认结果")}</button></div> : null}
    {status && !disabled && !blocked ? <dl className="settings-value-grid"><div><dt>{t("Telegram 当前注册")}</dt><dd>{status.configured ? t("已设置回调") : t("尚未设置回调")}</dd></div><div><dt>{t("与保存配置一致")}</dt><dd>{status.matches_saved ? t("一致") : t("不一致，请核对配置后重新注册")}</dd></div><div><dt>{t("待处理消息")}</dt><dd>{status.pending_updates}</dd></div><div><dt>{t("最近投递错误")}</dt><dd>{status.delivery_error ? t("Telegram 报告投递异常，请检查回调服务") : t("未报告投递异常")}</dd></div></dl> : null}
    <footer className="settings-form-actions"><span>{t("检查结果来自 Telegram，不包含机器人 token 或回调凭据。")}</span><button className="button" disabled={disabled || busy || state.busy || blocked} onClick={() => void perform('inspect')}>{busy ? t("处理中…") : t("检查 Webhook 状态")}</button><button className="button primary" disabled={disabled || busy || state.busy || !!state.pending || blocked} onClick={() => {
      const origin = { version, epoch }
      requestConfirm({ title: t("注册 Telegram Webhook？"), message: t("将使用当前已保存的凭据和地址，替换 Telegram 上现有的机器人回调配置。"), confirmLabel: t("确认注册"), cancelLabel: t("取消"), action: () => {
        if (view.current.mounted && view.current.version === origin.version && view.current.epoch === origin.epoch && workflowActions.generation() === origin.epoch) return perform('register')
      } })
    }}>{t("注册已保存的 Webhook")}</button></footer>
  </section>
}
