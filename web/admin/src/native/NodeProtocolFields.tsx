import { t, useLocale } from '../lib/i18n'
import { useEffect, useRef, useState } from 'react'
import { Field, Input, Select, Switch, Textarea } from '../components/ui/Field'
import { protocolValue, withProtocolValue, type NodeProtocolField, type NodeProtocol } from './nodeConfiguration'
import { useDirtyDraft } from '../lib/useDirtyDraft'

export function NodeJsonField({ label, value, onChange, disabled, arrayOnly = false, objectOnly = false, templates = [] }: {
  label: string; value: unknown; onChange: (value: unknown) => void; disabled?: boolean; arrayOnly?: boolean; objectOnly?: boolean
  templates?: { label: string; value: unknown }[]
}) {
  const { locale } = useLocale()
  const encoded = JSON.stringify(value, null, 2) ?? 'null'
  const accepted = useRef(encoded)
  const [text, setText] = useState(encoded), [error, setError] = useState('')
  const dirty = text !== encoded
  useDirtyDraft(dirty && !disabled)
  const input = useRef<HTMLTextAreaElement>(null)
  useEffect(() => { if (accepted.current !== encoded) { accepted.current = encoded; setText(encoded); setError('') } }, [encoded])
  useEffect(() => { input.current?.setCustomValidity(t(error || (dirty ? 'JSON 修改尚未应用，请先解析并应用。' : ''))) }, [error, dirty, locale])
  function parse(source: string): unknown {
    const parsed: unknown = JSON.parse(source)
    if (arrayOnly && !Array.isArray(parsed)) throw new Error('请填写 JSON 数组。')
    if (objectOnly && (!parsed || typeof parsed !== 'object' || Array.isArray(parsed))) throw new Error('请填写 JSON 对象。')
    if (parsed !== null && typeof parsed !== 'object') throw new Error('请填写 JSON 对象、数组或 null。')
    return parsed
  }
  function edit(source: string) {
    setText(source)
    try { parse(source); setError('') }
    catch (failure) { setError(failure instanceof SyntaxError ? 'JSON 格式有误，请检查后再保存。' : (failure as Error).message) }
  }
  function apply() {
    if (disabled || !dirty) return
    try {
      const parsed = parse(text)
      if (!window.confirm(t('将解析后的 JSON 写入 {label} 草稿，确定应用？', { label }))) return
      const next = JSON.stringify(parsed, null, 2)
      accepted.current = next; setText(next); setError(''); onChange(parsed)
    } catch (failure) { setError(failure instanceof SyntaxError ? 'JSON 格式有误，请检查后再保存。' : (failure as Error).message) }
  }
  return <div className="node-editor-json-control"><Field label={label} error={error ? t(error) : undefined}><textarea ref={input} className="ui-textarea node-editor-json" spellCheck={false} rows={5} value={text} disabled={disabled}
    data-json-dirty={dirty || undefined} onChange={event => edit(event.target.value)} /></Field>
    {templates.length ? <label className="node-editor-template">{t('JSON 模板')}<Select aria-label={t('{label} JSON 模板', { label })} value="" disabled={disabled} onChange={event => {
      const template = templates[Number(event.target.value)]
      if (template && (!dirty || window.confirm(t('模板将替换当前 JSON 编辑内容，确定继续？')))) edit(JSON.stringify(template.value, null, 2))
    }}><option value="">{t('选择模板填入编辑器')}</option>{templates.map((template, index) => <option key={index} value={index}>{t(template.label)}</option>)}</Select></label> : null}
    <div className="network-form-actions"><button type="button" className="button" disabled={disabled || !dirty || !!error} onClick={apply}>{t('解析并应用 {label}', { label })}</button>
      {dirty ? <><button type="button" className="button" disabled={disabled} onClick={() => {
        if (window.confirm(t('确定放弃这项 JSON 修改？'))) { setText(encoded); setError('') }
      }}>{t('还原 JSON')}</button><span role="status">{t('JSON 修改尚未应用，请先解析并应用。')}</span></> : null}</div>
  </div>
}

export function NodeProtocolFields({ protocol, value, onChange, disabled, onGenerate, generating }: {
  protocol: NodeProtocol; value: Record<string, unknown>; onChange: (value: Record<string, unknown>, appliedJson?: boolean) => void
  disabled?: boolean; onGenerate: (field: NodeProtocolField) => void; generating?: string
}) {
  useLocale()
  return <div className="node-editor-grid">{protocol.form_schema.filter(field => (field.visible_when ?? []).every(rule =>
    rule.one_of ? rule.one_of.some(item => Object.is(protocolValue(value, rule.field), item)) : Object.is(protocolValue(value, rule.field), rule.equals))).map(field => {
    const current = protocolValue(value, field.key)
    const update = (next: unknown, appliedJson = false) => onChange(withProtocolValue(value, field.key, next), appliedJson)
    const secret = /(?:password|private_key|decryption|\.key)$/.test(field.key)
    let control
    if (field.type === 'checkbox') control = <Switch label={t(field.label)} checked={current === true} disabled={disabled} onChange={event => update(event.target.checked)} />
    else if (['json', 'json-array', 'json-object'].includes(field.type)) control = <NodeJsonField label={t(field.label)} value={current ?? (field.type === 'json-array' ? [] : {})} onChange={next => update(next, true)} disabled={disabled}
      arrayOnly={field.type === 'json-array'} objectOnly={field.type === 'json-object'} templates={field.templates?.filter(template => !template.transport || template.transport === protocolValue(value, 'network'))} />
    else if (field.type === 'select') control = <Field label={t(field.label)}><Select value={String(current ?? '')} disabled={disabled}
      onChange={event => update(field.options?.find(option => String(option.value) === event.target.value)?.value ?? event.target.value)}>
      {!(field.options ?? []).some(option => String(option.value) === String(current ?? '')) ? <option value={String(current ?? '')}>{String(current ?? t("请选择"))}</option> : null}
      {field.options?.map(option => <option key={String(option.value)} value={String(option.value)}>{t(option.label)}</option>)}
    </Select></Field>
    else if (field.type === 'textarea' || field.type === 'string-list') control = <Field label={t(field.label)}><Textarea rows={field.type === 'textarea' ? 4 : 3}
      value={field.type === 'string-list' ? (Array.isArray(current) ? current.join(field.separator === 'comma' ? ', ' : '\n') : '') : String(current ?? '')}
      placeholder={field.placeholder ? t(field.placeholder) : undefined} disabled={disabled} spellCheck={false}
      onChange={event => update(field.type === 'string-list' ? event.target.value.split(field.separator === 'comma' ? /[,，\n]/ : /\n/).map(item => item.trim()).filter(Boolean) : event.target.value)} /></Field>
    else control = <Field label={t(field.label)}><Input type={field.type === 'number' ? 'number' : secret ? 'password' : 'text'} value={String(current ?? '')}
      min={field.min} max={field.max} step={field.type === 'number' ? '1' : undefined} placeholder={field.placeholder ? t(field.placeholder) : undefined} disabled={disabled} autoComplete="off"
      onChange={event => update(field.type === 'number' ? event.target.value === '' ? null : Number(event.target.value) : event.target.value)} /></Field>
    return <div key={field.key} className={field.full || ['json', 'json-array', 'json-object', 'textarea', 'string-list'].includes(field.type) ? 'node-editor-wide' : ''}>
      {control}{field.generator ? <button type="button" className="button node-editor-generator" disabled={disabled || !!generating} onClick={() => onGenerate(field)}>
        {generating === field.key ? t("正在生成…") : t(field.generator.label)}</button> : null}
    </div>
  })}</div>
}
