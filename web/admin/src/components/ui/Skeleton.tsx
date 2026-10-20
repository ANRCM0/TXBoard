export function Skeleton({ variant = 'line', className = '' }: {
  variant?: 'line' | 'card' | 'chart'
  className?: string
}) {
  return <span className={'ui-skeleton ui-skeleton-' + variant + ' ' + className} aria-hidden="true" />
}

export function ChartSkeleton({ label }: { label: string }) {
  return <div className="ui-chart-skeleton" role="status" aria-label={label}>
    <Skeleton variant="chart" />
  </div>
}

export function TableSkeleton({ rows = 5, columns = 4, label = '正在加载列表' }: {
  rows?: number
  columns?: number
  label?: string
}) {
  return <div className="ui-skeleton-table" role="status" aria-label={label}>
    {Array.from({ length: rows }, (_, row) => <div className="ui-skeleton-row" key={row}>
      {Array.from({ length: columns }, (_, column) => <span className="ui-skeleton ui-skeleton-line" key={column} />)}
    </div>)}
  </div>
}
