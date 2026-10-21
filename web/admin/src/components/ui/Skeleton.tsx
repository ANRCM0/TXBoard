import { t, useLocale } from '../../lib/i18n'
export function Skeleton({ variant = 'line', className = '' }: {
  variant?: 'line' | 'card' | 'chart'
  className?: string
}) {
  useLocale()
  return <span className={'ui-skeleton ui-skeleton-' + variant + ' ' + className} aria-hidden="true" />
}

export function ChartSkeleton({ label }: { label: string }) {
  useLocale()
  return <div className="ui-chart-skeleton" role="status" aria-label={t(label)}>
    <Skeleton variant="chart" />
  </div>
}

export function TableSkeleton({ rows = 5, columns = 4, label = t("正在加载列表") }: {
  rows?: number
  columns?: number
  label?: string
}) {
  useLocale()
  return <div className="ui-skeleton-table" role="status" aria-label={t(label)}>
    {Array.from({ length: rows }, (_, row) => <div className="ui-skeleton-row" key={row}>
      {Array.from({ length: columns }, (_, column) => <span className="ui-skeleton ui-skeleton-line" key={column} />)}
    </div>)}
  </div>
}
