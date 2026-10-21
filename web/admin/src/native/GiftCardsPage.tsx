import { t, useLocale, getLocale } from '../lib/i18n'
import { useListPagination } from './useListPagination'
import './commerce-reference.css'
import { useEffect, useState, useSyncExternalStore } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { ManagementFeedback, managementError } from './Feedback'
import { ReadFields, ReadList } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import { Popover } from '../components/ui/Popover'
import { DataTable, type Column } from '../components/ui/DataTable'
import { CopyButton } from '../components/ui/CopyButton'
import { commerceMoney } from './commerce-format'
type Card = CoreOutput<'billing.admin-gift-cards.list'>[number] & { id: string }
type Batch = CoreOutput<'billing.admin-gift-batches.list'>[number] & { id: string }
const states = {reserved:'待兑换',redeemed:'已兑换',revoked:'已撤销',expired:'已过期'}
export function GiftCardsPage() {
  useLocale()
  const client = useQueryClient(), state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const { page, setPage, perPage, setPerPage } = useListPagination(50)
  const [error,setError] = useState('')
  const { page: batchPage, setPage: setBatchPage, perPage: batchSize, setPerPage: setBatchSize } = useListPagination(50)
  const [selectedBatch,setSelectedBatch] = useState(''), [tab, setTab] = useState<'cards' | 'batches'>('cards')
  const list = useQuery({queryKey:['native-admin','gift-cards', page, perPage],queryFn:({signal})=>admin.read('billing.admin-gift-cards.list',{page,per_page:perPage},signal)})
  const batches = useQuery({ queryKey: ['native-admin', 'gift-batches', 'list', batchPage, batchSize],
    queryFn: ({ signal }) => admin.read('billing.admin-gift-batches.list', { page: batchPage, per_page: batchSize }, signal) })
  const batch = useQuery({ queryKey: ['native-admin', 'gift-batches', 'detail', selectedBatch], enabled: !!selectedBatch,
    queryFn: ({ signal }) => admin.read('billing.admin-gift-batches.get', { batch_id: selectedBatch }, signal) })
  const denied = [list.error,batches.error,batch.error, !state.errorOperation || state.errorOperation.startsWith('billing.admin-gift-') ? state.error : undefined].some(failure=>failure instanceof CoreAdminApiError && [401,403].includes(failure.status))
  const pending = state.pending?.operation.startsWith('billing.admin-gift-cards.') ? state.pending : undefined
  const locked = denied || state.busy || !!state.pending
  const generation = workflowActions.generation()
  useEffect(() => { setSelectedBatch(''); setError('') }, [generation])
  useEffect(() => { if (denied) setSelectedBatch('') }, [denied])
  async function refresh() {
    const generation=workflowActions.generation()
    setError('')
    await Promise.all([
      client.invalidateQueries({queryKey:['native-admin','gift-cards']}),
      client.invalidateQueries({queryKey:['native-admin','gift-batches']}),
    ])
    if (generation===workflowActions.generation() && ['gift-cards', 'gift-batches'].every(resource => client.getQueryCache().findAll({queryKey:['native-admin',resource]}).filter(query => query.isActive()).every(query => query.state.status === 'success'))) { workflowActions.confirmReadAuthorization('billing.admin-gift-cards.'); workflowActions.clearError() }
  }
  async function revoke(row:Card) { if(locked)return; const generation=workflowActions.generation(); setError(''); try { await workflowActions.run('billing.admin-gift-cards.revoke',{card_id:row.card_id,expected_version:row.row_version}); await refresh() } catch(failure) { if(generation!==workflowActions.generation())return; setError(managementError(failure, false)); if(failure instanceof CoreAdminApiError && failure.status===409 && !failure.needsStateConfirmation) await refresh() } }
  async function recover() { const action=pending; if(!action || denied || state.busy)return; const generation=workflowActions.generation(); try { await workflowActions.run(action.operation,action.input); await refresh() } catch(failure) { if(generation!==workflowActions.generation())return; setError(managementError(failure, false)) } }
  const columns:Column<Card>[]=[
    {key:'card',header:t("卡片"),render:row=><div className="native-row-identity"><span className="commerce-copy-value"><strong className="commerce-identifier">{row.card_id}</strong><CopyButton value={row.card_id} label={t("复制礼品卡标识")} /></span><small>{t("卡片 ID · 非兑换码")}</small></div>},
    {key:'issuer',header:t("发行账户"),render:row=>row.issuer_id},
    {key:'recipient',header:t("兑换账户"),render:row=>row.recipient_id ?? t("尚未兑换")},
    {key:'amount',header:t("金额"),render:row=><span className="commerce-amount">{commerceMoney(row.amount_minor, row.currency)}</span>},
    {key:'state',header:t("状态"),render:row=><span className={'commerce-badge '+(row.state==='redeemed'?'success':row.state==='reserved'?'warning':'')}>{states[row.state]}</span>},
    {key:'created',header:t("发行 / 结束时间"),render:row=><div className="commerce-cell-stack"><span>{new Date(row.created_at*1000).toLocaleString(getLocale())}</span><small>{row.closed_at===null?t("尚未结束"):new Date(row.closed_at*1000).toLocaleString(getLocale())}</small></div>},
    {key:'expiry',header:t("到期时间"),render:row=>new Date(row.expires_at*1000).toLocaleString(getLocale())},
    {key:'action',header:t("操作"),pin:'right',hideable:false,render:row=><button className="button" disabled={locked || row.state!=='reserved'} title={t("仅未兑换卡片可撤销，资金退回发行人")} onClick={()=>void revoke(row)}>{t("撤销并退回发行人")}</button>}
  ]
  const batchColumns: Column<Batch>[] = [
    { key: 'label', header: t("批次"), render: row => <div className="native-row-identity"><strong>{row.label}</strong><span className="commerce-copy-value"><small>{row.batch_id}</small><CopyButton value={row.batch_id} label={t("复制批次号")} /></span></div> },
    { key: 'issuer', header: t("发行账户"), render: row => row.issuer_id },
    { key: 'quantity', header: t("数量"), render: row => row.quantity + t(" 张") },
    { key: 'amount', header: t("单张金额"), render: row => commerceMoney(row.amount_minor, row.currency) },
    { key: 'total', header: t("发行预留合计"), render: row => commerceMoney(row.total_minor, row.currency) },
    { key: 'expires', header: t("到期时间"), render: row => new Date(row.expires_at * 1000).toLocaleString(getLocale()) },
    { key: 'open', header: t("操作"), pin: 'right', hideable: false, render: row => <button className="button" disabled={state.busy} onClick={() => setSelectedBatch(row.batch_id)}>{t("查看逐卡状态")}</button> },
  ]
  const detail = !denied && !batch.isError ? batch.data?.data : undefined
  return <div className="native-management-page commerce-reference gift-cards-page"><PageHeader title={t("礼品卡管理")} description={t("查看用户钱包出资的单卡、批次及逐卡兑换状态")}
    action={<><Popover label={t("资金与卡码规则")}><p>{t("发行时预留发行人的钱包资金 · 未兑换卡片撤销或到期后退回发行人，已兑换资金不能通过撤销收回 · 卡码仅由发行人保管")}</p></Popover><button className="button" disabled={list.isFetching || batches.isFetching || batch.isFetching} onClick={() => void refresh()}>{t("刷新")}</button></>} />
    {pending ? <section className="list-card commerce-pending" role="status"><h2>{t("操作等待确认")}</h2><p>{t("确认上次撤销结果后，可继续管理礼品卡。")}</p><details className="commerce-help"><summary>{t("查看操作标识")}</summary><code>{pending.key}</code></details><button className="button" disabled={denied || state.busy} onClick={()=>void recover()}>{t("使用原操作确认结果")}</button></section> : null}
    {error || state.error ? <p role="alert">{error ? t(error) : managementError(state.error)}</p> : null}
    {denied ? <p role="alert">{t("无法确认礼品卡管理权限，已隐藏数据")}</p> : null}
    <div className="commerce-view-tabs" role="group" aria-label={t("礼品卡记录范围")}><button className="button" aria-pressed={tab === 'cards'} onClick={() => { setTab('cards'); setSelectedBatch('') }}>{t("单卡记录")}</button><button className="button" aria-pressed={tab === 'batches'} onClick={() => setTab('batches')}>{t("发行批次")}</button></div>
    {tab === 'cards' ? <ReadList mobilePrimaryKey="card" maxPageSize={50} title={"礼品卡"} rows={denied || list.isError ? [] : list.data?.data.map(row => ({ ...row, id: row.card_id })) ?? []} columns={columns} meta={denied || list.isError ? undefined : list.data?.meta} perPage={perPage} onPageSize={setPerPage} page={page} ready={!!list.data && !denied} loading={list.isFetching} error={list.isError?list.error:undefined} onRetry={()=>void refresh()} onPage={setPage}><header className="commerce-card-heading"><div><h2>{t("单卡记录")}</h2><p>{t("核对发行、兑换与资金状态；卡片标识不能用于兑换")}</p></div></header></ReadList> : null}
    {tab === 'batches' ? <ReadList mobilePrimaryKey="label" maxPageSize={50} title={"礼品卡批次"} rows={denied || batches.isError ? [] : batches.data?.data.map(row => ({ ...row, id: row.batch_id })) ?? []}
      columns={batchColumns} meta={denied || batches.isError ? undefined : batches.data?.meta} perPage={batchSize} onPageSize={setBatchSize} page={batchPage} ready={!!batches.data && !denied}
      loading={batches.isFetching} error={batches.isError ? batches.error : undefined} onRetry={() => void refresh()} onPage={setBatchPage}>
      <header className="commerce-card-heading"><div><h2>{t("发行批次")}</h2><p>{t("按批次核对发行资金与逐卡兑换情况")}</p></div></header>
    </ReadList> : null}
    {tab === 'batches' && selectedBatch && !denied ? <section className="list-card commerce-editor"><header className="commerce-card-heading"><div><h2>{t("批次详情")}</h2><p>{t("核对发行资金与兑换进度")}</p></div><button className="button" disabled={state.busy} onClick={() => setSelectedBatch('')}>{t("关闭详情")}</button></header>
      <ManagementFeedback error={batch.isError ? batch.error : undefined} loading={batch.isFetching} onRetry={() => void batch.refetch()} />
      {detail ? <><h3 className="commerce-batch-title">{detail.batch.label}</h3><div className="commerce-metrics">{Object.entries(states).map(([key,label]) => <div key={key}><small>{label}</small><strong>{detail.cards.filter(card => card.state === key).length}<span>{t("张")}</span></strong></div>)}</div>
        <ReadFields fields={[[t("批次号"), detail.batch.batch_id], [t("发行账户"), detail.batch.issuer_id], [t("数量"), detail.batch.quantity + t(" 张")], [t("单张金额"), commerceMoney(detail.batch.amount_minor, detail.batch.currency)],
          [t("发行预留合计"), commerceMoney(detail.batch.total_minor, detail.batch.currency)], [t("到期时间"), new Date(detail.batch.expires_at * 1000).toLocaleString(getLocale())]]} />
        <p className="native-scroll-hint">{t("左右滑动表格查看完整信息")}</p>
        <DataTable mobilePrimaryKey="card" rows={detail.cards.map(row => ({ ...row, id: row.card_id }))} columns={columns} rowKey={row => row.card_id} label={t("批次内卡片")} stickyHeader stickyFirstColumn stickyLastColumn empty={t("本批次没有卡片")} />
      </> : null}
    </section> : null}
  </div>
}
