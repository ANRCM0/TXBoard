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

  return (
    <Modal
      open={open}
      title="批量发送邮件"
      subtitle="选择收件范围并填写邮件内容；发送动作仍由现有后台邮件任务执行。"
      className="content-editor-modal"
      onClose={onClose}
      footer={
        <div className="content-editor-footer">
          <button className="button" onClick={onClose} disabled={mutation.isPending}>取消</button>
          <button
            className="button primary"
            disabled={mutation.isPending || !subject.trim() || !content.trim()}
            onClick={() => mutation.mutate()}
          >
            <Send size={15}/>{mutation.isPending ? '提交中…' : '发送邮件'}
          </button>
        </div>
      }
    >
      <div className="content-editor-form">
        <section className="admin-form-section">
          <div className="admin-form-section-head">
            <div><strong>收件范围</strong><small>先确认影响范围，再提交批量邮件任务。</small></div>
          </div>
          <div className="admin-form-section-body">
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
                <span><strong>全部用户</strong><small>影响全部账户，请谨慎使用</small></span>
              </label>
            </div>
          </div>
        </section>

        <section className="admin-form-section">
          <div className="admin-form-section-head">
            <div><strong>邮件内容</strong><small>支持现有后端 app / user 等模板变量。</small></div>
          </div>
          <div className="admin-form-section-body">
            <div className="form-stack">
              <label className="field">
                <span>主题 *</span>
                <input value={subject} onChange={event => setSubject(event.target.value)} placeholder="系统通知"/>
              </label>
              <label className="field">
                <span>正文 *</span>
                <textarea
                  className="user-mail-editor"
                  value={content}
                  onChange={event => setContent(event.target.value)}
                  placeholder={"尊敬的用户，您好！\n\n这里填写通知内容。\n\n{{app.name}}"}
                />
                <small className="field-help">建议先向测试账户验证模板变量与排版。</small>
              </label>
            </div>
          </div>
        </section>
      </div>
    </Modal>
  )
}
