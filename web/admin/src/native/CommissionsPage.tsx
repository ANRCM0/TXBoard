import './commerce-reference.css'
import { useState, useSyncExternalStore } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { managementError } from './Feedback'
import { ReadList } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import type { Column } from '../components/ui/DataTable'
type Reward = CoreOutput<'billing.admin-commissions.rewards.list'>[number]
type Payout = CoreOutput<'billing.admin-commissions.payouts.list'>[number]
const rewardStates = {pending:'等待计提', accrued:'已计提', ineligible:'无可计提金额'}
const payoutStates = {requested:'待审核', approved:'已转入钱包', rejected:'已驳回'}
export function CommissionsPage() {
  const client=useQueryClient(), state=useSyncExternalStore(workflowActions.subscribe,workflowActions.snapshot)
  const [rewardPage,setRewardPage]=useState(1),[payoutPage,setPayoutPage]=useState(1),[error,setError]=useState('')
  const [draft,setDraft]=useState<{enabled:boolean;rate:number;hold:number;version:string}>(), [reason,setReason]=useState('reviewed')
  const policy=useQuery({queryKey:['native-admin','commissions','policy'],queryFn:({signal})=>admin.read('billing.admin-commissions.policy.get',{},signal)})
  const rewards=useQuery({queryKey:['native-admin','commissions','rewards',rewardPage],queryFn:({signal})=>admin.read('billing.admin-commissions.rewards.list',{page:rewardPage,per_page:20},signal)})
  const payouts=useQuery({queryKey:['native-admin','commissions','payouts',payoutPage],queryFn:({signal})=>admin.read('billing.admin-commissions.payouts.list',{page:payoutPage,per_page:20},signal)})
  const denied=[policy.error,rewards.error,payouts.error,state.error].some(failure=>failure instanceof CoreAdminApiError && [401,403].includes(failure.status))
  const pending = state.pending?.operation.startsWith('billing.admin-commissions.') ? state.pending : undefined
  const locked=denied || state.busy || !!state.pending
  async function refresh(){const generation=workflowActions.generation();await client.invalidateQueries({queryKey:['native-admin','commissions']});if(generation===workflowActions.generation()&&client.getQueryCache().findAll({queryKey:['native-admin','commissions']}).filter(query => query.isActive()).every(query=>query.state.status==='success')){workflowActions.confirmReadAuthorization('billing.admin-commissions.');workflowActions.clearError();setError('')}}
  async function perform(run:()=>Promise<void>){const generation=workflowActions.generation();setError('');try{await run()}catch(failure){if(generation!==workflowActions.generation())return;setError(managementError(failure));if(failure instanceof CoreAdminApiError && failure.status===409 && !failure.needsStateConfirmation)await refresh()}}
  async function save(){if(locked||!draft)return;await perform(async()=>{await workflowActions.run('billing.admin-commissions.policy.update',{enabled:draft.enabled,rate_bps:draft.rate,hold_seconds:draft.hold,expected_version:draft.version,refund_policy:'net-before-vesting-retained-after/v1'});setDraft(undefined);await refresh()})}
  async function decide(row:Payout,approve:boolean){if(locked || !/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/.test(reason)){setError('请填写有效的审核原因标识');return}await perform(async()=>{await workflowActions.run(approve?'billing.admin-commissions.payouts.approve':'billing.admin-commissions.payouts.reject',{payout_id:row.id,expected_version:row.row_version,reason});await refresh()})}
  async function recover(){const action=pending;if(!action||denied||state.busy)return;await perform(async()=>{await workflowActions.run(action.operation,action.input);setDraft(undefined);await refresh()})}
  const rewardColumns:Column<Reward>[]=[
    {key:'order',header:'来源订单',render:row=>row.order_id}, {key:'buyer',header:'购买账户',render:row=>row.buyer_id}, {key:'sponsor',header:'邀请账户',render:row=>row.sponsor_id},
    {key:'amount',header:'佣金',render:row=>row.amount_minor+' 分 '+row.currency}, {key:'rate',header:'冻结比例 / 等待期',render:row=>row.rate_bps+' / 10000 · '+row.hold_seconds+' 秒'},
    {key:'state',header:'状态',render:row=><span className={'commerce-badge '+(row.state==='accrued'?'success':row.state==='pending'?'warning':'')}>{rewardStates[row.state]}</span>},
  ]
  const payoutColumns:Column<Payout>[]=[
    {key:'id',header:'申请',render:row=>row.id}, {key:'user',header:'账户',render:row=>row.user_id}, {key:'amount',header:'金额',render:row=>row.amount_minor+' 分 '+row.currency},
    {key:'state',header:'状态',render:row=><span className={'commerce-badge '+(row.state==='approved'?'success':row.state==='requested'?'warning':'')}>{payoutStates[row.state]}</span>}, {key:'audit',header:'审核记录',render:row=>(row.actor_id ?? '未审核')+' · '+(row.reason ?? '')},
    {key:'actions',header:'操作',render:row=>row.state==='requested'?<><button className="button" disabled={locked} onClick={()=>void decide(row,true)}>批准转入钱包</button><button className="button" disabled={locked} onClick={()=>void decide(row,false)}>驳回并释放</button></>:null},
  ]
  return <div className="native-management-page commerce-reference"><PageHeader title="邀请与佣金" description="管理单级邀请奖励、冻结策略和钱包转入审核" action={<button className="button" disabled={policy.isFetching || rewards.isFetching || payouts.isFetching} onClick={()=>void refresh()}>刷新</button>} />
    <details className="commerce-help"><summary>佣金计提与退款规则</summary><p>已核验付款并完成履约的订单才会计提佣金；计提前扣除已确认退款，计提后不再因退款扣回；申请仅转入用户钱包</p></details>
    {pending?<section role="status"><p>操作等待确认：{pending.key}</p><button className="button" disabled={denied || state.busy} onClick={()=>void recover()}>使用原操作确认结果</button></section>:null}
    {error||state.error?<p role="alert">{error||managementError(state.error)}</p>:null}{denied?<p role="alert">无法确认佣金管理权限，已隐藏数据</p>:null}
    {!denied && policy.data && !policy.isError?<section className="list-card commerce-editor"><header className="commerce-card-heading"><div><h2>佣金策略</h2><p>调整后应用于后续订单</p></div><span className={'commerce-badge '+(policy.data.data.enabled?'success':'')}>{policy.data.data.enabled?'已启用':'已停用'}</span></header><div className="commerce-metrics"><div><small>佣金比例</small><strong>{(policy.data.data.rate_bps / 100).toFixed(2)}<span>%</span></strong></div><div><small>计提等待</small><strong>{policy.data.data.hold_seconds}<span>秒</span></strong></div></div><details className="commerce-help"><summary>查看当前策略版本</summary><code>{policy.data.data.row_version}</code></details><button className="button" disabled={locked} onClick={()=>setDraft({enabled:policy.data!.data.enabled,rate:policy.data!.data.rate_bps,hold:policy.data!.data.hold_seconds,version:policy.data!.data.row_version})}>编辑下一版本</button>
      {draft?<form className="native-content-form commerce-form-grid" onSubmit={event=>{event.preventDefault();void save()}}><label className="commerce-toggle"><input type="checkbox" checked={draft.enabled} disabled={locked} onChange={event=>setDraft({...draft,enabled:event.target.checked})}/>启用后续订单佣金</label><label>比例（1000 表示 10%）<input type="number" min={1} max={10000} step={1} required value={draft.rate} disabled={locked} onChange={event=>setDraft({...draft,rate:Number(event.target.value)})}/></label><label>计提等待秒数（0 至 90 天）<input type="number" min={0} max={7776000} step={1} required value={draft.hold} disabled={locked} onChange={event=>setDraft({...draft,hold:Number(event.target.value)})}/></label><footer className="commerce-form-actions"><span>既有订单仍保留原策略</span><button className="button primary" disabled={locked}>保存新版本</button><button className="button" type="button" onClick={()=>setDraft(undefined)}>关闭</button></footer></form>:null}</section>:null}
    {policy.isError?<p role="alert">{managementError(policy.error)}</p>:null}
    {!denied ? <section className="list-card commerce-review-toolbar"><div><h2>转入申请审核</h2><p>填写审核标记后，在申请行选择批准或驳回</p></div><label>审核原因标识<input value={reason} required maxLength={64} pattern="[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}" disabled={locked} onChange={event=>setReason(event.target.value)}/><small>使用英文、数字或短横线等符号，最多 64 字符</small></label></section> : null}
    <ReadList title="钱包转入申请" rows={denied||payouts.isError?[]:payouts.data?.data??[]} columns={payoutColumns} meta={denied || payouts.isError ? undefined : payouts.data?.meta} page={payoutPage} ready={!!payouts.data&&!denied} loading={payouts.isFetching} error={payouts.isError?payouts.error:undefined} onRetry={()=>void refresh()} onPage={setPayoutPage}/>
    <ReadList title="逐单佣金" rows={denied||rewards.isError?[]:rewards.data?.data??[]} columns={rewardColumns} meta={denied || rewards.isError ? undefined : rewards.data?.meta} page={rewardPage} ready={!!rewards.data&&!denied} loading={rewards.isFetching} error={rewards.isError?rewards.error:undefined} onRetry={()=>void refresh()} onPage={setRewardPage}/>
  </div>
}
