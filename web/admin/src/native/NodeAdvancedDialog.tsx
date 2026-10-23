import { useId, useRef, useState, type FormEvent } from 'react'
import { Modal } from '../components/ui/Modal'
import { t } from '../lib/i18n'
import { useDirtyDraft } from '../lib/useDirtyDraft'
import { NodeCertificateFields } from './NodeCertificateFields'
import { NodeJsonField } from './NodeProtocolFields'
import { useAdminConfirmation } from './useAdminConfirmation'
import type { NodeConfiguration, NodeProtocol } from './nodeConfiguration'

type Advanced = Pick<NodeConfiguration, 'custom_outbounds' | 'custom_routes' | 'cert_config'>
const tabs = [['outbounds', '出站配置'], ['routes', '路由配置'], ['certificate', '证书配置']] as const
type Tab = typeof tabs[number][0]

export function NodeAdvancedDialog({ value, protocol, disabled, onApply, onClose }: {
  value: Advanced; protocol?: NodeProtocol; disabled: boolean; onApply: (value: Advanced) => void; onClose: () => void
}) {
  const formId = useId(), form = useRef<HTMLFormElement>(null)
  const [draft, setDraft] = useState<Advanced>(() => structuredClone(value))
  const [tab, setTab] = useState<Tab>('outbounds'), [error, setError] = useState('')
  const confirm = useAdminConfirmation(() => !disabled, protocol?.type)
  const dirty = JSON.stringify(draft) !== JSON.stringify(value)
  useDirtyDraft(dirty && !disabled)
  async function close() {
    if (disabled) return
    if ((dirty || form.current?.querySelector('[data-json-dirty="true"]')) &&
      !(await confirm(t('高级配置尚未应用到节点草稿，确定放弃修改？')))) return
    onClose()
  }
  function apply(event: FormEvent) {
    event.preventDefault()
    if (disabled || !form.current) return
    const invalid = form.current.querySelector<HTMLElement>('[data-json-dirty="true"], :invalid')
    if (invalid) {
      setTab(invalid.closest<HTMLElement>('[data-advanced-panel]')!.dataset.advancedPanel as Tab)
      setError('请先修正 JSON 并解析应用，再将高级配置应用到节点草稿。')
      requestAnimationFrame(() => {
        if (!form.current?.contains(invalid)) return
        const control = invalid.matches('textarea, input') ? invalid : invalid.querySelector<HTMLElement>('textarea, input')
        control?.focus()
      })
      return
    }
    onApply(draft); onClose()
  }
  return <Modal open size="medium" title={t('高级出站、路由与证书配置')}
    subtitle={t('修改先保存在此处，应用到节点草稿后仍需保存完整节点配置。')}
    className="node-advanced-dialog" onClose={() => void close()}
    footer={<><button type="button" className="button" disabled={disabled} onClick={() => void close()}>{t('取消高级配置')}</button>
      <button type="submit" form={formId} className="button primary" disabled={disabled}>{t('应用到节点草稿')}</button></>}>
    <div className="refinement-tabs" role="tablist" aria-label={t('高级配置分类')}>
      {tabs.map(([key, label], index) => <button type="button" role="tab" key={key} id={formId + '-' + key}
        aria-selected={tab === key} aria-controls={formId + '-panel-' + key} tabIndex={tab === key ? 0 : -1}
        onClick={() => setTab(key)} onKeyDown={event => {
          const next = event.key === 'ArrowRight' ? (index + 1) % tabs.length : event.key === 'ArrowLeft' ? (index + tabs.length - 1) % tabs.length : event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : -1
          if (next < 0) return
          event.preventDefault(); setTab(tabs[next][0]); document.getElementById(formId + '-' + tabs[next][0])?.focus()
        }}>{t(label)}</button>)}
    </div>
    {error ? <p role="alert">{t(error)}</p> : null}
    <form ref={form} id={formId} noValidate onSubmit={apply} className="node-advanced-form">
      {tabs.map(([key, label]) => <div key={key} role="tabpanel" id={formId + '-panel-' + key}
        aria-labelledby={formId + '-' + key} hidden={tab !== key} data-advanced-panel={key} tabIndex={0}>
        <p className="field-help">{t(label)} · {t('修改前请核对部署端支持的配置格式。JSON 校验通过后仍会由服务端验证。')}</p>
        {key === 'outbounds' ? <NodeJsonField label={t('自定义 Outbounds')} value={draft.custom_outbounds} arrayOnly disabled={disabled} onChange={custom_outbounds => setDraft(current => ({ ...current, custom_outbounds: custom_outbounds as unknown[] }))} />
          : key === 'routes' ? <NodeJsonField label={t('自定义 Routes')} value={draft.custom_routes} arrayOnly disabled={disabled} onChange={custom_routes => setDraft(current => ({ ...current, custom_routes: custom_routes as unknown[] }))} />
            : <NodeCertificateFields protocol={protocol} value={draft.cert_config} disabled={disabled} onChange={cert_config => setDraft(current => ({ ...current, cert_config }))} />}
      </div>)}
    </form>
  </Modal>
}
