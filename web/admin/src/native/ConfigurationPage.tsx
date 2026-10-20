import { t, useLocale } from '../lib/i18n'
import './settings-reference.css'
import { Fragment, useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreInput } from '@txboard/core-sdk'
import metadata from '../../../../contracts/core-api/admin-settings.v1.json'
import { admin, workflowActions } from './session'
import { ManagementFeedback, managementError } from './Feedback'
import { PageHeader } from '../components/ui/PageHeader'
import { Popover } from '../components/ui/Popover'
import { Field, Input, Select, Switch, Textarea } from '../components/ui/Field'
import { configurationDescriptions, configurationHints, configurationSection, configurationOption, configurationFieldVisible, configurationChoices, configurationDisplayOption } from './configurationPresentation'
import { requestConfirm } from '../components/ui/ConfirmDialog'
import { ConfigurationDiagnostics, SmtpDiagnostics } from './ConfigurationDiagnostics'
import { useDirtyDraft } from '../lib/useDirtyDraft'

export type ConfigurationGroup = 'site' | 'features' | 'safe' | 'subscribe' | 'server' | 'email' | 'telegram' | 'app'
const titles: Record<ConfigurationGroup, string> = { site: '站点设置', features: '功能设置', safe: '安全设置', subscribe: '订阅设置', server: '节点设置', email: '邮件设置', telegram: 'Telegram 设置', app: '客户端设置' }
type Value = string | number | boolean | string[]
type Field = { group: string; label: string; kind: string; default: Value; write_only: boolean; options?: string[]; schema: { minLength?: number; maxLength?: number; minimum?: number; maximum?: number } }
const fields = Object.entries(metadata.fields) as [string, Field][]
const denied = (error: unknown) => error instanceof CoreAdminApiError && [401, 403].includes(error.status)

export function ConfigurationPage({ group = 'site' }: { group?: ConfigurationGroup }) {
  useLocale()
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const epoch = workflowActions.generation()
  const [draft, setDraft] = useState<Record<string, Value>>({}), [baseline, setBaseline] = useState<Record<string, Value>>({})
  const [version, setVersion] = useState(''), [editing, setEditing] = useState(false), [message, setMessage] = useState('')
  const [owner, setOwner] = useState({ group, epoch })
  const current = owner.group === group && owner.epoch === epoch
  const view = useRef({ group, epoch }); view.current = { group, epoch }
  const query = useQuery({ queryKey: ['native-admin', 'configuration', epoch], queryFn: ({ signal }) => admin.read('settings.configuration.get', {}, signal) })
  const actionError = !state.errorOperation || state.errorOperation === 'settings.configuration.update' ? state.error : undefined
  const blocked = denied(query.error) || denied(actionError)
  const ready = current && !blocked && !query.isError && !!query.data
  const locked = !ready || query.isFetching || state.busy || !!state.pending
  const editor = useRef<HTMLFormElement>(null)
  useEffect(() => { if (editing) { editor.current?.scrollIntoView?.({ behavior: 'smooth', block: 'start' }); editor.current?.querySelector<HTMLElement>('input, select, textarea')?.focus({ preventScroll: true }) } }, [editing])
  const visibleValues = { ...Object.fromEntries(fields.map(([name, field]) => [name, field.default])), ...(query.data?.data.values ?? {}), ...(editing ? draft : {}) }
  const selectedFields = fields.filter(([name, field]) => field.group === group && configurationFieldVisible(name, visibleValues))
  const changedCount = selectedFields.filter(([name, field]) => JSON.stringify(draft[name]) !== JSON.stringify(baseline[name]) && (!field.write_only || draft[name] !== '')).length
  const dirty = editing && fields.some(([name, field]) => field.group === group && JSON.stringify(draft[name]) !== JSON.stringify(baseline[name]) && (!field.write_only || draft[name] !== ''))
  useDirtyDraft(ready && dirty)
  const pending = state.pending?.operation === 'settings.configuration.update' ? state.pending : undefined
  useEffect(() => {
    if (!dirty) return
    const prevent = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', prevent)
    return () => window.removeEventListener('beforeunload', prevent)
  }, [dirty])
  function cancel() {
    if (state.busy) return
    const origin = { group, epoch }
    const leave = () => { if (view.current.group === origin.group && view.current.epoch === origin.epoch && workflowActions.generation() === origin.epoch) { setDraft({}); setBaseline({}); setEditing(false) } }
    if (!dirty) { leave(); return }
    requestConfirm({ title: t("放弃未保存的设置？"), message: t("本分类中的修改尚未保存，继续将丢弃本次修改。"), confirmLabel: t("放弃修改"), cancelLabel: t("继续编辑"), danger: true, action: leave })
  }
  useEffect(() => { setOwner({ group, epoch }); setDraft({}); setBaseline({}); setVersion(''); setEditing(false); setMessage('') }, [group, epoch])
  useEffect(() => { if (blocked) { setDraft({}); setBaseline({}); setVersion(''); setEditing(false) } }, [blocked])
  function begin() {
    if (locked || !query.data) return
    const values: Record<string, Value> = {}
    for (const [name, field] of fields.filter(([, field]) => field.group === group)) {
      values[name] = field.write_only ? '' : (query.data.data.values as Record<string, Value>)[name] ?? field.default
    }
    setDraft(values); setBaseline(structuredClone(values)); setVersion(query.data.data.version); setEditing(true); setMessage('')
  }
  async function refresh() {
    const result = await query.refetch()
    if (workflowActions.generation() === epoch && !result.isError) { workflowActions.clearError(); workflowActions.confirmReadAuthorization('settings.configuration.') }
  }
  async function perform(recover = false) {
    if (state.busy || blocked || !ready || query.isFetching || (!recover && (!editing || !!state.pending))) return
    const origin = { group, epoch }
    const isCurrent = () => view.current.group === origin.group && view.current.epoch === origin.epoch && workflowActions.generation() === origin.epoch
    setMessage('')
    try {
      if (recover && pending) await workflowActions.run(pending.operation, pending.input)
      else {
        const values = Object.fromEntries(selectedFields.filter(([name, field]) => JSON.stringify(draft[name]) !== JSON.stringify(baseline[name]) && (!field.write_only || draft[name] !== '')).map(([name]) => [name, draft[name]]))
        if (!Object.keys(values).length) { setMessage('没有需要保存的修改'); return }
        await workflowActions.run('settings.configuration.update', { expected_version: version, values } as CoreInput<'settings.configuration.update'>)
      }
      if (!isCurrent()) return
      setDraft({}); setBaseline({}); setEditing(false); setMessage('设置已保存'); await refresh()
    } catch (error) {
      if (!isCurrent()) return
      setMessage(managementError(error, false))
      if (error instanceof CoreAdminApiError && error.status === 409 && !error.needsStateConfirmation) {
        setDraft({}); setBaseline({}); setEditing(false); setVersion(''); await refresh()
        if (isCurrent()) setMessage('设置已被其他管理员修改，请重新编辑并核对修改')
      }
    }
  }
  return <div className="native-management-page settings-reference settings-configuration-page">
    <PageHeader title={t(titles[group])} description={t(configurationDescriptions[group])} action={<button className="button" disabled={state.busy || query.isFetching} onClick={() => void refresh()}>{t("刷新设置")}</button>} />
    <ManagementFeedback error={query.isError ? query.error : undefined} loading={query.isFetching} onRetry={() => void refresh()} />
    {message || actionError ? <p role="alert">{message ? t(message) : managementError(actionError)}</p> : null}
    {blocked ? <p role="alert">{t("当前无法确认设置访问权限，已清空表单并隐藏数据")}</p> : null}
    {pending ? <section className="list-card settings-pending-card"><h2>{t("操作等待确认")}</h2><p>{t("设置保存的结果尚未确认，请使用原操作确认结果")}</p><button className="button" disabled={state.busy || !ready || query.isFetching} onClick={() => void perform(true)}>{t("使用原操作确认结果")}</button></section> : null}
    {ready ? <section className="list-card settings-section"><header className="settings-section-heading"><div><h2>{t(titles[group])}</h2><p>{selectedFields.length}{t("项设置 · 凭据留空保留已配置值")}</p></div><div className="settings-heading-actions"><Popover label={t("设置说明")}><p>{t("修改仅在保存后生效，取消编辑会放弃本次修改")}</p><p className="native-configuration-version">{t("设置版本：")}{query.data!.data.version}</p></Popover><span className="settings-status" data-editing={editing}>{editing ? t("正在编辑") : t("当前配置")}</span></div></header>
      {!editing ? <><dl className="settings-value-grid">{selectedFields.map(([name, field], index) => { const section = configurationSection(name, group), heading = section && section !== configurationSection(selectedFields[index - 1]?.[0] ?? '', group); const value = (query.data!.data.values as Record<string, Value>)[name] ?? field.default; return <Fragment key={name}>{heading ? <div className="settings-value-section"><h3>{t(section)}</h3></div> : null}<div><dt>{t(field.label)}</dt><dd data-kind={field.kind} data-configured={field.write_only ? query.data!.data.credentials.find(item => item.name === name)?.configured : undefined}>{field.write_only ? (query.data!.data.credentials.find(item => item.name === name)?.configured ? t("已配置") : t("未配置")) : field.kind === 'checkbox' ? (value ? t("已开启") : t("已关闭")) : Array.isArray(value) ? value.join('、') || t("未设置") : field.kind === 'select' || configurationChoices(name) ? t(configurationDisplayOption(name, value)) : String(value) || t("未设置")}</dd>{configurationHints[name] ? <small className="settings-value-hint">{t(configurationHints[name])}</small> : null}</div></Fragment> })}</dl><footer className="settings-form-actions"><span>{t("核对当前配置后开始修改")}</span><button className="button primary" disabled={locked} onClick={begin}>{t("开始编辑")}</button></footer></> : <form ref={editor} className="native-content-form settings-field-grid" onSubmit={event => { event.preventDefault(); void perform() }}>
        {selectedFields.map(([name, field], index) => {
          const section = configurationSection(name, group), heading = section && section !== configurationSection(selectedFields[index - 1]?.[0] ?? '', group)
          const sectionHeading = heading ? <div className="settings-subsection-heading"><h3>{t(section)}</h3></div> : null
          const id = 'configuration-' + name, value = draft[name] ?? field.default
          const choices = configurationChoices(name)
          const update = (next: Value) => setDraft(old => ({ ...old, [name]: next }))
          const configured = field.write_only && query.data!.data.credentials.find(item => item.name === name)?.configured
          if (field.kind === 'checkbox') return <Fragment key={name}>{sectionHeading}<Field label={t(field.label)} hint={configurationHints[name] ? t(configurationHints[name]) : undefined} className="settings-toggle-field">
            <Switch id={id} label="" checked={Boolean(value)} disabled={locked} onChange={event => update(event.target.checked)} />
          </Field></Fragment>
          return <Fragment key={name}>{sectionHeading}<Field label={t(field.label)} className={field.kind === 'list' ? 'settings-wide-field' : undefined}
            hint={field.write_only ? (configured ? t("已配置，留空保留") : t("未配置，留空保留")) : configurationHints[name] ? t(configurationHints[name]) : undefined}>
            {choices ? <Select id={id} value={String(value)} disabled={locked} onChange={event => update(Number(event.target.value))}>{choices.map(([value, label]) => <option key={value} value={value}>{t(label)}</option>)}</Select> : field.kind === 'select' ? <Select id={id} value={String(value)} disabled={locked} onChange={event => update(event.target.value)}>
              {field.options?.map(option => <option key={option} value={option}>{t(configurationOption(option))}</option>)}
            </Select>
              : field.kind === 'list' ? <Textarea id={id} placeholder={t("每行填写一项")} rows={4} value={Array.isArray(value) ? value.join('\n') : String(value)} disabled={locked}
                onChange={event => update(event.target.value.split('\n'))}
                onBlur={() => update(Array.isArray(value) ? value.map(item => item.trim()).filter(Boolean) : [])} />
              : <Input id={id} type={field.write_only ? 'password' : field.kind === 'number' ? 'number' : 'text'} autoComplete={field.write_only ? 'new-password' : 'off'}
                value={String(value)} min={field.schema.minimum} max={field.schema.maximum} minLength={field.schema.minLength} maxLength={field.schema.maxLength}
                required={field.kind === 'number' || !!field.schema.minLength} disabled={locked}
                onChange={event => update(field.kind === 'number' ? Number(event.target.value) : event.target.value)} />}
          </Field></Fragment>
        })}
        <footer className="settings-form-actions"><span aria-live="polite">{changedCount ? t("{v0} 项待保存", { v0: changedCount }) : t("尚未修改")}</span><button className="button primary" disabled={locked}>{t("保存当前分类")}</button><button className="button" type="button" disabled={state.busy} onClick={cancel}>{t("取消编辑")}</button></footer>
      </form>}
    </section> : null}
    {ready && group === 'telegram' ? <ConfigurationDiagnostics version={query.data!.data.version} disabled={editing || query.isFetching} /> : null}
    {ready && group === 'email' ? <SmtpDiagnostics version={query.data!.data.version} disabled={editing || query.isFetching} /> : null}
  </div>
}
