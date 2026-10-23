import { t, useLocale } from '../lib/i18n'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { useListPagination } from './useListPagination'
import './settings-reference.css'
import { useEffect, useId, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreInput, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { ManagementFeedback, managementError } from './Feedback'
import { ReadList } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import type { Column } from '../components/ui/DataTable'
import { Modal } from '../components/ui/Modal'
import { FormSection } from '../components/ui/FormSection'
import { CopyButton } from '../components/ui/CopyButton'
import { useAdminConfirmation } from './useAdminConfirmation'

type Method = CoreOutput<'billing.admin-payment-methods.list'>[number]
type Save = CoreInput<'billing.admin-payment-methods.save'>
type Draft = { row?: Method; name: string; provider: string; icon: string; enabled: boolean; sort: string; configuration: Record<string, string> }
const prefix = 'billing.admin-payment-methods.'
const forbidden = (error: unknown) => error instanceof CoreAdminApiError && [401, 403].includes(error.status)

export function PaymentMethodsPage() {
  const confirm = useAdminConfirmation(() => !denied)
  useLocale()
  const formId = useId()
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const generation = workflowActions.generation(), observed = useRef(generation), view = useRef(0)
  const { page, setPage, perPage, setPerPage } = useListPagination()
  const [draft, setDraft] = useState<Draft>(), [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const baseline = useRef('')
  const dirty = !!draft && JSON.stringify(draft) !== baseline.current
  useEffect(() => {
    if (!dirty) return
    const prevent = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', prevent)
    return () => window.removeEventListener('beforeunload', prevent)
  }, [dirty])
  const [failedFormRead, setFailedFormRead] = useState<CoreInput<'billing.admin-payment-methods.form'>>()
  const methods = useQuery({ queryKey: ['native-admin', 'payment-methods', generation, page, perPage],
    queryFn: ({ signal }) => admin.read('billing.admin-payment-methods.list', { page, per_page: perPage }, signal) })
  const providers = useQuery({ queryKey: ['native-admin', 'payment-providers', generation],
    queryFn: ({ signal }) => admin.read('billing.admin-payment-providers.list', {}, signal) })
  const form = useQuery({ queryKey: ['native-admin', 'payment-form', generation, draft?.provider, draft?.row?.id],
    enabled: !!draft?.provider,
    queryFn: ({ signal }) => admin.read('billing.admin-payment-methods.form', {
      provider: draft!.provider, ...(draft?.row ? { payment_id: draft.row.id } : {}),
    }, signal) })
  const ownError = state.errorOperation?.startsWith(prefix) ? state.error : undefined
  const denied = !!failedFormRead || [methods.error, providers.error, draft && form.error, ownError].some(forbidden)
  const pending = state.pending?.operation === 'billing.admin-payment-methods.save' ? state.pending : undefined
  const readable = !denied && !methods.isError && !providers.isError
  useDirtyDraft(dirty && readable && !state.pending && observed.current === generation)
  const locked = !readable || state.busy || !!state.pending || methods.isFetching || providers.isFetching || !methods.data || !providers.data
  useEffect(() => () => { view.current++ }, [])
  useEffect(() => {
    if (draft && forbidden(form.error)) setFailedFormRead({ provider: draft.provider, ...(draft.row ? { payment_id: draft.row.id } : {}) })
  }, [form.error, draft])
  useEffect(() => {
    if (observed.current !== generation) { observed.current = generation; setFailedFormRead(undefined); view.current++; setDraft(undefined); setError(''); setNotice('') }
    else if (denied) { view.current++; setDraft(undefined); setError(''); setNotice('') }
  }, [generation, denied])
  async function refresh() {
    const current = workflowActions.generation()
    try {
      await Promise.all([methods.refetch({ throwOnError: true }), providers.refetch({ throwOnError: true }),
        failedFormRead ? admin.read('billing.admin-payment-methods.form', failedFormRead) : Promise.resolve()])
      if (current !== workflowActions.generation()) return
      setFailedFormRead(undefined)
      workflowActions.confirmReadAuthorization(prefix); workflowActions.clearError(); setError('')
    } catch { /* The failed authorization check remains visible. */ }
  }
  async function edit(row?: Method) {
    if (locked) return
    if (dirty && !(await confirm(t('支付配置尚未保存，放弃当前修改？')))) return
    view.current++
    const next = { row, name: row?.name ?? '', provider: row?.provider ?? providers.data?.data[0]?.provider ?? '',
      icon: row?.icon ?? '', enabled: row?.enabled ?? false, sort: String(row?.sort ?? 0), configuration: {} }
    baseline.current = JSON.stringify(next)
    setDraft(next)
    setError(''); setNotice('')
  }
  async function perform(input: Save) {
    const current = workflowActions.generation(), displayed = view.current
    setError('')
    try {
      await workflowActions.run('billing.admin-payment-methods.save', input)
      if (current !== workflowActions.generation() || displayed !== view.current) return
      setDraft(undefined); await refresh()
      if (current === workflowActions.generation() && displayed === view.current) setNotice('支付配置已保存')
    } catch (failure) {
      if (current !== workflowActions.generation() || displayed !== view.current) return
      if (failure instanceof CoreAdminApiError && failure.status === 409 && !workflowActions.snapshot().pending) {
        setDraft(undefined); await refresh()
        if (current === workflowActions.generation() && displayed === view.current) setError('支付方式已变化，请按刷新后的配置重新编辑')
        return
      }
      setError(managementError(failure, false))
    }
  }
  async function save() {
    if (!draft || locked || !form.data || form.isError || form.isFetching) return
    if (!draft.name.trim() || !/^(0|[1-9][0-9]{0,9})$/.test(draft.sort) || Number(draft.sort) > 2147483647) {
      setError('请填写支付方式名称和有效的排序值'); return
    }
    await perform({ ...(draft.row ? { payment_id: draft.row.id, expected_version: draft.row.version } : {}),
      name: draft.name.trim(), provider: draft.provider, icon: draft.icon.trim() || null, enabled: draft.enabled,
      sort: Number(draft.sort), configuration: Object.entries(draft.configuration)
        .filter(([, value]) => value !== '').map(([name, value]) => ({ name, value })) })
  }
  async function recover() {
    if (!pending || denied || state.busy || methods.isFetching || providers.isFetching || !readable || !methods.data || !providers.data) return
    await perform(pending.input)
  }
  async function closeEditor() {
    if (state.busy) return
    if (dirty && !(await confirm(t('支付配置尚未保存，放弃当前修改？')))) return
    view.current++; setDraft(undefined)
  }
  const columns: Column<Method>[] = [
    { key: 'name', header: t('支付方式'), render: row => <div className="native-row-identity"><strong>{row.name}</strong><small>#{row.id}<CopyButton value={row.id} label={t("复制支付方式 ID")} /> · {row.provider}</small></div> },
    { key: 'state', header: t('状态'), render: row => <div className="settings-payment-status"><span className={row.enabled ? 'settings-status positive' : 'settings-status'}>{row.enabled ? t('已启用') : t('已停用')}</span><small>{row.available ? t('当前可结账') : t('当前不可结账')}</small></div> },
    { key: 'fees', header: t('手续费'), render: row => <div className="settings-fee-value"><strong>{row.fee_percent}%</strong><small>{t('固定 {amount} 最小货币单位', { amount: row.fee_fixed_minor })}</small></div> },
    { key: 'sort', header: t('排序'), render: row => row.sort },
    { key: 'action', header: t('操作'), pin: 'right', hideable: false, render: row => <button className="button" disabled={locked} onClick={() => void edit(row)}>{t("配置支付方式")}</button> },
  ]
  return <div className="native-management-page settings-reference settings-payment-page">
    <PageHeader title={t("支付配置")} description={t("管理收款渠道、商户资料与结账可用状态")}
      action={<><button className="button" disabled={state.busy} onClick={() => void refresh()}>{t("刷新支付配置")}</button><button className="button primary" disabled={locked || !providers.data?.data.length} onClick={() => void edit()}>{t("新增支付方式")}</button></>} />
    <ManagementFeedback error={providers.error} loading={providers.isFetching} onRetry={() => void refresh()} />
    {pending ? <section className="list-card settings-pending-card" role="status"><h2>{t("支付配置等待确认")}</h2><p>{t("继续保留原输入与操作标识，请确认原操作结果后再编辑")}</p><button className="button" disabled={denied || state.busy || !readable || methods.isFetching || providers.isFetching} onClick={() => void recover()}>{t("使用原操作确认结果")}</button></section> : null}
    {notice && !denied ? <p role="status" className="settings-success-feedback">{t(notice)}</p> : null}
    {!draft && (error || ownError) ? <p role="alert">{error ? t(error) : managementError(ownError)}</p> : null}
    {denied ? <p role="alert">{t("无法确认支付配置权限，已隐藏数据")}</p> : null}
    <ReadList title="支付方式" rows={readable ? methods.data?.data ?? [] : []} columns={columns} perPage={perPage} onPageSize={setPerPage} page={page}
      meta={readable ? methods.data?.meta : undefined} ready={readable && !!methods.data} mobilePrimaryKey="name"
      loading={methods.isFetching} error={methods.error} onRetry={() => void refresh()} onPage={setPage}><header className="settings-section-heading"><div><h2>{t("支付方式")}</h2><p>{t("区分渠道启用状态与当前结账可用性")}</p></div>{readable && methods.data ? <span className="settings-status">{t('共 {count} 项', { count: methods.data.meta.total })}</span> : null}</header></ReadList>
    {readable && providers.data ? <section className="list-card settings-section"><details className="settings-provider-help"><summary>{t("可用支付提供方")}<span>{t('{count} 个', { count: providers.data.data.length })}</span></summary>
      {providers.data.data.length ? <ul>{providers.data.data.map(provider => <li key={provider.provider}>{provider.provider} · {provider.currencies.join(' / ')} · {provider.external_refund ? t('支持渠道退款') : t('渠道退款未开放')}</li>)}</ul> : <p>{t("暂无可用支付提供方，请检查支付模块状态")}</p>}
      <p>{t("当前支持零手续费渠道，保存后仍需核对商户资料和支持币种")}</p></details>
    </section> : null}
    {draft && readable ? <Modal open className="settings-reference settings-payment-page" size="medium" title={draft.row ? t("编辑支付方式") : t("新增支付方式")} subtitle={t("填写展示信息和商户资料")} onClose={() => void closeEditor()} footer={<div className="settings-form-actions"><span>{t("保存后影响后续结账")}</span><button className="button" type="button" disabled={state.busy} onClick={() => void closeEditor()}>{t("关闭编辑")}</button>
        <button type="submit" form={formId} className="button primary" disabled={locked || !form.data || form.isError || form.isFetching}>{t("保存支付配置")}</button></div>}><section aria-label={t("支付方式编辑器")} className="refined-payment-editor">{error || ownError ? <p role="alert">{error ? t(error) : managementError(ownError)}</p> : null}
      <form id={formId} className="native-content-form" onSubmit={event => { event.preventDefault(); void save() }}>
        <FormSection title={t("展示信息")} description={t("名称、图标和排序决定用户选择支付渠道时看到的内容")}><div className="settings-field-grid">
        <label>{t("名称")}<input autoFocus placeholder={t("例如：在线支付")} required maxLength={255} value={draft.name} disabled={locked} onChange={event => setDraft({ ...draft, name: event.target.value })} /></label>
        <label>{t("支付提供方")}<select value={draft.provider} disabled={locked || !!draft.row} onChange={event => setDraft({ ...draft, provider: event.target.value, configuration: {} })}>
          {draft.row && !providers.data?.data.some(row => row.provider === draft.provider) ? <option value={draft.provider}>{draft.provider}</option> : null}
          {providers.data?.data.map(row => <option key={row.provider} value={row.provider}>{row.provider}</option>)}</select></label>
        <label>{t("图标地址")}<input placeholder="https://" maxLength={2048} value={draft.icon} disabled={locked} onChange={event => setDraft({ ...draft, icon: event.target.value })} /></label>
        <label>{t("排序")}<input inputMode="numeric" value={draft.sort} disabled={locked} onChange={event => setDraft({ ...draft, sort: event.target.value })} /></label>
        <label className="settings-toggle-field"><span>{t("启用此支付方式")}</span><input type="checkbox" checked={draft.enabled} disabled={locked} onChange={event => setDraft({ ...draft, enabled: event.target.checked })} /></label>
        {providers.data?.data.find(row => row.provider === draft.provider) ? <div className="settings-provider-summary"><span>{t('支持币种：{currencies}', { currencies: providers.data.data.find(row => row.provider === draft.provider)!.currencies.join(' / ') || t('未声明') })}</span><span>{providers.data.data.find(row => row.provider === draft.provider)!.external_refund ? t('支持渠道退款') : t('渠道退款未开放')}</span></div> : null}
        </div></FormSection><ManagementFeedback error={form.error} loading={form.isFetching} onRetry={() => void form.refetch()} />
        {form.data && !form.isError ? <FormSection title={t("商户配置")} description={t("凭据不会回显，留空保留原值")}><div className="settings-field-grid">
          {form.data.data.fields.map(field => <label key={field.name}><span className="settings-label-line">{field.label}<small className={field.configured ? 'settings-configured' : ''}>{field.configured ? t('已配置') : t('待填写')}</small></span><input autoComplete="off" type={field.kind === 'password' ? 'password' : 'text'} maxLength={8192}
            value={draft.configuration[field.name] ?? ''} disabled={locked} onChange={event => setDraft({ ...draft, configuration: { ...draft.configuration, [field.name]: event.target.value } })} /></label>)}</div></FormSection> : null}

      </form>
    </section></Modal> : null}
  </div>
}
