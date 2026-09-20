import { useQuery } from '@tanstack/react-query'
import { ChevronLeft, ChevronRight, Search } from 'lucide-react'
import { useState } from 'react'
import { getAuditLogs } from '../../api/statistics'
import { Modal } from '../../components/ui/Modal'
import { PageHeader } from '../../components/ui/PageHeader'

export function AuditLogPage(){
  const [page,setPage]=useState(1),[keyword,setKeyword]=useState(''),[applied,setApplied]=useState(''),[action,setAction]=useState(''),[detail,setDetail]=useState<any>(null)
  const q=useQuery({queryKey:['audit',page,applied,action],queryFn:()=>getAuditLogs({current:page,page_size:20,...(applied?{keyword:applied}:{}),...(action?{action}:{})})})
  const rows=q.data?.data||[]
  return <>
    <PageHeader title="审计日志" description="管理员操作、请求路径、IP 与请求数据记录。"/>
    <div className="content-toolbar"><div className="order-search"><input value={keyword} onChange={e=>setKeyword(e.target.value)} onKeyDown={e=>e.key==='Enter'&&(setApplied(keyword),setPage(1))} placeholder="搜索 URI / 请求数据…"/><button className="button" onClick={()=>{setApplied(keyword);setPage(1)}}><Search size={15}/>搜索</button></div><input value={action} onChange={e=>{setAction(e.target.value);setPage(1)}} placeholder="Action"/></div>
    <div className="card content-table-card"><div className="table-wrap"><table className="data-table"><thead><tr><th>ID</th><th>管理员</th><th>Action</th><th>Method</th><th>URI</th><th>IP</th><th>时间</th></tr></thead><tbody>
      {rows.map(r=><tr key={r.id} onClick={()=>setDetail(r)} className="clickable-row"><td>{r.id}</td><td>{r.admin?.email||r.admin_id||'-'}</td><td><span className="badge">{r.action||'-'}</span></td><td>{r.method||'-'}</td><td><code>{r.uri||'-'}</code></td><td>{r.ip||'-'}</td><td>{fmt(r.created_at)}</td></tr>)}
      {!rows.length&&<tr><td colSpan={7} className="empty-cell">{q.isLoading?'加载中…':'暂无记录'}</td></tr>}
    </tbody></table></div><div className="pagination-bar"><span className="pagination-meta">共 {q.data?.total||0} 条 · 第 {q.data?.current_page||page} / {q.data?.last_page||1} 页</span><div className="pagination-actions"><button className="icon-button" disabled={page<=1} onClick={()=>setPage(v=>Math.max(1,v-1))}><ChevronLeft size={16}/></button><button className="icon-button" disabled={page>=Number(q.data?.last_page||1)} onClick={()=>setPage(v=>v+1)}><ChevronRight size={16}/></button></div></div></div>
    <Modal open={!!detail} title="审计详情" onClose={()=>setDetail(null)}>{detail&&<div className="form-stack"><dl className="meta-list"><div><dt>管理员</dt><dd>{detail.admin?.email||detail.admin_id||'-'}</dd></div><div><dt>Action</dt><dd>{detail.action||'-'}</dd></div><div><dt>请求</dt><dd>{detail.method||'-'} {detail.uri||'-'}</dd></div><div><dt>IP</dt><dd>{detail.ip||'-'}</dd></div><div><dt>时间</dt><dd>{fmt(detail.created_at)}</dd></div></dl><pre className="code-block">{pretty(detail.request_data)}</pre></div>}</Modal>
  </>
}
function fmt(v?:number){return v?new Date(v*1000).toLocaleString():'-'}
function pretty(v?:string){if(!v)return '无请求数据';try{return JSON.stringify(JSON.parse(v),null,2)}catch{return v}}
