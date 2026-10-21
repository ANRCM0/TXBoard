import { t, useLocale } from '../lib/i18n'
import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreOutput } from '@txboard/core-sdk'
import { admin, useAdminSession } from './session'
import { ManagementFeedback } from './Feedback'
import { ReadFields } from './ReadList'
import { DataTable, type Column } from '../components/ui/DataTable'
import { Modal } from '../components/ui/Modal'

type Failure = CoreOutput<'platform.queue.failures.list'>[number]
const forbidden = (error: unknown) => error instanceof CoreAdminApiError && [401, 403].includes(error.status)

export function QueueFailures({ compact = false, onDenied }: { compact?: boolean; onDenied?: (denied: boolean) => void }) {
  useLocale()
  const session = useAdminSession()
  const [opened, setOpened] = useState(!compact), [selected, setSelected] = useState('')
  const limit = compact ? 5 : 30
  const failures = useQuery({ queryKey: ['native-admin', 'queue-failures', limit], enabled: session && opened,
    queryFn: ({ signal }) => admin.read('platform.queue.failures.list', { limit }, signal) })
  const detail = useQuery({ queryKey: ['native-admin', 'queue-failure', selected], enabled: session && !!selected,
    queryFn: ({ signal }) => admin.read('platform.queue.failures.get', { failure_id: selected }, signal) })
  const denied = forbidden(failures.error) || forbidden(detail.error)
  const visible = session && !denied
  useEffect(() => { onDenied?.(denied) }, [denied, onDenied])
  useEffect(() => { if (!session) setSelected('') }, [session])
  const refresh = () => Promise.allSettled([failures.refetch(), ...(selected ? [detail.refetch()] : [])])
  const columns: Column<Failure>[] = [
    { key: 'id', header: t('失败记录'), render: row => <button className="native-read-link" onClick={() => setSelected(row.id)}>#{row.id}</button> },
    { key: 'connection', header: t('连接'), render: row => row.connection },
    { key: 'queue', header: t('队列'), render: row => <span className="observation-tag">{row.queue}</span> },
    { key: 'job', header: t('任务'), render: row => <span className="observation-mono">{row.job}</span> },
    { key: 'failed_at', header: t('失败时间'), render: row => <span className="observation-date">{row.failed_at}</span> },
  ]
  return <div className="native-read-list queue-failures">
    <div className="observation-card-heading"><div><h2>{t("失败任务")}</h2><p>{t('最近 {limit} 条记录', { limit })}</p></div>
      <button type="button" className="button" disabled={!session || failures.isFetching || detail.isFetching} onClick={() => { if (!opened) setOpened(true); else void refresh() }}>{opened ? t('刷新失败记录') : t('查看失败任务')}</button>
    </div>
    {opened ? <>
      <ManagementFeedback loading={failures.isFetching} error={failures.error} onRetry={() => void refresh()} />
      {denied ? <p role="alert">{t("无法确认失败记录权限，已隐藏列表和详情。")}</p> : null}
      {visible && !failures.isError && failures.data ? <DataTable label={t("队列失败记录")} rows={failures.data.data} columns={columns} rowKey={row => row.id} empty={t("没有失败记录")} mobilePrimaryKey="id" /> : null}
    </> : null}
    <Modal open={!!selected && visible} title={t("失败记录详情")} subtitle={t('失败任务 #{id}', { id: selected })} onClose={() => setSelected('')}>
      <ManagementFeedback loading={detail.isFetching} error={detail.error} onRetry={() => void detail.refetch()} />
      {visible && !detail.isError && detail.data ? <ReadFields fields={[
        [t('记录 ID'), detail.data.data.id], [t('连接'), detail.data.data.connection], [t('队列'), detail.data.data.queue],
        [t('任务'), detail.data.data.job], [t('失败时间'), detail.data.data.failed_at],
      ]} /> : null}
      <p className="native-list-note">{t("仅展示任务标识与运行元数据。")}</p>
    </Modal>
  </div>
}
