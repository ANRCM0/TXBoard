import './settings-reference.css'
import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreInput } from '@txboard/core-sdk'
import metadata from '../../../../contracts/core-api/admin-settings.v1.json'
import { admin, workflowActions } from './session'
import { ManagementFeedback, managementError } from './Feedback'
import { PageHeader } from '../components/ui/PageHeader'

export type ConfigurationGroup = 'site' | 'features' | 'safe' | 'subscribe' | 'server' | 'email' | 'telegram' | 'app'
const titles: Record<ConfigurationGroup, string> = { site: '站点设置', features: '功能设置', safe: '安全设置', subscribe: '订阅设置', server: '节点设置', email: '邮件设置', telegram: 'Telegram 设置', app: '客户端设置' }
type Value = string | number | boolean | string[]
type Field = { group: string; label: string; kind: string; default: Value; write_only: boolean; options?: string[]; schema: { minLength?: number; maxLength?: number; minimum?: number; maximum?: number } }
const fields = Object.entries(metadata.fields) as [string, Field][]
const denied = (error: unknown) => error instanceof CoreAdminApiError && [401, 403].includes(error.status)

export function ConfigurationPage({ group = 'site' }: { group?: ConfigurationGroup }) {
  const state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const epoch = workflowActions.generation()
  const [draft, setDraft] = useState<Record<string, Value>>({}), [baseline, setBaseline] = useState<Record<string, Value>>({})
  const [version, setVersion] = useState(''), [editing, setEditing] = useState(false), [message, setMessage] = useState('')
  const [owner, setOwner] = useState({ group, epoch })
  const current = owner.group === group && owner.epoch === epoch
  const view = useRef({ group, epoch }); view.current = { group, epoch }
  const query = useQuery({ queryKey: ['native-admin', 'configuration', epoch], queryFn: ({ signal }) => admin.read('settings.configuration.get', {}, signal) })
  const blocked = denied(query.error) || denied(state.error)
  const ready = current && !blocked && !query.isError && !!query.data
  const locked = !ready || query.isFetching || state.busy || !!state.pending
  const selectedFields = fields.filter(([, field]) => field.group === group)
  const changedCount = selectedFields.filter(([name, field]) => JSON.stringify(draft[name]) !== JSON.stringify(baseline[name]) && (!field.write_only || draft[name] !== '')).length
  const pending = state.pending?.operation === 'settings.configuration.update' ? state.pending : undefined
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
        const values = Object.fromEntries(fields.filter(([name, field]) => field.group === group && JSON.stringify(draft[name]) !== JSON.stringify(baseline[name]) && (!field.write_only || draft[name] !== '')).map(([name]) => [name, draft[name]]))
        if (!Object.keys(values).length) { setMessage('没有需要保存的修改'); return }
        await workflowActions.run('settings.configuration.update', { expected_version: version, values } as CoreInput<'settings.configuration.update'>)
      }
      if (!isCurrent()) return
      setDraft({}); setBaseline({}); setEditing(false); setMessage('设置已保存'); await refresh()
    } catch (error) {
      if (!isCurrent()) return
      setMessage(managementError(error))
      if (error instanceof CoreAdminApiError && error.status === 409 && !error.needsStateConfirmation) {
        setDraft({}); setBaseline({}); setEditing(false); setVersion(''); await refresh()
        if (isCurrent()) setMessage('设置已被其他管理员修改，请重新编辑并核对修改')
      }
    }
  }
  return <div className="native-management-page settings-reference">
    <PageHeader title={titles[group]} description="管理站点运行偏好，修改后保存当前分类" action={<button className="button" disabled={state.busy || query.isFetching} onClick={() => void refresh()}>刷新设置</button>} />
    <ManagementFeedback error={query.isError ? query.error : undefined} loading={query.isFetching} onRetry={() => void refresh()} />
    {message || state.error ? <p role="alert">{message || managementError(state.error)}</p> : null}
    {blocked ? <p role="alert">当前无法确认设置访问权限，已清空表单并隐藏数据</p> : null}
    {pending ? <section className="list-card"><h2>操作等待确认</h2><p>设置保存的结果尚未确认，请使用原操作确认结果</p><button className="button" disabled={state.busy || !ready || query.isFetching} onClick={() => void perform(true)}>使用原操作确认结果</button></section> : null}
    {ready ? <section className="list-card settings-section"><header className="settings-section-heading"><div><h2>{titles[group]}</h2><p>{selectedFields.length} 项设置 · 凭据留空保留已配置值</p></div><span className="settings-status">{editing ? '正在编辑' : '当前配置'}</span></header>
      <details className="settings-help"><summary>设置说明</summary><p>修改仅在保存后生效，取消编辑会放弃本次修改</p><p className="native-configuration-version">设置版本：{query.data!.data.version}</p></details>
      {!editing ? <><dl className="settings-value-grid">{selectedFields.map(([name, field]) => { const value = (query.data!.data.values as Record<string, Value>)[name] ?? field.default; return <div key={name}><dt>{field.label}</dt><dd>{field.write_only ? (query.data!.data.credentials.find(item => item.name === name)?.configured ? '已配置' : '未配置') : field.kind === 'checkbox' ? (value ? '已开启' : '已关闭') : Array.isArray(value) ? value.join('、') || '未设置' : String(value) || '未设置'}</dd></div> })}</dl><footer className="settings-form-actions"><span>核对当前配置后开始修改</span><button className="button primary" disabled={locked} onClick={begin}>开始编辑</button></footer></> : <form className="native-content-form settings-field-grid" onSubmit={event => { event.preventDefault(); void perform() }}>
        {fields.filter(([, field]) => field.group === group).map(([name, field]) => {
          const id = 'configuration-' + name, value = draft[name] ?? field.default
          const update = (next: Value) => setDraft(old => ({ ...old, [name]: next }))
          const configured = field.write_only && query.data!.data.credentials.find(item => item.name === name)?.configured
          return <label className={field.kind === 'checkbox' ? 'settings-toggle-field' : field.kind === 'list' ? 'settings-wide-field' : undefined} key={name} htmlFor={id}>{field.label}{field.write_only ? <small>（{configured ? '已配置' : '未配置'}，留空保留）</small> : null}
            {field.kind === 'checkbox' ? <input id={id} type="checkbox" checked={Boolean(value)} disabled={locked} onChange={event => update(event.target.checked)} />
              : field.kind === 'select' ? <select id={id} value={String(value)} disabled={locked} onChange={event => update(event.target.value)}>{field.options?.map(option => <option key={option} value={option}>{option || '不加密'}</option>)}</select>
              : field.kind === 'list' ? <textarea id={id} rows={4} value={Array.isArray(value) ? value.join('\n') : String(value)} disabled={locked} onChange={event => update(event.target.value.split('\n'))} onBlur={() => update(Array.isArray(value) ? value.map(item => item.trim()).filter(Boolean) : [])} />
              : <input id={id} type={field.write_only ? 'password' : field.kind === 'number' ? 'number' : 'text'} autoComplete={field.write_only ? 'new-password' : 'off'} value={String(value)} min={field.schema.minimum} max={field.schema.maximum} minLength={field.schema.minLength} maxLength={field.schema.maxLength} required={field.kind === 'number' || !!field.schema.minLength} disabled={locked} onChange={event => update(field.kind === 'number' ? Number(event.target.value) : event.target.value)} />}
          </label>
        })}
        <footer className="settings-form-actions"><span>{changedCount ? `${changedCount} 项待保存` : '尚未修改'}</span><button className="button primary" disabled={locked}>保存当前分类</button><button className="button" type="button" disabled={state.busy} onClick={() => { setDraft({}); setBaseline({}); setEditing(false) }}>取消编辑</button></footer>
      </form>}
    </section> : null}
  </div>
}
