import './commerce-reference.css'
import { useState, useSyncExternalStore } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { ManagementFeedback, managementError } from './Feedback'
import { ReadFields, ReadList } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import { DataTable, type Column } from '../components/ui/DataTable'
type Card = CoreOutput<'billing.admin-gift-cards.list'>[number] & { id: string }
type Batch = CoreOutput<'billing.admin-gift-batches.list'>[number] & { id: string }
const states = {reserved:'待兑换',redeemed:'已兑换',revoked:'已撤销',expired:'已过期'}
export function GiftCardsPage() {
  const client = useQueryClient(), state = useSyncExternalStore(workflowActions.subscribe, workflowActions.snapshot)
  const [page,setPage] = useState(1), [error,setError] = useState('')
  const [batchPage,setBatchPage] = useState(1), [selectedBatch,setSelectedBatch] = useState('')
  const list = useQuery({queryKey:['native-admin','gift-cards',page],queryFn:({signal})=>admin.read('billing.admin-gift-cards.list',{page,per_page:20},signal)})
  const batches = useQuery({ queryKey: ['native-admin', 'gift-batches', 'list', batchPage],
    queryFn: ({ signal }) => admin.read('billing.admin-gift-batches.list', { page: batchPage, per_page: 20 }, signal) })
  const batch = useQuery({ queryKey: ['native-admin', 'gift-batches', 'detail', selectedBatch], enabled: !!selectedBatch,
    queryFn: ({ signal }) => admin.read('billing.admin-gift-batches.get', { batch_id: selectedBatch }, signal) })
  const denied = [list.error,batches.error,batch.error,state.error].some(failure=>failure instanceof CoreAdminApiError && [401,403].includes(failure.status))
  const pending = state.pending?.operation.startsWith('billing.admin-gift-cards.') ? state.pending : undefined
  const locked = denied || state.busy || !!state.pending
  async function refresh() {
    const generation=workflowActions.generation()
    setError('')
    await Promise.all([
      client.invalidateQueries({queryKey:['native-admin','gift-cards']}),
      client.invalidateQueries({queryKey:['native-admin','gift-batches']}),
    ])
    if (generation===workflowActions.generation() && ['gift-cards', 'gift-batches'].every(resource => client.getQueryCache().findAll({queryKey:['native-admin',resource]}).filter(query => query.isActive()).every(query => query.state.status === 'success'))) { workflowActions.confirmReadAuthorization('billing.admin-gift-cards.'); workflowActions.clearError() }
  }
  async function revoke(row:Card) { if(locked)return; const generation=workflowActions.generation(); setError(''); try { await workflowActions.run('billing.admin-gift-cards.revoke',{card_id:row.card_id,expected_version:row.row_version}); await refresh() } catch(failure) { if(generation!==workflowActions.generation())return; setError(managementError(failure)); if(failure instanceof CoreAdminApiError && failure.status===409 && !failure.needsStateConfirmation) await refresh() } }
  async function recover() { const action=pending; if(!action || denied || state.busy)return; const generation=workflowActions.generation(); try { await workflowActions.run(action.operation,action.input); await refresh() } catch(failure) { if(generation!==workflowActions.generation())return; setError(managementError(failure)) } }
  const columns:Column<Card>[]=[
    {key:'card',header:'卡片',render:row=><div className="native-row-identity"><strong className="commerce-identifier">{row.card_id}</strong><small>礼品卡标识</small></div>},
    {key:'issuer',header:'发行账户',render:row=>row.issuer_id},
    {key:'recipient',header:'兑换账户',render:row=>row.recipient_id ?? '尚未兑换'},
    {key:'amount',header:'金额',render:row=><span className="commerce-amount">{row.amount_minor} 分 {row.currency}</span>},
    {key:'state',header:'状态',render:row=><span className={'commerce-badge '+(row.state==='redeemed'?'success':row.state==='reserved'?'warning':'')}>{states[row.state]}</span>},
    {key:'expiry',header:'到期时间',render:row=>new Date(row.expires_at*1000).toLocaleString()},
    {key:'action',header:'操作',render:row=><button className="button" disabled={locked || row.state!=='reserved'} title="仅未兑换卡片可撤销，资金退回发行人" onClick={()=>void revoke(row)}>撤销并退回发行人</button>}
  ]
  const batchColumns: Column<Batch>[] = [
    { key: 'label', header: '批次', render: row => <div className="native-row-identity"><strong>{row.label}</strong><small>{row.batch_id}</small></div> },
    { key: 'issuer', header: '发行账户', render: row => row.issuer_id },
    { key: 'quantity', header: '数量', render: row => row.quantity + ' 张' },
    { key: 'amount', header: '单张金额', render: row => row.amount_minor + ' 分 ' + row.currency },
    { key: 'total', header: '发行预留合计', render: row => row.total_minor + ' 分 ' + row.currency },
    { key: 'expires', header: '到期时间', render: row => new Date(row.expires_at * 1000).toLocaleString() },
    { key: 'open', header: '操作', render: row => <button className="button" disabled={state.busy} onClick={() => setSelectedBatch(row.batch_id)}>查看逐卡状态</button> },
  ]
  const detail = !denied && !batch.isError ? batch.data?.data : undefined
  return <div className="native-management-page commerce-reference gift-cards-page"><PageHeader title="礼品卡管理" description="查看用户钱包出资的单卡、批次及逐卡兑换状态"
    action={<button className="button" disabled={list.isFetching || batches.isFetching || batch.isFetching} onClick={() => void refresh()}>刷新</button>} /><details className="commerce-help"><summary>资金与卡码规则</summary><p>发行时预留发行人的钱包资金；未兑换卡片撤销或到期后退回发行人，已兑换资金不能通过撤销收回；卡码仅由发行人保管</p></details>
    {pending ? <section className="list-card commerce-pending" role="status"><h2>操作等待确认</h2><p>确认上次撤销结果后，可继续管理礼品卡。</p><details className="commerce-help"><summary>查看操作标识</summary><code>{pending.key}</code></details><button className="button" disabled={denied || state.busy} onClick={()=>void recover()}>使用原操作确认结果</button></section> : null}
    {error || state.error ? <p role="alert">{error || managementError(state.error)}</p> : null}
    {denied ? <p role="alert">无法确认礼品卡管理权限，已隐藏数据</p> : null}
    <ReadList title="礼品卡" rows={denied || list.isError ? [] : list.data?.data.map(row => ({ ...row, id: row.card_id })) ?? []} columns={columns} meta={denied || list.isError ? undefined : list.data?.meta} page={page} ready={!!list.data && !denied} loading={list.isFetching} error={list.isError?list.error:undefined} onRetry={()=>void refresh()} onPage={setPage}><header className="commerce-card-heading"><div><h2>单卡记录</h2><p>核对发行、兑换与资金状态</p></div></header></ReadList>
    <ReadList title="礼品卡批次" rows={denied || batches.isError ? [] : batches.data?.data.map(row => ({ ...row, id: row.batch_id })) ?? []}
      columns={batchColumns} meta={denied || batches.isError ? undefined : batches.data?.meta} page={batchPage} ready={!!batches.data && !denied}
      loading={batches.isFetching} error={batches.isError ? batches.error : undefined} onRetry={() => void refresh()} onPage={setBatchPage}>
      <header className="commerce-card-heading"><div><h2>发行批次</h2><p>按批次核对发行资金与逐卡兑换情况</p></div></header>
    </ReadList>
    {selectedBatch && !denied ? <section className="list-card commerce-editor"><header className="commerce-card-heading"><div><h2>批次详情</h2><p>核对发行资金与兑换进度</p></div><button className="button" disabled={state.busy} onClick={() => setSelectedBatch('')}>关闭详情</button></header>
      <ManagementFeedback error={batch.isError ? batch.error : undefined} loading={batch.isFetching} onRetry={() => void batch.refetch()} />
      {detail ? <><h3 className="commerce-batch-title">{detail.batch.label}</h3><div className="commerce-metrics">{Object.entries(states).map(([key,label]) => <div key={key}><small>{label}</small><strong>{detail.cards.filter(card => card.state === key).length}<span>张</span></strong></div>)}</div>
        <ReadFields fields={[["批次号", detail.batch.batch_id], ["发行账户", detail.batch.issuer_id], ["数量", detail.batch.quantity],
          ["发行预留合计", detail.batch.total_minor + ' 分 ' + detail.batch.currency], ["到期时间", new Date(detail.batch.expires_at * 1000).toLocaleString()]]} />
        <p className="native-scroll-hint">左右滑动表格查看完整信息</p>
        <DataTable rows={detail.cards.map(row => ({ ...row, id: row.card_id }))} columns={columns} rowKey={row => row.card_id} label="批次内卡片" empty="本批次没有卡片" />
      </> : null}
    </section> : null}
  </div>
}
