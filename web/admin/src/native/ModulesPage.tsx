import { t, useLocale } from '../lib/i18n'
import { Blocks, LockKeyhole, RefreshCw } from 'lucide-react'
import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreOutput } from '@txboard/core-sdk'
import { admin, useAdminSession, workflowActions } from './session'
import { ManagementFeedback } from './Feedback'
import { PageHeader } from '../components/ui/PageHeader'
import { StatusBadge } from '../components/ui/StatusBadge'
import { Modal } from '../components/ui/Modal'
import { requestConfirm } from '../components/ui/ConfirmDialog'
import { ModuleConfigurationDialog, ModuleRuntimeDialog } from './ModuleDialogs'
import { ThemePackagesDialog } from './ThemePackagesDialog'
import './modules-reference.css'

export type ModuleCard = CoreOutput<'platform.module-cards.list'>['modules'][number]
export const moduleActionLabels = { install: '安装', enable: '启用', disable: '停用', upgrade: '升级', uninstall: '卸载' }
export const moduleDenied = (error: unknown) => error instanceof CoreAdminApiError && [401, 403].includes(error.status)
const extensionOperation = (operation?: string) => operation?.startsWith('platform.module-') || operation?.startsWith('platform.theme-packages.')
const actionLabel = (row: ModuleCard, action: ModuleCard['actions'][number]) => row.type === 'theme' ? action === 'enable' ? '应用主题' : action === 'uninstall' ? '删除主题' : moduleActionLabels[action] : moduleActionLabels[action]
type View = { id: string; mode: 'documentation' | 'configuration' | 'runtime' }
export function ModulesPage({ kind }: { kind?: 'plugin' | 'theme' }) {
  useLocale()
  const session = useAdminSession(), client = useQueryClient()
  const workflow = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [view, setView] = useState<View>(), [keyword, setKeyword] = useState(''), [category, setCategory] = useState('feature')
  const [message, setMessage] = useState(''), [messageVariables, setMessageVariables] = useState<Record<string, string>>({}), [localError, setLocalError] = useState<unknown>(), [recovering, setRecovering] = useState(false)
  const [packagesOpen, setPackagesOpen] = useState(false)
  const sessionGeneration = useSyncExternalStore(workflowActions.subscribe, workflowActions.generation)
  const generation = useRef(0), alive = useRef(true)
  useEffect(() => { alive.current = true; return () => { alive.current = false; generation.current++ } }, [])
  useEffect(() => { generation.current++; setView(undefined); setPackagesOpen(false); setKeyword(''); setCategory('feature'); setMessage(''); setLocalError(undefined) }, [kind, session, sessionGeneration])
  const list = useQuery({ queryKey: ['native-admin', 'module-cards'], enabled: session,
    queryFn: ({ signal }) => admin.read('platform.module-cards.list', {}, signal) })
  const detail = useQuery({ queryKey: ['native-admin', 'module-card', view?.id], enabled: session && !!view,
    queryFn: ({ signal }) => admin.read('platform.module-cards.get', { module_id: view!.id }, signal) })
  const scopedError = extensionOperation(workflow.errorOperation) ? workflow.error : undefined
  const denied = [list.error, detail.error, localError, scopedError].some(moduleDenied), visible = session && !denied
  useEffect(() => { if (denied) { generation.current++; setView(undefined); setPackagesOpen(false); setMessage('') } }, [denied])
  const pending = workflow.pending && (workflow.pending.operation === 'platform.module-configuration.save' || workflow.pending.operation === 'platform.module-lifecycle.execute' || workflow.pending.operation === 'platform.module-presentation.execute' || workflow.pending.operation === 'platform.theme-packages.deploy') ? workflow.pending : undefined
  const rows = visible && !list.isError ? (list.data?.data.modules ?? []).filter(row => (!kind || row.type === kind)
    && (kind !== 'plugin' || category === 'all' || (category === 'other' ? row.subtype === null : row.subtype === category))
    && [row.id, row.name, row.description].some(text => text.toLowerCase().includes(keyword.trim().toLowerCase()))) : []
  const selected = visible && !detail.isError ? detail.data?.data.module : undefined
  async function refresh() {
    setLocalError(undefined)
    if (extensionOperation(workflow.errorOperation)) workflowActions.clearError()
    await Promise.all([list.refetch(), ...(view ? [detail.refetch()] : [])])
    await client.invalidateQueries({ queryKey: ['native-admin', 'module-configuration'] })
  }
  async function lifecycle(row: ModuleCard, action: ModuleCard['actions'][number]) {
    if (!alive.current || !session || !visible || workflowActions.generation() !== sessionGeneration || workflowActions.snapshot().busy || workflowActions.snapshot().pending || !row.fingerprint || !row.revision) return
    const current = generation.current
    try { setLocalError(undefined); setMessage(''); await workflowActions.run('platform.module-lifecycle.execute', { module_id: row.id, fingerprint: row.fingerprint, expected_revision: row.revision, action })
      if (alive.current && generation.current === current) { setMessageVariables({ name: row.name, action: actionLabel(row, action) }); setMessage('module-action-completed'); await refresh() }
    } catch (error) { if (alive.current && generation.current === current) setLocalError(error) }
  }
  function confirmLifecycle(row: ModuleCard, action: ModuleCard['actions'][number]) {
    const current = generation.current
    requestConfirm({ title: t('{action}{name}', { action: t(actionLabel(row, action)), name: row.name }), message: action === 'uninstall' ? row.type === 'theme' ? t('删除会移除未使用主题的安装文件、公开资产、回滚槽和配置。此操作不可撤销。') : t('卸载会停止模块并执行其已声明的数据卸载流程。请先确认已完成所需备份。') : t('变更由受控部署所有者执行，并校验当前包指纹和配置修订。'),
      danger: action === 'uninstall' || action === 'disable', action: () => { if (alive.current && generation.current === current) void lifecycle(row, action) } })
  }
  async function recover() {
    if (!alive.current || !session || !pending || workflow.busy || recovering) return
    const current = generation.current
    setRecovering(true); setLocalError(undefined)
    try {
      if (pending.operation === 'platform.theme-packages.deploy') await admin.read('platform.theme-packages.list', {})
      else {
        if (!(pending.operation === 'platform.module-lifecycle.execute' && pending.input.action === 'uninstall' && pending.input.module_id.startsWith('theme.'))) await admin.read('platform.module-cards.get', { module_id: pending.input.module_id })
        else await admin.read('platform.module-cards.list', {})
        if (pending.operation === 'platform.module-configuration.save') await admin.read('platform.module-configuration.get', { module_id: pending.input.module_id })
        if (pending.operation === 'platform.module-presentation.execute') await admin.read('platform.module-presentation.get', { module_id: pending.input.module_id, fingerprint: pending.input.fingerprint })
      }
      if (!alive.current || generation.current !== current) return
      workflowActions.confirmReadAuthorization(pending.operation === 'platform.theme-packages.deploy' ? 'platform.theme-packages.' : 'platform.module-')
      if (pending.operation === 'platform.module-configuration.save') await workflowActions.run(pending.operation, pending.input)
      else if (pending.operation === 'platform.module-lifecycle.execute') await workflowActions.run(pending.operation, pending.input)
      else if (pending.operation === 'platform.module-presentation.execute') await workflowActions.run(pending.operation, pending.input)
      else if (pending.operation === 'platform.theme-packages.deploy') await workflowActions.run(pending.operation, pending.input)
      if (alive.current && generation.current === current) { setMessage('原请求结果已确认'); await refresh() }
    } catch (error) { if (alive.current && generation.current === current) setLocalError(error) }
    finally { if (alive.current) setRecovering(false) }
  }
  return <div className="native-management-page modules-page">
    <PageHeader title={kind === 'theme' ? t('主题管理') : kind === 'plugin' ? t('插件管理') : t('模块清单')} description={t("查看已声明能力，管理配置和受控生命周期")} action={<>{kind === 'theme' ? <button className="button primary" disabled={!visible || workflow.busy || !!workflow.pending} onClick={() => setPackagesOpen(true)}>{t("部署受控主题")}</button> : null}<button className="button" disabled={!session || list.isFetching} onClick={() => void refresh()}><RefreshCw size={15} />{t("刷新")}</button></>} />
    {kind === 'theme' ? <p className="modules-owner-note">{t("站点主题配置由主题所有者保存。当前可用操作以卡片为准；管理端本机明暗偏好在导航栏设置。")}</p> : null}
    {pending ? <div className="modules-recovery" role="status"><p>{t('{resource} 的写入结果待确认。确认前暂停其他变更，恢复会使用原请求和原幂等键。', { resource: pending.operation === 'platform.theme-packages.deploy' ? t('候选包 {id}', { id: pending.input.candidate_id }) : t('模块 {id}', { id: pending.input.module_id }) })}</p><button className="button" disabled={!session || workflow.busy || recovering} onClick={() => void recover()}>{recovering ? t('确认权限与结果中…') : t('恢复原请求')}</button></div> : null}
    <div className="modules-toolbar">{kind === 'plugin' ? <div className="modules-tabs" role="group" aria-label={t("插件分类")}>{[['feature', t('功能插件')], ['payment', t('支付插件')], ['other', t('其他')], ['all', t('全部')]].map(([id, label]) => <button className="button" aria-pressed={category === id} key={id} onClick={() => setCategory(id)}>{t(label)}</button>)}</div> : null}<label>{t("搜索")}<input className="input" placeholder={t("名称、ID 或描述")} maxLength={120} value={keyword} onChange={event => setKeyword(event.target.value)} /></label></div>
    <ManagementFeedback loading={list.isFetching} error={list.error || localError || scopedError} onRetry={() => void refresh()} />
    {message ? <p role="status">{message === 'module-action-completed' ? t('{name}：{action}完成', { name: messageVariables.name, action: t(messageVariables.action) }) : t(message)}</p> : null}
    {denied ? <p role="alert">{t("无法确认模块权限，已隐藏清单、详情和配置草稿。可重新读取权限后恢复原请求。")}</p> : null}
    {visible && list.data && !list.isError ? <>{list.data.data.discovery_errors ? <p role="status">{t('{count} 项模块发现异常，请检查部署日志。', { count: list.data.data.discovery_errors })}</p> : null}<div className="module-cards">{rows.map(row => <article key={row.id} className="module-card">
      <div className="module-card-heading"><div className="module-icon"><Blocks size={23} /></div><div><button className="native-read-link" onClick={() => setView({ id: row.id, mode: 'documentation' })}><strong>{row.name}</strong></button><small>{row.id}</small></div>{row.protected ? <span title={t("受保护模块")}><LockKeyhole size={16} /><span className="sr-only">{t("受保护")}</span></span> : null}</div>
      <p className="module-description">{row.description || t('未提供说明')}</p>
      <div className="module-badges"><span>v{row.version}</span><span>{t(({ system: '系统', bundled: '内置', user: '用户安装', external: '外部' })[row.source])}</span><StatusBadge tone={row.enabled ? 'success' : 'neutral'}>{row.enabled ? t('已启用') : row.installed ? t('已停用') : t('未安装')}</StatusBadge>{row.upgrade_available ? <StatusBadge tone="warning">{t("可升级")}</StatusBadge> : null}<span>{({ healthy: t('运行正常'), degraded: t('运行降级'), disabled: t('未运行'), failed: t('运行失败'), incompatible: t('不兼容'), missing_dependency: t('缺少依赖') })[row.health]}</span>{row.active ? <span>{t("当前站点主题")}</span> : null}</div>
      <div className="module-card-actions"><button className="button" onClick={() => setView({ id: row.id, mode: 'documentation' })}>{t("文档")}</button>{row.configuration_available ? <button className="button" onClick={() => setView({ id: row.id, mode: 'configuration' })}>{t("配置")}</button> : null}{row.presentation_available ? <button className="button" onClick={() => setView({ id: row.id, mode: 'runtime' })}>{t("运行入口")}</button> : null}{row.actions.map(action => <button key={action} className={'button' + (action === 'uninstall' ? ' danger' : '')} disabled={workflow.busy || !!workflow.pending} onClick={() => confirmLifecycle(row, action)}>{t(actionLabel(row, action))}</button>)}</div>
      {!row.managed && row.type !== 'theme' ? <small>{t("由现有模块所有者维护，未声明受控管理能力。")}</small> : null}
    </article>)}</div>{!rows.length ? <p className="modules-empty">{t("没有符合条件的模块")}</p> : null}</> : null}
    {view?.mode === 'documentation' && visible ? <Modal open title={selected?.name || t('模块文档')} onClose={() => setView(undefined)} wide><ManagementFeedback error={detail.error} loading={detail.isFetching} onRetry={() => void detail.refetch()} />{selected ? <><p>{selected.description}</p><p>{t('作者：{author} · 版本 {version}', { author: selected.author || t('未声明'), version: selected.version })}</p><pre className="module-readme">{detail.data?.data.readme || t('此模块没有随包提供文档。')}</pre></> : null}</Modal> : null}
    {view?.mode === 'configuration' && selected && visible ? <ModuleConfigurationDialog key={selected.id} module={selected} onClose={() => setView(undefined)} onSaved={refresh} onDenied={setLocalError} /> : null}
    {view?.mode === 'runtime' && selected && visible ? <ModuleRuntimeDialog key={selected.id} module={selected} onClose={() => setView(undefined)} onDenied={setLocalError} /> : null}
    {packagesOpen && visible ? <ThemePackagesDialog onClose={() => setPackagesOpen(false)} onSaved={refresh} onDenied={setLocalError} /> : null}
  </div>
}
