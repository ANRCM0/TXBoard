import { t, useLocale } from '../lib/i18n'
import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { admin } from './session'
import { ManagementFeedback } from './Feedback'
import { commerceMoney } from './commerce-format'

export function OfferSelector({ value, onChange, disabled, onReady }: {
  value: string[]; onChange: (ids: string[]) => void; disabled?: boolean; onReady: (ready: boolean) => void
}) {
  useLocale()
  const [page, setPage] = useState(1), [search, setSearch] = useState('')
  const query = useQuery({ queryKey: ['native-admin', 'coupon-offer-picker', page],
    queryFn: ({ signal }) => admin.read('catalog.admin-offers.list', { page, per_page: 100, status: 'published' }, signal) })
  const ready = !!query.data && !query.isFetching && !query.isError
  useEffect(() => { onReady(ready); return () => onReady(false) }, [ready, onReady])
  const rows = query.isError ? [] : (query.data?.data ?? []).filter(row =>
    [row.name, row.offer_id, row.offer_code].some(text => text.toLowerCase().includes(search.trim().toLowerCase())))
  return <div className="commerce-offer-selector" aria-label={t("选择适用商品")}>
    <label>{t("搜索本页商品")}<input value={search} disabled={disabled} onChange={event => setSearch(event.target.value)} placeholder={t("套餐名称、周期或商品编号")} /></label>
    <p className="commerce-table-description">{t("每次读取 100 个当前发布商品；搜索仅作用本页。已选商品跨页保留，优惠资格按商品编号校验。")}</p>
    <ManagementFeedback loading={query.isFetching} error={query.error} onRetry={() => void query.refetch()} />
    {!query.isError ? <div className="commerce-offer-options">{rows.map(row => <label key={row.offer_id}>
      <input type="checkbox" checked={value.includes(row.offer_id)} disabled={disabled || !ready || !value.includes(row.offer_id) && value.length >= 100}
        onChange={event => onChange(event.target.checked ? [...new Set([...value, row.offer_id])] : value.filter(id => id !== row.offer_id))} />
      <span><strong>{row.name}</strong><small>{row.offer_code} · {commerceMoney(row.price_minor, row.currency)} · #{row.offer_id}</small></span>
    </label>)}</div> : null}
    {ready && !rows.length ? <p>{t("本页没有符合条件的商品。")}</p> : null}
    <div className="commerce-picker-pagination"><button type="button" className="button" disabled={disabled || !ready || page === 1} onClick={() => { setPage(current => current - 1); setSearch('') }}>{t("上一页商品")}</button>
      <span>{t("第")}{page} / {query.isError ? '?' : query.data?.meta.last_page ?? '?'}{t("页")}</span>
      <button type="button" className="button" disabled={disabled || !ready || page >= (query.data?.meta.last_page ?? 1)} onClick={() => { setPage(current => current + 1); setSearch('') }}>{t("下一页商品")}</button></div>
    {!query.isError ? <div aria-label={t("已选商品编号")} className="commerce-selected-offers"><span>{t("已选")}{value.length}{t("/ 100 项")}</span>{value.map(id => <button type="button" className="reference-tag" key={id} disabled={disabled} onClick={() => onChange(value.filter(value => value !== id))} aria-label={t("移除商品 ") + id}>#{id} ×</button>)}</div> : null}
  </div>
}
