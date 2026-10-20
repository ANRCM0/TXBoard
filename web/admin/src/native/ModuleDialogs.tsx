import { t, useLocale } from '../lib/i18n'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery } from '@tanstack/react-query'
import { type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { ManagementFeedback } from './Feedback'
import { moduleDenied, type ModuleCard } from './ModulesPage'
import { Modal } from '../components/ui/Modal'
import { CodeEditor } from '../components/ui/CodeEditor'
import { requestConfirm } from '../components/ui/ConfirmDialog'

type Props = { module: ModuleCard; onClose(): void; onDenied(error: unknown): void }
function objectJson(source: string) {
  const value: unknown = JSON.parse(source)
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('请输入 JSON 对象')
  return value as Record<string, unknown>
}
function useDraftClose(dirty: boolean, busy: boolean, onClose: () => void) {
  const alive = useRef(true)
  useEffect(() => { alive.current = true; return () => { alive.current = false } }, [])
  useEffect(() => {
    if (!dirty) return
    const beforeUnload = (event: BeforeUnloadEvent) => { event.preventDefault(); event.returnValue = '' }
    window.addEventListener('beforeunload', beforeUnload)
    return () => window.removeEventListener('beforeunload', beforeUnload)
  }, [dirty])
  return () => {
    if (busy) return
    if (dirty) requestConfirm({ title: t('放弃未保存的修改？'), message: t('关闭后会清除此处草稿。已发送但结果未知的请求仍可在模块页恢复。'), danger: true, confirmLabel: t('放弃修改'), action: () => { if (alive.current) onClose() } })
    else onClose()
  }
}
export function ModuleConfigurationDialog({ module, onClose, onDenied, onSaved }: Props & { onSaved(): Promise<void> }) {
  useLocale()
  const workflow = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [loaded, setLoaded] = useState<CoreOutput<'platform.module-configuration.get'>>()
  const [draft, setDraft] = useState(''), [references, setReferences] = useState<Record<string, string>>({})
  const [error, setError] = useState<unknown>(), [message, setMessage] = useState(''), [validation, setValidation] = useState('')
  const alive = useRef(true), sessionGeneration = workflowActions.generation()
  const current = () => alive.current && sessionGeneration === workflowActions.generation()
  useEffect(() => { alive.current = true; return () => { alive.current = false } }, [])
  const query = useQuery({ queryKey: ['native-admin', 'module-configuration', module.id],
    queryFn: ({ signal }) => admin.read('platform.module-configuration.get', { module_id: module.id }, signal) })
  useEffect(() => { if (query.data && !loaded) { setLoaded(query.data.data); setDraft(query.data.data.configuration_json) } }, [query.data, loaded])
  useEffect(() => { if (moduleDenied(query.error)) onDenied(query.error) }, [query.error, onDenied])
  const dirty = !!loaded && (draft !== loaded.configuration_json || Object.values(references).some(Boolean))
  useDirtyDraft(dirty && !moduleDenied(query.error) && !workflow.pending)
  const close = useDraftClose(dirty, workflow.busy, onClose), locked = workflow.busy || !!workflow.pending
  async function save() {
    if (!loaded || locked || !current()) return
    try { objectJson(draft) } catch { setValidation('请输入有效的 JSON 对象，修改内容仍保留在编辑器中。'); return }
    try {
      setValidation(''); setError(undefined); setMessage('')
      await workflowActions.run('platform.module-configuration.save', { module_id: module.id, fingerprint: loaded.fingerprint, expected_revision: loaded.revision,
        configuration_json: draft, credential_references: Object.entries(references).filter(([, reference]) => reference.trim()).map(([name, reference]) => ({ name, reference: reference.trim() })) })
      if (!current()) return
      const fresh = await query.refetch()
      if (!current()) return
      if (fresh.data && !fresh.isError) { setLoaded(fresh.data.data); setDraft(fresh.data.data.configuration_json); setReferences({}); setMessage('配置已保存') }
      await onSaved()
    } catch (failure) { if (current()) { setError(failure); if (moduleDenied(failure)) onDenied(failure) } }
  }
  async function reload() {
    if (!current()) return
    const fresh = await query.refetch()
    if (current() && fresh.data && !fresh.isError) { setLoaded(fresh.data.data); setDraft(fresh.data.data.configuration_json); setReferences({}); setError(undefined) }
  }
  let schema: Record<string, unknown> = {}
  try { if (loaded) schema = objectJson(loaded.schema_json) } catch { /* Invalid owner schemas stay visible for diagnosis. */ }
  const properties = schema.properties && typeof schema.properties === 'object' ? schema.properties as Record<string, Record<string, unknown>> : {}
  let values: Record<string, unknown> | undefined
  try { values = objectJson(draft) } catch { /* Preserve invalid JSON until the editor repairs it. */ }
  function patch(name: string, value: unknown) { if (values) setDraft(JSON.stringify({ ...values, [name]: value }, null, 2)) }
  return <Modal open title={t('{name}配置', { name: module.name })} placement="right" wide onClose={close} footer={<div className="modal-actions"><button className="button" disabled={workflow.busy} onClick={close}>{t("关闭")}</button><button className="button" disabled={locked || query.isFetching} onClick={() => dirty ? requestConfirm({ title: t('重新读取并放弃草稿？'), action: () => void reload() }) : void reload()}>{t("重新读取")}</button><button className="button primary" disabled={locked || !loaded || !dirty} onClick={() => void save()}>{t("保存配置")}</button></div>}>
    <ManagementFeedback error={query.error || error} loading={query.isFetching} onRetry={() => void query.refetch()} />
    {validation ? <p role="alert">{t(validation)}</p> : null}
    {message ? <p role="status">{t(message)}</p> : null}
    {loaded && !moduleDenied(error) && !moduleDenied(query.error) ? <>
      <p>{t("配置按读取时的修订保存。冲突时请先核对当前配置；凭据留空会保留已有值。")}</p>
      {module.type === 'theme' && values ? <div className="module-schema-fields">{Object.entries(properties).map(([name, spec]) => <label key={name}><strong>{typeof spec.title === 'string' ? t(spec.title) : name}</strong>{typeof spec.description === 'string' ? <small>{t(spec.description)}</small> : null}
        {Array.isArray(spec.enum) ? <select value={String(values![name] ?? '')} disabled={locked} onChange={event => patch(name, (spec.enum as unknown[])[Number(event.target.selectedOptions[0].dataset.index)])}>{spec.enum.map((option, index) => <option key={index} data-index={index} value={String(option)}>{String(option)}</option>)}</select>
          : spec.type === 'boolean' ? <input type="checkbox" checked={values![name] === true} disabled={locked} onChange={event => patch(name, event.target.checked)} />
            : <input value={String(values![name] ?? '')} type={spec.type === 'integer' || spec.type === 'number' ? 'number' : 'text'} disabled={locked} onChange={event => patch(name, spec.type === 'integer' || spec.type === 'number' ? event.target.value === '' ? '' : Number(event.target.value) : event.target.value)} />}</label>)}</div> : null}
      <CodeEditor label={t("公开配置 JSON")} language="json" value={draft} onChange={setDraft} disabled={locked} height={300} maxLength={32768} />
      {loaded.credentials.length ? <fieldset className="module-credentials"><legend>{t("凭据引用")}</legend><p>{t("只填写已由部署所有者授权给此模块和当前管理员的 secret: 引用；此处不会返回保存的引用或凭据值。")}</p>{loaded.credentials.map(credential => <label key={credential.name}><span>{credential.name} · {credential.configured ? t('已配置') : t('未配置')}</span><input autoComplete="off" placeholder={t("留空保留已有凭据")} value={references[credential.name] ?? ''} disabled={locked} maxLength={71} onChange={event => setReferences(current => ({ ...current, [credential.name]: event.target.value }))} /></label>)}</fieldset> : null}
      <details><summary>{t("配置字段规范")}</summary><pre className="module-readme">{JSON.stringify(schema, null, 2)}</pre></details>
    </> : null}
  </Modal>
}

export function ModuleRuntimeDialog({ module, onClose, onDenied }: Props) {
  useLocale()
  const workflow = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [operation, setOperation] = useState(''), [draft, setDraft] = useState('{}'), [result, setResult] = useState(''), [error, setError] = useState<unknown>(), [reading, setReading] = useState(false), [validation, setValidation] = useState('')
  const alive = useRef(true), generation = useRef(0), sessionGeneration = workflowActions.generation()
  useEffect(() => { alive.current = true; return () => { alive.current = false; generation.current++ } }, [])
  const query = useQuery({ queryKey: ['native-admin', 'module-presentation', module.id, module.fingerprint], enabled: !!module.fingerprint,
    queryFn: ({ signal }) => admin.read('platform.module-presentation.get', { module_id: module.id, fingerprint: module.fingerprint! }, signal) })
  useEffect(() => { if (moduleDenied(query.error)) onDenied(query.error) }, [query.error, onDenied])
  const spec = query.data?.data.operations.find(row => row.id === operation)
  const close = useDraftClose(draft !== '{}', reading || workflow.busy, onClose)
  async function execute() {
    if (!spec || !query.data || reading || workflow.busy || workflow.pending || !alive.current || sessionGeneration !== workflowActions.generation()) return
    try { objectJson(draft) } catch { setValidation('请输入有效的 JSON 对象，操作尚未发送。'); return }
    const current = ++generation.current
    try {
      setValidation(''); setError(undefined); setResult(''); setReading(true)
      const input = { module_id: module.id, fingerprint: query.data.data.fingerprint, operation_id: spec.id, input_json: draft }
      const response = spec.mode === 'read' ? await admin.read('platform.module-presentation.query', input)
        : await workflowActions.runResult('platform.module-presentation.execute', input)
      if (alive.current && sessionGeneration === workflowActions.generation() && generation.current === current) setResult((response as { data: CoreOutput<'platform.module-presentation.query'> }).data.result_json)
    } catch (failure) { if (alive.current && sessionGeneration === workflowActions.generation() && generation.current === current) { setError(failure); if (moduleDenied(failure)) onDenied(failure) } }
    finally { if (alive.current && sessionGeneration === workflowActions.generation() && generation.current === current) setReading(false) }
  }
  function switchOperation(next: string) {
    const change = () => { if (alive.current) { setOperation(next); setDraft('{}'); setResult(''); setError(undefined); setValidation('') } }
    if (draft !== '{}') requestConfirm({ title: t('切换操作并放弃输入？'), message: t('当前操作的输入尚未保留。'), action: change })
    else change()
  }
  return <Modal open title={query.data?.data.title || t('{name}运行入口', { name: module.name })} wide placement="right" onClose={close} footer={<div className="modal-actions"><button className="button" disabled={reading || workflow.busy} onClick={close}>{t("关闭")}</button><button className="button primary" disabled={!spec || reading || workflow.busy || !!workflow.pending} onClick={() => spec?.mode === 'domain' ? requestConfirm({ title: t('执行模块变更？'), message: t('此操作会调用模块声明的业务所有者。'), action: () => void execute() }) : void execute()}>{reading ? t('执行中…') : spec?.mode === 'read' ? t('查询') : t('确认执行')}</button></div>}>
    <ManagementFeedback error={query.error || error} loading={query.isFetching} onRetry={() => void query.refetch()} />
    {validation ? <p role="alert">{t(validation)}</p> : null}
    {query.data && !moduleDenied(error) && !moduleDenied(query.error) ? <><p>{t("仅执行当前包声明并授权的操作。读取和业务变更分别通过原生入口处理。")}</p><label className="module-operation-select">{t("操作")}<select value={operation} disabled={reading || workflow.busy} onChange={event => switchOperation(event.target.value)}><option value="">{t("选择操作")}</option>{query.data.data.operations.map(row => <option key={row.id} value={row.id}>{row.id} · {row.mode === 'read' ? t('读取') : t('业务变更')}</option>)}</select></label>
      {spec ? <><CodeEditor label={t("操作输入 JSON")} language="json" value={draft} onChange={setDraft} disabled={reading || workflow.busy || !!workflow.pending} height={260} maxLength={32768} /><details><summary>{t("输入规范")}</summary><pre className="module-readme">{spec.input_schema_json}</pre></details></> : null}
      {result ? <section aria-label={t("运行结果")}><h4>{t("运行结果")}</h4><pre className="module-readme">{result}</pre></section> : null}</> : null}
  </Modal>
}
