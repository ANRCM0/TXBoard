import { t, useLocale } from '../../lib/i18n'
import { useEffect, useId, useState } from 'react'
import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from 'lucide-react'
import './experience-lists.css'

export function TablePagination({ label, page, meta, loading, onPage, perPage, onPageSize, selectedCount, maxPageSize = 100 }: {
  label: string; page: number; meta?: { total: number; last_page: number }; loading: boolean
  onPage: (page: number) => void; perPage?: number; onPageSize?: (size: number) => void; selectedCount?: number
  maxPageSize?: number
}) {
  useLocale()
  const [pageInput, setPageInput] = useState(String(page))
  const [invalidPage, setInvalidPage] = useState(false)
  const errorId = useId()
  useEffect(() => { setPageInput(String(page)); setInvalidPage(false) }, [page, meta?.last_page])
  const lastPage = Math.max(1, Math.min(meta?.last_page ?? page, 100000))
  const disabled = loading || !meta
  const pages = [...new Set([1, page - 1, page, page + 1, lastPage])].filter(value => value >= 1 && value <= lastPage)
  function commit() {
    if (disabled) return
    const next = Number(pageInput)
    if (/^[1-9][0-9]*$/.test(pageInput) && Number.isSafeInteger(next) && next <= lastPage) {
      setInvalidPage(false)
      if (next !== page) onPage(next)
    } else {
      setPageInput(String(page))
      setInvalidPage(true)
    }
  }
  return <div className="native-pagination" aria-label={t('{label}分页', { label: t(label) })}>
    <span className="native-pagination-summary" aria-live="polite">{selectedCount !== undefined ? t('已选当前页 {count} 项 · ', { count: selectedCount }) : ''}{meta ? t('共 {total} 项 · 第 {page} 页 / 共 {lastPage} 页', { total: meta.total, page, lastPage }) : t('第 {page} 页', { page })}</span>
    {onPageSize && perPage ? <label className="native-page-size">{t("每页显示")}<select aria-label={t("每页显示条数")} value={perPage} disabled={disabled}
      onChange={event => onPageSize(Number(event.target.value))}>{[10, 20, 50, 100].filter(size => size <= maxPageSize).map(size => <option key={size} value={size}>{t('{count} 条', { count: size })}</option>)}</select></label> : null}
    <nav className="native-page-controls" aria-label={t('{label}页码', { label: t(label) })}>
      <button type="button" className="button native-page-arrow native-page-boundary" aria-label={t("第一页")} disabled={disabled || page <= 1} onClick={() => onPage(1)}><ChevronsLeft size={16} /></button>
      <button type="button" className="button native-page-arrow" aria-label={t("上一页")} disabled={disabled || page <= 1} onClick={() => onPage(page - 1)}><ChevronLeft size={16} /></button>
      {pages.map((value, index) => <span className="native-page-item" key={value}>
        {index > 0 && value - pages[index - 1] > 1 ? <span className="native-page-ellipsis">…</span> : null}
        <button type="button" className={'button native-page-number' + (value === page ? ' is-current' : '')} aria-label={t('第 {page} 页', { page: value })} aria-current={value === page ? 'page' : undefined}
          disabled={disabled || value === page} onClick={() => onPage(value)}>{value}</button>
      </span>)}
      <button type="button" className="button native-page-arrow" aria-label={t("下一页")} disabled={disabled || page >= lastPage} onClick={() => onPage(page + 1)}><ChevronRight size={16} /></button>
      <button type="button" className="button native-page-arrow native-page-boundary" aria-label={t("最后一页")} disabled={disabled || page >= lastPage} onClick={() => onPage(lastPage)}><ChevronsRight size={16} /></button>
    </nav>
    <div className="native-page-jump-control">
      <label className="native-page-jump">{t("跳至")}<input aria-label={t("跳转页码")} inputMode="numeric" value={pageInput} disabled={disabled}
        aria-invalid={invalidPage || undefined} aria-describedby={invalidPage ? errorId : undefined}
        onChange={event => { setPageInput(event.target.value); setInvalidPage(false) }}
        onKeyDown={event => {
          if (event.nativeEvent.isComposing) return
          if (event.key === 'Enter') { event.preventDefault(); commit() }
          if (event.key === 'Escape') { event.preventDefault(); setPageInput(String(page)); setInvalidPage(false) }
        }} />{t("页")}</label>
      <button type="button" className="button native-page-jump-submit" disabled={disabled} onClick={commit}>{t("跳转")}</button>
    </div>
    {invalidPage ? <span id={errorId} className="native-page-jump-error" role="status">{t('请输入 1 至 {lastPage} 的页码。', { lastPage })}</span> : null}
  </div>
}
