import { t, useLocale } from '../../lib/i18n'
import { useEffect, useRef, useState } from 'react'
import { X } from 'lucide-react'

export function TagInput({ id, value, onChange, disabled, maxItems = 30, maxLength = 80 }: {
  id: string; value: string[]; onChange: (tags: string[]) => void; disabled?: boolean; maxItems?: number; maxLength?: number
}) {
  useLocale()
  const [draft, setDraft] = useState('')
  const input = useRef<HTMLInputElement>(null)
  const additions = draft.split(/[,，\n]/).map(tag => tag.trim()).filter(Boolean)
  const overLimit = new Set([...value, ...additions]).size > maxItems
  const tooLong = additions.some(tag => tag.length > maxLength)
  const validation = overLimit ? t("最多添加 {v0} 个标签，请先移除已有标签。", { v0: maxItems }) : tooLong ? t("每个标签最多 {v0} 个字符。", { v0: maxLength }) : ''
  useEffect(() => {
    input.current?.setCustomValidity(validation)
  }, [validation])
  function commit(raw: string) {
    const additions = raw.split(/[,，\n]/).map(tag => tag.trim()).filter(Boolean)
    const next = [...new Set([...value, ...additions])]
    if (next.length > maxItems || additions.some(tag => tag.length > maxLength)) return
    onChange(next)
    setDraft('')
  }
  return <div className="ui-tag-field">
    <div className="ui-tag-input" aria-disabled={disabled}>
      {value.map((tag, index) => <span className="ui-tag-input-item" key={index}>{tag}<button type="button" aria-label={t("移除标签 ") + tag} disabled={disabled}
        onClick={() => onChange(value.filter(item => item !== tag))}><X size={12} /></button></span>)}
      <input ref={input} id={id} value={draft} disabled={disabled} placeholder={t("输入标签后按回车")} aria-invalid={!!validation}
        onChange={event => setDraft(event.target.value)} onBlur={() => { if (!disabled) commit(draft) }}
        onKeyDown={event => {
          if (event.nativeEvent.isComposing) return
          if (['Enter', ',', '，'].includes(event.key)) { event.preventDefault(); commit(draft) }
          else if (event.key === 'Backspace' && !draft) { event.preventDefault(); onChange(value.slice(0, -1)) }
        }} />
    </div>
    <small className="ui-tag-hint">{t("回车或逗号添加 ·")}{value.length} / {maxItems}{t("个标签")}</small>
    {validation ? <small role="status">{validation}</small> : null}
  </div>
}
