import { t, useLocale, getLocale } from '../lib/i18n'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { useListPagination } from './useListPagination'
import './commerce-reference.css'
import { useEffect, useState, useSyncExternalStore } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { CoreAdminApiError, type CoreOutput } from '@txboard/core-sdk'
import { admin, workflowActions } from './session'
import { managementError } from './Feedback'
import { ReadList } from './ReadList'
import { PageHeader } from '../components/ui/PageHeader'
import { Popover } from '../components/ui/Popover'
import type { Column } from '../components/ui/DataTable'
import { commerceMoney, commerceDuration } from './commerce-format'
type Reward = CoreOutput<'billing.admin-commissions.rewards.list'>[number]
type Payout = CoreOutput<'billing.admin-commissions.payouts.list'>[number]
const rewardStates = {pending:'等待计提', accrued:'已计提', ineligible:'无可计提金额'}
const payoutStates = {requested:'待审核', approved:'已转入钱包', rejected:'已驳回'}
export function CommissionsPage() {
  useLocale()
  const client=useQueryClient(), state=useSyncExternalStore(workflowActions.subscribe,workflowActions.snapshot)
  const { page: rewardPage, setPage: setRewardPage, perPage: rewardSize, setPerPage: setRewardSize } = useListPagination(50)
  const { page: payoutPage, setPage: setPayoutPage, perPage: payoutSize, setPerPage: setPayoutSize } = useListPagination(50)
  const [error,setError]=useState('')
  const [draft,setDraft]=useState<{enabled:boolean;rate:number;hold:number;version:string}>(), [reason,setReason]=useState('reviewed')
  const policy=useQuery({queryKey:['native-admin','commissions','policy'],queryFn:({signal})=>admin.read('billing.admin-commissions.policy.get',{},signal)})
  const rewards=useQuery({queryKey:['native-admin','commissions','rewards', rewardPage, rewardSize],queryFn:({signal})=>admin.read('billing.admin-commissions.rewards.list',{page: rewardPage, per_page: rewardSize},signal)})
  const payouts=useQuery({queryKey:['native-admin','commissions','payouts', payoutPage, payoutSize],queryFn:({signal})=>admin.read('billing.admin-commissions.payouts.list',{page: payoutPage, per_page: payoutSize},signal)})
  const denied=[policy.error,rewards.error,payouts.error, !state.errorOperation || state.errorOperation.startsWith('billing.admin-commissions.') ? state.error : undefined].some(failure=>failure instanceof CoreAdminApiError && [401,403].includes(failure.status))
  const pending = state.pending?.operation.startsWith('billing.admin-commissions.') ? state.pending : undefined
  const locked=denied || state.busy || !!state.pending
  useDirtyDraft(!!draft && !denied && !state.pending && !!policy.data && (draft.enabled !== policy.data.data.enabled || draft.rate !== policy.data.data.rate_bps || draft.hold !== policy.data.data.hold_seconds))
  const generation = workflowActions.generation()
  useEffect(() => { setDraft(undefined); setReason('reviewed'); setError('') }, [generation])
  useEffect(() => { if (denied) setDraft(undefined) }, [denied])
  async function refresh(){const generation=workflowActions.generation();await client.invalidateQueries({queryKey:['native-admin','commissions']});if(generation===workflowActions.generation()&&client.getQueryCache().findAll({queryKey:['native-admin','commissions']}).filter(query => query.isActive()).every(query=>query.state.status==='success')){workflowActions.confirmReadAuthorization('billing.admin-commissions.');workflowActions.clearError();setError('')}}
  async function perform(run:()=>Promise<void>){const generation=workflowActions.generation();setError('');try{await run()}catch(failure){if(generation!==workflowActions.generation())return;setError(managementError(failure, false));if(failure instanceof CoreAdminApiError && failure.status===409 && !failure.needsStateConfirmation)await refresh()}}
  async function save(){if(locked||!draft)return;await perform(async()=>{await workflowActions.run('billing.admin-commissions.policy.update',{enabled:draft.enabled,rate_bps:draft.rate,hold_seconds:draft.hold,expected_version:draft.version,refund_policy:'net-before-vesting-retained-after/v1'});setDraft(undefined);await refresh()})}
  async function decide(row:Payout,approve:boolean){if(locked || !/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/.test(reason)){setError('请填写有效的审核原因标识');return}await perform(async()=>{await workflowActions.run(approve?'billing.admin-commissions.payouts.approve':'billing.admin-commissions.payouts.reject',{payout_id:row.id,expected_version:row.row_version,reason});await refresh()})}
  async function recover(){const action=pending;if(!action||denied||state.busy)return;await perform(async()=>{await workflowActions.run(action.operation,action.input);setDraft(undefined);await refresh()})}
  const rewardColumns:Column<Reward>[]=[
    {key:'order',header:t("来源订单"),render:row=>row.order_id}, {key:'buyer',header:t("购买账户"),render:row=>row.buyer_id}, {key:'sponsor',header:t("邀请账户"),render:row=>row.sponsor_id},
    {key:'amount',header:t("佣金"),render:row=>commerceMoney(row.amount_minor, row.currency)}, {key:'rate',header:t("冻结比例 / 等待期"),render:row=><div className="commerce-cell-stack"><strong>{(row.rate_bps / 100).toLocaleString(getLocale(), { maximumFractionDigits: 2 })}%</strong><small>{commerceDuration(row.hold_seconds)}</small></div>},
    {key:'state',header:t("状态"),render:row=><span className={'commerce-badge '+(row.state==='accrued'?'success':row.state==='pending'?'warning':'')}>{t(rewardStates[row.state])}</span>},
  ]
  const payoutColumns:Column<Payout>[]=[
    {key:'id',header:t("申请"),render:row=>row.id}, {key:'user',header:t("账户"),render:row=>row.user_id}, {key:'amount',header:t("金额"),render:row=>commerceMoney(row.amount_minor, row.currency)},
    {key:'state',header:t("状态"),render:row=><span className={'commerce-badge '+(row.state==='approved'?'success':row.state==='requested'?'warning':'')}>{t(payoutStates[row.state])}</span>}, {key:'dates',header:t("申请 / 结束时间"),render:row=><div className="commerce-cell-stack"><span>{new Date(row.created_at*1000).toLocaleString(getLocale())}</span><small>{row.closed_at===null?t("尚未结束"):new Date(row.closed_at*1000).toLocaleString(getLocale())}</small></div>}, {key:'audit',header:t("审核记录"),render:row=><div className="commerce-cell-stack"><span>{row.actor_id ? t("管理员 #") + row.actor_id : t("未审核")}</span>{row.reason ? <small>{row.reason}</small> : null}</div>},
    {key:'actions',header:t("操作"),pin:'right',hideable:false,render:row=>row.state==='requested'?<div className="commerce-row-actions"><button className="button primary" disabled={locked} onClick={()=>void decide(row,true)}>{t("批准转入钱包")}</button><button className="button" disabled={locked} onClick={()=>void decide(row,false)}>{t("驳回并释放")}</button></div>:<span className="commerce-table-note">{t("已处理")}</span>},
  ]
  return <div className="native-management-page commerce-reference commissions-page"><PageHeader title={t("邀请与佣金")} description={t("管理单级邀请奖励、冻结策略和钱包转入审核")} action={<><Popover label={t("佣金计提与退款规则")}><p>{t("已核验付款并完成履约的订单才会计提佣金 · 计提前扣除已确认退款，计提后不再因退款扣回 · 申请仅转入用户钱包")}</p></Popover><button className="button" disabled={policy.isFetching || rewards.isFetching || payouts.isFetching} onClick={()=>void refresh()}>{t("刷新")}</button></>} />
    {pending?<section className="list-card commerce-pending" role="status"><h2>{t("操作等待确认")}</h2><p>{t("请先确认上次策略或审核操作的结果。")}</p><details className="commerce-help"><summary>{t("查看操作标识")}</summary><code>{pending.key}</code></details><button className="button" disabled={denied || state.busy} onClick={()=>void recover()}>{t("使用原操作确认结果")}</button></section>:null}
    {error||state.error?<p role="alert">{error ? t(error) : managementError(state.error)}</p>:null}{denied?<p role="alert">{t("无法确认佣金管理权限，已隐藏数据")}</p>:null}
    {!denied && policy.isFetching && !policy.data ? <p className="commerce-loading" role="status">{t("正在读取佣金策略…")}</p> : null}
    {!denied && policy.data && !policy.isError?<section className="list-card commerce-editor"><header className="commerce-card-heading"><div><h2>{t("佣金策略")}</h2><p>{t("调整后应用于后续订单")}</p></div><span className={'commerce-badge '+(policy.data.data.enabled?'success':'')}>{policy.data.data.enabled?t("已启用"):t("已停用")}</span></header><div className="commerce-metrics"><div><small>{t("佣金比例")}</small><strong>{(policy.data.data.rate_bps / 100).toFixed(2)}<span>%</span></strong></div><div><small>{t("计提等待")}</small><strong className="commerce-metric-duration">{commerceDuration(policy.data.data.hold_seconds)}</strong></div></div><details className="commerce-help"><summary>{t("查看当前策略版本")}</summary><code>{policy.data.data.row_version}</code></details><button className="button" disabled={locked} onClick={()=>setDraft({enabled:policy.data!.data.enabled,rate:policy.data!.data.rate_bps,hold:policy.data!.data.hold_seconds,version:policy.data!.data.row_version})}>{t("编辑下一版本")}</button>
      {draft?<form className="native-content-form commerce-form-grid" onSubmit={event=>{event.preventDefault();void save()}}><label className="commerce-toggle"><input type="checkbox" checked={draft.enabled} disabled={locked} onChange={event=>setDraft({...draft,enabled:event.target.checked})}/>{t("启用后续订单佣金")}</label><label>{t("比例（1000 表示 10%）")}<input type="number" min={1} max={10000} step={1} required value={draft.rate} disabled={locked} onChange={event=>setDraft({...draft,rate:Number(event.target.value)})}/><small>{t("当前设置：")}{(draft.rate / 100).toLocaleString(getLocale(), { maximumFractionDigits: 2 })}%</small></label><label>{t("计提等待秒数（0 至 90 天）")}<input type="number" min={0} max={7776000} step={1} required value={draft.hold} disabled={locked} onChange={event=>setDraft({...draft,hold:Number(event.target.value)})}/><small>{commerceDuration(draft.hold)}</small></label><footer className="commerce-form-actions"><span>{t("既有订单仍保留原策略")}</span><button className="button primary" disabled={locked}>{t("保存新版本")}</button><button className="button" type="button" onClick={()=>setDraft(undefined)}>{t("关闭")}</button></footer></form>:null}</section>:null}
    {policy.isError?<p role="alert">{managementError(policy.error)}</p>:null}
    {!denied ? <section className="list-card commerce-review-toolbar"><div><h2>{t("转入申请审核")}</h2><p>{t("填写审核标记后，在申请行选择批准或驳回")}</p></div><label>{t("审核原因标识")}<input value={reason} required maxLength={64} pattern="[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}" disabled={locked} onChange={event=>setReason(event.target.value)}/><small>{t("使用英文、数字或短横线等符号，最多 64 字符")}</small></label></section> : null}
    <ReadList mobilePrimaryKey="id" maxPageSize={50} title={"钱包转入申请"} rows={denied||payouts.isError?[]:payouts.data?.data??[]} columns={payoutColumns} meta={denied || payouts.isError ? undefined : payouts.data?.meta} perPage={payoutSize} onPageSize={setPayoutSize} page={payoutPage} ready={!!payouts.data&&!denied} loading={payouts.isFetching} error={payouts.isError?payouts.error:undefined} onRetry={()=>void refresh()} onPage={setPayoutPage}><header className="commerce-card-heading"><div><h2>{t("钱包转入申请")}</h2><p>{t("批准后转入用户钱包，驳回后释放申请金额")}</p></div></header></ReadList>
    <ReadList mobilePrimaryKey="order" maxPageSize={50} title={"逐单佣金"} rows={denied||rewards.isError?[]:rewards.data?.data??[]} columns={rewardColumns} meta={denied || rewards.isError ? undefined : rewards.data?.meta} perPage={rewardSize} onPageSize={setRewardSize} page={rewardPage} ready={!!rewards.data&&!denied} loading={rewards.isFetching} error={rewards.isError?rewards.error:undefined} onRetry={()=>void refresh()} onPage={setRewardPage}><header className="commerce-card-heading"><div><h2>{t("逐单佣金")}</h2><p>{t("每笔订单使用其冻结的比例与等待期")}</p></div></header></ReadList>
  </div>
}
