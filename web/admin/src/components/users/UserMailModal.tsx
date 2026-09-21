import { useMutation } from '@tanstack/react-query'
import { isAxiosError } from 'axios'
import { useEffect, useState } from 'react'
import { Send } from 'lucide-react'
import { toast } from 'sonner'
import { sendUsersMail, type UserFilter } from '../../api/user-admin'
import { Modal } from '../ui/Modal'

export function UserMailModal({
  open,
  selectedIds,
  filters,
  onClose,
}: {
  open: boolean
  selectedIds: number[]
  filters: UserFilter[]
  onClose: () => void
}) {
  const [scope, setScope] = useState<'selected' | 'filtered' | 'all'>('selected')
  const [subject, setSubject] = useState('')
  const [content, setContent] = useState('')

  useEffect(() => {
    if (!open) return
    if (selectedIds.length) setScope('selected')
    else if (filters.length) setScope('filtered')
    else setScope('all')
  }, [open, selectedIds.length, filters.length])

  const mutation = useMutation({
    mutationFn: () => {
      const common = { subject: subject.trim(), content: content.trim() }
      if (scope === 'selected') {
        if (!selectedIds.length) throw new Error('请先选择用户')
        return sendUsersMail({ scope, user_ids: selectedIds, ...common })
      }
      if (scope === 'filtered') {
        if (!filters.length) throw new Error('当前没有有效筛选条件')
        return sendUsersMail({ scope, filter: filters, ...common })
      }
      return sendUsersMail({ scope: 'all', ...common })
    },
    onSuccess: () => {
      toast.success('邮件任务已提交')
      setSubject('')
      setContent('')
      onClose()
    },
    onError: error => {
      if (!isAxiosError(error)) toast.error(error instanceof Error ? error.message : '发送失败')
    },
  })

  return <Modal open={open} title="批量发送邮件" onClose={onClose}>
    <div className="form-stack">
      <div className="mail-scope">
        <label className={scope === 'selected' ? 'scope-option active' : 'scope-option'}>
          <input type="radio" name="mail-scope" checked={scope === 'selected'} onChange={() => setScope('selected')}/>
          <span><strong>已选择用户</strong><small>{selectedIds.length} 人</small></span>
        </label>
        <label className={scope === 'filtered' ? 'scope-option active' : 'scope-option'}>
          <input type="radio" name="mail-scope" checked={scope === 'filtered'} onChange={() => setScope('filtered')}/>
          <span><strong>当前筛选结果</strong><small>{filters.length ? '按当前条件' : '暂无筛选条件'}</small></span>
        </label>
        <label className={scope === 'all' ? 'scope-option active danger-zone' : 'scope-option danger-zone'}>
          <input type="radio" name="mail-scope" checked={scope === 'all'} onChange={() => setScope('all')}/>
          <span><strong>全部用户</strong><small>谨慎使用</small></span>
        </label>
      </div>

      <label className="field">
        <span>主题 *</span>
        <input value={subject} onChange={e => setSubject(e.target.value)} placeholder="系统通知"/>
      </label>

      <label className="field">
        <span>正文 *</span>
        <textarea
          className="user-mail-editor"
          value={content}
          onChange={e => setContent(e.target.value)}
          placeholder={"尊敬的用户，您好！\n\n这里填写通知内容。\n\n{{app.name}}"}
        />
        <small className="field-help">后端支持 app / user 等模板变量。</small>
      </label>

      <div className="card-actions">
        <button className="button" onClick={onClose}>取消</button>
        <button
          className="button primary"
          disabled={mutation.isPending || !subject.trim() || !content.trim()}
          onClick={() => mutation.mutate()}
        >
          <Send size={15}/>{mutation.isPending ? '提交中…' : '发送邮件'}
        </button>
      </div>
    </div>
  </Modal>
}
