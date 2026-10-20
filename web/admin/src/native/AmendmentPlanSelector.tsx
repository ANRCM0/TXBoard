import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import type { CoreOutput } from '@txboard/core-sdk'
import { t, useLocale } from '../lib/i18n'
import { admin, workflowActions } from './session'
import { ManagementFeedback, managementError } from './Feedback'

type Publication = CoreOutput<'catalog.admin-plans.get'>
export function AmendmentPlanSelector({ disabled, onSelected }: { disabled: boolean; onSelected: (value: Publication) => void }) {
  useLocale()
  const [page, setPage] = useState(1), [search, setSearch] = useState(''), [opening, setOpening] = useState(false), [error, setError] = useState('')
  const view = useRef(0), generation = workflowActions.generation()
  useEffect(() => () => { ++view.current }, [])
  const list = useQuery({ queryKey: ['native-admin', 'amendment-plan-picker', generation, page],
    queryFn: ({ signal }) => admin.read('catalog.admin-plans.list', { page, per_page: 25 }, signal) })
  async function select(planId: string, revisionId: string) {
    if (disabled || opening) return
    const current = ++view.current; setOpening(true); setError('')
    try {
      const result = await admin.read('catalog.admin-plans.get', { plan_id: planId, revision_id: revisionId })
      if (view.current !== current || workflowActions.generation() !== generation) return
      if (result.data.current_revision_id !== revisionId || result.data.revision.revision_id !== revisionId) {
        setError('套餐发布修订已变化，请刷新选择列表后重选。'); await list.refetch(); return
      }
      onSelected(result.data)
    } catch (failure) { if (view.current === current && workflowActions.generation() === generation) setError(managementError(failure, false)) }
    finally { if (view.current === current && workflowActions.generation() === generation) setOpening(false) }
  }
  const rows = list.isError ? [] : (list.data?.data ?? []).filter(row => [row.name, row.plan_id].some(value => value.toLowerCase().includes(search.toLowerCase())))
  return <div className="commerce-offer-selector" aria-label={t('选择管理员权益套餐')}>
    <label>{t('搜索本页套餐')}<input value={search} disabled={disabled || opening} onChange={event => setSearch(event.target.value)} /></label>
    <p>{t('选择当前发布的不可变套餐修订，导入配额、权限组、限速及设备数；原到期与重置日保持原语义。')}</p>
    <ManagementFeedback error={list.error} loading={list.isFetching} onRetry={() => void list.refetch()} />
    {error ? <p role="alert">{t(error)}</p> : null}
    {rows.map(row => <button type="button" className="button" key={row.plan_id} disabled={disabled || opening || list.isFetching || list.isError}
      onClick={() => void select(row.plan_id, row.current_revision_id)}>{row.name} · #{row.plan_id} · {t('修订')} #{row.current_revision_id}</button>)}
    {!list.isError && !list.isFetching && !rows.length ? <p>{t('本页没有可选的已发布套餐。')}</p> : null}
    <div className="commerce-picker-pagination"><button type="button" className="button" disabled={disabled || opening || list.isFetching || page === 1} onClick={() => { setPage(value => value - 1); setSearch('') }}>{t('上一页')}</button>
      <span>{page} / {list.isError ? '?' : list.data?.meta.last_page ?? '?'}</span>
      <button type="button" className="button" disabled={disabled || opening || list.isFetching || list.isError || page >= (list.data?.meta.last_page ?? 1)} onClick={() => { setPage(value => value + 1); setSearch('') }}>{t('下一页')}</button></div>
  </div>
}
