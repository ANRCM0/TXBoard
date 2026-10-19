import { Paperclip, Download, X } from 'lucide-react'
import './support-reference.css'
import { useSyncExternalStore } from 'react'
import type { AttachmentMetadata } from '@txboard/shared'
import { attachmentTransfers } from './session'

const statusNames = { uploading: '上传未完成', ready: '待随回复发送', attached: '已发送', cancelled: '已取消', expired: '已过期' }
export function AttachmentsPanel({ ticketId, attachments = [], disabled = false, upload = true }: {
  ticketId: string; attachments?: readonly AttachmentMetadata[]; disabled?: boolean; upload?: boolean
}) {
  const state = useSyncExternalStore(attachmentTransfers.subscribe, attachmentTransfers.snapshot)
  const locked = disabled || state.busy || state.pending
  const entries = state.entries.filter(entry => entry.ticketId === ticketId)
  return <section className="support-attachments" aria-label="工单附件">
    {upload ? <><h3>回复附件</h3><p>最多 4 个文件；支持 PNG、JPEG、PDF、纯文本，单个最多 5 MiB。上传完成后随回复发送。</p>
      <label className="support-upload"><Paperclip size={16} />选择附件<input type="file" accept="image/png,image/jpeg,application/pdf,text/plain" disabled={locked || state.denied}
        onChange={event => { const file = event.target.files?.[0]; event.target.value = ''; if (file) void attachmentTransfers.upload(ticketId, file) }} /></label></> : null}
    {upload ? <p className="support-attachment-note">附件可能包含个人信息，请仅上传处理此工单所需的文件。</p> : null}{state.error ? <p role="alert">{state.error}</p> : null}
    {state.busy ? <p role="status">正在处理附件…</p> : null}
    {state.pending ? <div className="support-attachment-pending" role="status"><p>存在等待确认的附件操作，确认前不能继续上传或回复。</p>
      <button className="button" type="button" disabled={disabled || state.busy || state.denied} onClick={() => void attachmentTransfers.recover()}>使用原操作确认附件结果</button></div> : null}
    {state.denied ? <button className="button" type="button" disabled={disabled || state.busy} onClick={() => void attachmentTransfers.refresh(ticketId)}>重新读取附件权限</button> : <>
      {upload ? <ul aria-label="待发送附件">{entries.map(entry => <li className="support-transfer-row" key={entry.localId}>
        <span className="support-file-info"><strong>{entry.filename}</strong><small>{entry.metadata ? statusNames[entry.metadata.status] : state.busy || state.pending ? '正在准备或等待确认' : '未建立上传，可取消后重新选择'}{entry.metadata?.status === 'uploading' ? ' · ' + entry.metadata.received_bytes + ' / ' + entry.metadata.size_bytes + ' 字节' : ''}</small></span>
        {entry.metadata?.status === 'uploading' ? <progress aria-label={entry.filename + '上传进度'} max={entry.metadata.size_bytes} value={entry.metadata.received_bytes} /> : null}{entry.metadata?.status === 'uploading' ? <button className="button" type="button" disabled={locked} onClick={() => void attachmentTransfers.resume(entry.localId)}>重新读取并继续上传</button> : null}
        {!entry.metadata || ['uploading', 'ready'].includes(entry.metadata.status) ? <button className="button" type="button" disabled={locked} onClick={() => void attachmentTransfers.cancel(entry.localId)}><X size={14} />取消附件</button> : null}
      </li>)}</ul> : null}
      {attachments.length ? <ul aria-label="已发送附件">{attachments.map(metadata => <li key={metadata.id}>
        <span className="support-file-info"><strong>{metadata.filename}</strong><small>{metadata.size_bytes} 字节 · 已发送</small></span>{' '}
        <button className="button" type="button" disabled={locked} onClick={() => void attachmentTransfers.download(metadata)}><Download size={14} />校验并下载</button>
      </li>)}</ul> : null}
    </>}
  </section>
}
