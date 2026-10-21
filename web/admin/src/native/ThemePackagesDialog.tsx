import { t, useLocale } from '../lib/i18n'
import { useEffect, useRef, useState, useSyncExternalStore } from 'react'
import { useQuery } from '@tanstack/react-query'
import type { CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { ManagementFeedback } from './Feedback'
import { Modal } from '../components/ui/Modal'
import { requestConfirm } from '../components/ui/ConfirmDialog'
import { moduleDenied } from './ModulesPage'

type Candidate = CoreOutput<'platform.theme-packages.list'>['candidates'][number]
export function ThemePackagesDialog({ onClose, onSaved, onDenied }: { onClose(): void; onSaved(): Promise<void>; onDenied(error: unknown): void }) {
  useLocale()
  const workflow = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [error, setError] = useState<unknown>(), [message, setMessage] = useState(''), [messageName, setMessageName] = useState('')
  const alive = useRef(true)
  useEffect(() => { alive.current = true; return () => { alive.current = false } }, [])
  const query = useQuery({ queryKey: ['native-admin', 'theme-packages'], queryFn: ({ signal }) => admin.read('platform.theme-packages.list', {}, signal) })
  const locked = workflow.busy || !!workflow.pending
  async function deploy(candidate: Candidate, generation: number) {
    if (!alive.current || workflowActions.generation() !== generation || workflowActions.snapshot().busy || workflowActions.snapshot().pending) return
    try {
      setError(undefined); setMessage('')
      await workflowActions.run('platform.theme-packages.deploy', { candidate_id: candidate.id, sha256: candidate.sha256, expected_revision: candidate.expected_revision, action: candidate.action })
      if (!alive.current || workflowActions.generation() !== generation) return
      setMessageName(candidate.title); setMessage(candidate.action === 'install' ? '{name}已安装，请在主题卡片中应用。' : '{name}已升级；原版本已进入所有者的回滚槽。')
      await Promise.all([query.refetch(), onSaved()])
    } catch (failure) { if (alive.current && workflowActions.generation() === generation) { setError(failure); if (moduleDenied(failure)) onDenied(failure) } }
  }
  function confirm(candidate: Candidate) {
    const generation = workflowActions.generation()
    requestConfirm({ title: t('{action}{name}', { action: candidate.action === 'install' ? t('安装') : t('升级'), name: candidate.title }),
      message: candidate.action === 'install' ? t('安装已由部署所有者提供并固定摘要的静态主题包。新主题安装后保持未激活。') : t('升级已部署主题。当前使用中的主题会同步发布新资产，原版本保留在回滚槽。'),
      action: () => void deploy(candidate, generation) })
  }
  return <Modal open wide title={t("部署受控主题包")} onClose={() => { if (!workflow.busy) onClose() }} footer={<div className="modal-actions"><button className="button" disabled={workflow.busy} onClick={onClose}>{t("关闭")}</button><button className="button" disabled={locked || query.isFetching} onClick={() => void query.refetch()}>{t("刷新候选包")}</button></div>}>
    <p>{t("候选包来自部署所有者的本地受信策略。每次部署均核对固定 SHA-256、兼容性和当前主题修订。")}</p>
    <ManagementFeedback error={query.error || error} loading={query.isFetching} onRetry={() => void query.refetch()} />
    {message ? <p role="status">{t(message, { name: messageName })}</p> : null}
    {query.data && !query.isError && !moduleDenied(error) ? <div className="theme-package-list">{query.data.data.candidates.length ? query.data.data.candidates.map(candidate => <article key={candidate.id} className="theme-package-card"><div><h4>{candidate.title}</h4><p>{candidate.module_id} · v{candidate.version}</p><p>{candidate.installed_version ? t('已安装 v{version}', { version: candidate.installed_version }) : t('尚未安装')}</p><details><summary>{t("包摘要")}</summary><code>{candidate.sha256}</code></details></div><button className="button primary" disabled={locked || !candidate.eligible} onClick={() => confirm(candidate)}>{!candidate.eligible ? t('当前版本无需升级') : candidate.action === 'install' ? t('安装此主题') : t('升级此主题')}</button></article>) : <p>{t("暂未配置可部署的候选包。请由部署所有者将经过审核的静态主题 ZIP 纳入候选策略。")}</p>}</div> : null}
  </Modal>
}
