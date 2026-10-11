import { useEffect, useRef, useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Copy, RotateCcw } from 'lucide-react'
import { toast } from 'sonner'
import { getMachineCredentials, resetMachineToken } from '../../api/server'
import { Modal } from '../../components/ui/Modal'
import { QueryFeedback } from '../../components/ui/QueryFeedback'
import { requestConfirm } from '../../components/ui/ConfirmDialog'

type Credentials = {token:string;command:string}

/** Key this component by machine ID to isolate all ephemeral credential state. */
export function MachineCredentialsDialog({machineId,machineName,onClose}: {
  machineId:number;machineName:string;onClose:()=>void
}) {
  const qc=useQueryClient()
  const [credentials,setCredentials]=useState<Credentials|null>(null)
  const [loading,setLoading]=useState(true)
  const [error,setError]=useState(false)
  const activeRef=useRef(true)
  const generation=useRef(0)

  useEffect(()=>{
    activeRef.current=true
    return ()=>{
      activeRef.current=false
      generation.current+=1
    }
  },[])

  const load=async()=>{
    const request=++generation.current
    setLoading(true);setError(false);setCredentials(null)
    try {
      const result=await getMachineCredentials(machineId)
      if (activeRef.current && request===generation.current)
        setCredentials({token:result.token,command:result.install_command})
    } catch {
      if (activeRef.current && request===generation.current) {
        setError(true)
        toast.error('无法读取机器凭据')
      }
    } finally {
      if (activeRef.current && request===generation.current) setLoading(false)
    }
  }
  useEffect(()=>{void load()},[machineId])

  // Clear sensitive values when closing or timing out; the entire dialog then unmounts.
  useEffect(()=>{
    if (!credentials) return
    const timer=window.setTimeout(()=>close(),18_000)
    return ()=>window.clearTimeout(timer)
  },[credentials])
  const close=()=>{generation.current+=1;setCredentials(null);onClose()}

  const reset=useMutation({
    mutationFn:()=>resetMachineToken(machineId),
    onSuccess:async result=>{
      if (activeRef.current) {
        setCredentials({token:result.token,command:result.install_command})
        toast.success('Machine Token 已重置，请保存新的凭据')
      }
      await qc.invalidateQueries({queryKey:['machines']})
    },
  })

  const copy=async(value:string,label:string)=>{
    if (!value) return
    try {await navigator.clipboard.writeText(value);toast.success(label+'已复制')}
    catch {toast.error('复制失败')}
  }

  return <Modal open title="机器 Token / 安装命令" onClose={close}>
    <div className="form-stack machine-detail-v3-credentials">
      <div className="page-alert">Machine Token 属于高敏感凭据。显示后 18 秒自动关闭；重置后旧 Token 立即失效。</div>
      <QueryFeedback loading={loading} error={error} onRetry={()=>void load()}/>
      {credentials ? <>
        <div className="field"><span>Machine Token</span><pre className="code-block" data-testid="machine-secret-token">{credentials.token}</pre></div>
        <div className="field"><span>一键安装命令</span><pre className="code-block">{credentials.command}</pre></div>
        <div className="card-actions">
          <button type="button" className="button" onClick={()=>void copy(credentials.token,'Token')}><Copy size={15}/>复制 Token</button>
          <button type="button" className="button primary" onClick={()=>void copy(credentials.command,'安装命令')}><Copy size={15}/>复制安装命令</button>
          <button type="button" className="button danger" disabled={reset.isPending}
            onClick={()=>requestConfirm({
              title:'重置 Machine Token',
              message:`确认重置 ${machineName} 的 Token？旧 Token 会立即失效，节点端需要重新配置。`,
              danger:true,confirmLabel:'重置 Token',
              action:()=>{setCredentials(null);reset.mutate()},
            })}><RotateCcw size={15}/>{reset.isPending?'重置中…':'重置 Token'}</button>
        </div>
      </>:null}
    </div>
  </Modal>
}
