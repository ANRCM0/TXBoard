import { t, useLocale } from '../lib/i18n'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { useEffect, useId, useState, type FormEvent } from 'react'
import type { CoreInput } from '@txboard/core-sdk'
import { Modal } from '../components/ui/Modal'
import { Field, Input, Select, Switch, Textarea } from '../components/ui/Field'

export function MachineCreateDialog({ open, locked, onClose, onCreate }: {
  open: boolean
  locked: boolean
  onClose: () => void
  onCreate: (input: CoreInput<'network.machines.create'>) => Promise<void>
}) {
  useLocale()
  const formId = useId()
  const [name, setName] = useState(''), [notes, setNotes] = useState('')
  const [active, setActive] = useState(true), [channel, setChannel] = useState<'stable' | 'dev'>('stable')
  useEffect(() => { if (open) { setName(''); setNotes(''); setActive(true); setChannel('stable') } }, [open])
  const dirty = !!name || !!notes || !active || channel !== 'stable'
  useDirtyDraft(open && dirty)
  function close() {
    if (!locked && (!dirty || window.confirm(t("关闭后将丢弃未创建的服务器草稿，确定继续？")))) onClose()
  }
  async function submit(event: FormEvent) {
    event.preventDefault()
    if (locked || !name.trim()) return
    await onCreate({ name: name.trim(), notes: notes.trim() || null, is_active: active, image_channel: channel })
  }
  return <Modal open={open} title={t("新建服务器")} subtitle={t("创建后可在详情中查看认证信息、安装命令和负载历史。")} onClose={close}
    className="admin-form-dialog"
    footer={<><button type="button" className="button" disabled={locked} onClick={close}>{t("取消")}</button><button type="submit" form={formId} className="button primary" disabled={locked || !name.trim()}>{t("创建服务器")}</button></>}>
    <form id={formId} className="native-content-form" onSubmit={event => void submit(event)}>
      <Field label={t("服务器名称")} required hint={t("建议包含地区和用途，方便节点绑定时识别。")}>
        <Input data-autofocus required maxLength={255} disabled={locked} value={name} onChange={event => setName(event.target.value)} />
      </Field>
      <Field label={t("镜像通道")} hint={channel === 'dev' ? t("开发通道可能包含尚未发布的变更。") : t("使用稳定发布通道。")}>
        <Select disabled={locked} value={channel} onChange={event => setChannel(event.target.value as 'stable' | 'dev')}>
          <option value="stable">{t("stable · 稳定版")}</option><option value="dev">{t("dev · 开发版")}</option>
        </Select>
      </Field>
      <Switch label={t("启用服务器")} checked={active} disabled={locked} onChange={event => setActive(event.target.checked)} />
      <Field label={t("备注")} hint={t("{v0} / 2000 字符", { v0: notes.length })}>
        <Textarea rows={4} maxLength={2000} disabled={locked} value={notes} onChange={event => setNotes(event.target.value)} />
      </Field>
    </form>
  </Modal>
}
