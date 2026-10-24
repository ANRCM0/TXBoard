import type { ReactNode } from 'react'
import { t, useLocale } from '../lib/i18n'
import { EmptyState } from '../components/ui/EmptyState'

export function ListEmptyState({ filtered, scope = 'all', page, total, title, description, onClear, onFirstPage, createAction }: {
  filtered: boolean; scope?: 'all' | 'page'; page: number; total: number
  title: string; description: string; onClear: () => void; onFirstPage: () => void; createAction?: ReactNode
}) {
  useLocale()
  // A page beyond the remaining results must not look like an empty inventory.
  const emptyPage = page > 1 && (!filtered || (scope === 'all' && total > 0))
  if (emptyPage) return <EmptyState title={t('当前页已无数据')} description={t('数据可能已变更，返回第一页查看最新列表。')}
    action={<button type="button" className="button" onClick={onFirstPage}>{t('返回第一页')}</button>} />
  if (filtered) return <EmptyState title={t(scope === 'page' ? '当前页没有匹配项' : '没有找到匹配结果')}
    description={t(scope === 'page' ? '筛选仅作用于当前页，可清除筛选或切换分页继续查找。' : '请调整查询条件，或清除全部筛选重新查看。')}
    action={<button type="button" className="button" onClick={onClear}>{t('清除全部筛选')}</button>} />
  return <EmptyState title={title} description={description} action={createAction} />
}
