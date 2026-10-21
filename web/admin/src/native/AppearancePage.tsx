import { t, useLocale } from '../lib/i18n'
import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreInput } from '@txboard/core-sdk'
import { admin, useAdminSession, workflowActions } from './session'
import { ManagementFeedback } from './Feedback'
import { PageHeader } from '../components/ui/PageHeader'
import { Field, Input, Select } from '../components/ui/Field'
import { requestConfirm } from '../components/ui/ConfirmDialog'
import { safeLoginBackground, siteAppearanceDefaults, parseSiteAppearance, type SiteAppearance } from '../lib/useSiteAppearance'
import './appearance-page.css'
import { useDirtyDraft } from '../lib/useDirtyDraft'

const keys = Object.keys(siteAppearanceDefaults) as (keyof SiteAppearance)[]
const denied = (error: unknown) => error instanceof CoreAdminApiError && [401, 403].includes(error.status)
const choices = {
  admin_sidebar_style: [['normal', '默认表面'], ['contrast', '深色对比表面']],
  admin_header_style: [['normal', '默认标题区'], ['accent', '强调色标题区']],
  admin_color_scheme: [['default', '默认'], ['black', '石墨'], ['blackblue', '暗蓝'], ['green', '奶绿']],
} as const
export function AppearancePage() {
  useLocale()
  const session = useAdminSession(), client = useQueryClient(), epoch = workflowActions.generation()
  const workflow = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [draft, setDraft] = useState<SiteAppearance>({ ...siteAppearanceDefaults }), [baseline, setBaseline] = useState<SiteAppearance>({ ...siteAppearanceDefaults })
  const [version, setVersion] = useState(''), [message, setMessage] = useState(''), [failure, setFailure] = useState<unknown>()
  const view = useRef({ epoch, alive: true }); view.current.epoch = epoch
  useEffect(() => { view.current.alive = true; return () => { view.current.alive = false } }, [])
  const current = (origin: number) => view.current.alive && view.current.epoch === origin && workflowActions.generation() === origin
  const query = useQuery({ queryKey: ['native-admin', 'appearance', epoch], enabled: session,
    queryFn: ({ signal }) => admin.read('settings.configuration.get', {}, signal) })
  const stateError = workflow.errorOperation === 'settings.configuration.update' ? workflow.error : undefined
  const blocked = !session || denied(query.error) || denied(failure) || denied(stateError)
  const dirty = keys.some(key => draft[key] !== baseline[key])
  useDirtyDraft(!blocked && !!version && dirty)
  const locked = blocked || !version || query.isFetching || workflow.busy || !!workflow.pending
  const pending = workflow.pending?.operation === 'settings.configuration.update' && Object.keys(workflow.pending.input.values).length > 0
    && Object.keys(workflow.pending.input.values).every(key => keys.includes(key as keyof SiteAppearance)) ? workflow.pending : undefined
  useEffect(() => { setDraft({ ...siteAppearanceDefaults }); setBaseline({ ...siteAppearanceDefaults }); setVersion(''); setMessage(''); setFailure(undefined) }, [epoch, session])
  useEffect(() => {
    if (blocked) { setDraft({ ...siteAppearanceDefaults }); setBaseline({ ...siteAppearanceDefaults }); setVersion(''); return }
    if (query.data && !version) {
      const parsed = parseSiteAppearance(query.data.data.values)
      if (parsed) { setDraft(parsed); setBaseline(parsed); setVersion(query.data.data.version) }
    }
  }, [blocked, query.data, version])
  useEffect(() => {
    if (!dirty) return
    const prevent = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', prevent)
    return () => window.removeEventListener('beforeunload', prevent)
  }, [dirty])
  async function reload(discard = false) {
    const origin = epoch
    setFailure(undefined)
    const result = await query.refetch()
    if (!current(origin) || result.isError || !result.data) return
    workflowActions.clearError(); workflowActions.confirmReadAuthorization('settings.configuration.')
    const parsed = parseSiteAppearance(result.data.data.values)
    if (parsed && (discard || !dirty)) { setDraft(parsed); setBaseline(parsed); setVersion(result.data.data.version) }
  }
  async function save(recovery = false) {
    if (!session || workflow.busy || query.isFetching || !query.data || blocked || (!recovery && locked)) return
    const origin = epoch
    if (!recovery && draft.admin_login_background_url && !safeLoginBackground(draft.admin_login_background_url)) {
      setMessage('背景地址须为无凭据的 HTTPS 图片地址或同源绝对路径。'); return
    }
    try {
      setFailure(undefined); setMessage('')
      if (recovery && pending) await workflowActions.run(pending.operation, pending.input)
      else {
        const values = Object.fromEntries(keys.filter(key => draft[key] !== baseline[key]).map(key => [key, draft[key]]))
        if (!Object.keys(values).length) return
        await workflowActions.run('settings.configuration.update', { expected_version: version, values } as CoreInput<'settings.configuration.update'>)
      }
      if (!current(origin)) return
      setMessage('后台外观已保存')
      const [fresh] = await Promise.all([query.refetch(), client.invalidateQueries({ queryKey: ['admin', 'site-appearance'] })])
      if (current(origin) && fresh.data && !fresh.isError) {
        const parsed = parseSiteAppearance(fresh.data.data.values)
        if (parsed) { setDraft(parsed); setBaseline(parsed); setVersion(fresh.data.data.version) }
      }
    } catch (error) { if (current(origin)) setFailure(error) }
  }
  function reset() {
    const origin = epoch
    requestConfirm({ title: t('放弃外观草稿？'), confirmLabel: t('放弃修改'), danger: true, action: () => { if (current(origin)) { setDraft(baseline); setFailure(undefined); setMessage('') } } })
  }
  return <div className="native-management-page appearance-page">
    <PageHeader title={t("后台外观")} description={t("设置站点统一的后台表面、强调色和登录背景")} action={<button className="button" disabled={workflow.busy || query.isFetching} onClick={() => dirty ? requestConfirm({ title: t('重新读取并放弃外观草稿？'), action: () => void reload(true) }) : void reload(true)}>{t("重新读取")}</button>} />
    <p className="appearance-owner-note">{t("这些设置应用于管理控制台。本机选择的明亮、深色或跟随系统继续决定明暗；用户站点主题在主题管理中单独维护。")}</p>
    <ManagementFeedback error={query.error || failure || stateError} loading={query.isFetching} onRetry={() => void reload()} />
    {message ? <p role="status">{t(message)}</p> : null}
    {blocked ? <p role="alert">{t("无法确认外观设置权限，已清空表单和草稿。")}</p> : null}
    {pending ? <section className="list-card"><h2>{t("外观保存结果待确认")}</h2><p>{t("重新读取当前设置权限后，使用原请求和原幂等键确认结果。")}</p><button className="button" disabled={blocked || query.isFetching || workflow.busy} onClick={() => void save(true)}>{t("恢复原请求")}</button></section> : null}
    {!blocked && version && !query.isError ? <form className="list-card appearance-form" onSubmit={event => { event.preventDefault(); void save() }}>
      <Field label={t("侧栏表面风格")} hint={t("修改导航表面颜色，侧栏展开/收起仍由本机偏好决定。")}><Select value={draft.admin_sidebar_style} disabled={locked} onChange={event => setDraft(value => ({ ...value, admin_sidebar_style: event.target.value as SiteAppearance['admin_sidebar_style'] }))}>{choices.admin_sidebar_style.map(([value, label]) => <option key={value} value={value}>{t(label)}</option>)}</Select></Field>
      <Field label={t("页面标题区风格")} hint={t("强调色模式为每页标题增加色条和浅色底，不切换导航布局。")}><Select value={draft.admin_header_style} disabled={locked} onChange={event => setDraft(value => ({ ...value, admin_header_style: event.target.value as SiteAppearance['admin_header_style'] }))}>{choices.admin_header_style.map(([value, label]) => <option key={value} value={value}>{t(label)}</option>)}</Select></Field>
      <Field label={t("强调色")} hint={t("应用于按钮、焦点和强调元素；深色模式使用适配的对比颜色。")}><Select value={draft.admin_color_scheme} disabled={locked} onChange={event => setDraft(value => ({ ...value, admin_color_scheme: event.target.value as SiteAppearance['admin_color_scheme'] }))}>{choices.admin_color_scheme.map(([value, label]) => <option key={value} value={value}>{t(label)}</option>)}</Select></Field>
      <Field label={t("登录背景地址")} hint={t("仅在后台登录页展示。支持 HTTPS 或同源绝对路径；清空恢复默认背景，图片加载失败自动回退。")}><Input value={draft.admin_login_background_url} disabled={locked} maxLength={2048} autoComplete="off" placeholder="https://example.com/login.jpg" onChange={event => setDraft(value => ({ ...value, admin_login_background_url: event.target.value }))} /></Field>
      <footer className="appearance-actions"><span>{dirty ? t('修改尚未保存') : t('当前配置')}</span><button type="button" className="button" disabled={!dirty || workflow.busy || !!workflow.pending} onClick={reset}>{t("取消修改")}</button><button type="submit" className="button primary" disabled={locked || !dirty}>{t("保存外观")}</button></footer>
    </form> : null}
  </div>
}
