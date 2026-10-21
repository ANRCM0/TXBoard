import { t, useLocale } from '../lib/i18n'
import { NodeJsonField, NodeProtocolFields } from './NodeProtocolFields'
import type { NodeProtocol } from './nodeConfiguration'
import { useRef } from 'react'

export function NodeCertificateFields({ protocol, value, onChange, disabled }: {
  protocol?: NodeProtocol; value: unknown; onChange: (value: unknown) => void; disabled?: boolean
}) {
  useLocale()
  const section = useRef<HTMLElement>(null)
  const source = value && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {}
  const normalized = { ...source, ...(source.cert_mode === undefined && typeof source.mode === 'string' ? { cert_mode: source.mode } : {}) }
  function change(next: unknown, appliedJson = false) {
    const pending = section.current?.querySelector(appliedJson ? '.node-certificate-raw [data-json-dirty="true"]' : '[data-json-dirty="true"]')
    if (pending && !window.confirm(t('此修改将替换尚未应用的证书 JSON 编辑内容，确定继续？'))) return
    onChange(next)
  }
  return <section ref={section} className="node-editor-certificate"><h4>{t('证书配置')}</h4>
    <p>{t('证书由部署节点的证书管理器执行。无证书会明确关闭托管证书；未设置时使用节点兼容配置。DNS 提供商及环境变量必须与节点支持的提供商匹配。')}</p>
    {protocol?.certificate_schema?.length ? <NodeProtocolFields protocol={{ ...protocol, form_schema: protocol.certificate_schema }} value={normalized}
      disabled={disabled} onGenerate={() => undefined} onChange={(next, appliedJson) => {
        const canonical = { ...next }; delete canonical.mode; change(canonical, appliedJson)
      }} /> : <p>{t('当前服务端未声明证书表单元数据，可使用高级 JSON 配置。')}</p>}
    <button type="button" className="button" disabled={disabled || value === null} onClick={() => {
      if (window.confirm(t('确定移除管理端证书配置并使用节点兼容配置？'))) change(null)
    }}>{t('使用节点兼容证书配置')}</button>
    <details className="node-certificate-raw"><summary>{t('高级证书 JSON')}</summary><NodeJsonField label={t('证书配置 JSON')} value={value} disabled={disabled} objectOnly onChange={onChange} /></details>
  </section>
}
