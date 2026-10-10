import { KeyRound, LoaderCircle, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { toast } from 'sonner'
import { generateSecret, type ProtocolFieldGenerator, type ProtocolFormCondition, type ProtocolFormField } from '../../api/server'

function isRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value)
}

function getAt(source: Record<string, unknown>, path: string): unknown {
  let current: unknown = source
  for (const key of path.split('.')) {
    if (!isRecord(current) || !(key in current)) return undefined
    current = current[key]
  }
  return current
}

function conditionsFor(field: ProtocolFormField): ProtocolFormCondition[] {
  if (!field.visible_when) return []
  return Array.isArray(field.visible_when) ? field.visible_when : [field.visible_when]
}

function isVisible(field: ProtocolFormField, value: Record<string, unknown>) {
  return conditionsFor(field).every((condition) => Object.is(getAt(value, condition.field), condition.equals))
}

function scalarValue(value: unknown) {
  return value === null || value === undefined ? '' : String(value)
}

/** Reports whether this editor currently holds unparsable text. */
type JsonValidityReporter = (key: string, report: (() => boolean) | null) => void

function JsonSchemaField({
  field,
  value,
  onChange,
  reportJsonValidity,
}: {
  field: ProtocolFormField
  value: unknown
  onChange: (value: unknown) => void
  reportJsonValidity?: JsonValidityReporter
}) {
  const arrayOnly = field.type === 'json-array'
  const format = (input: unknown) => JSON.stringify(input ?? (arrayOnly ? [] : {}), null, 2)
  const [text, setText] = useState(format(value))
  const [invalid, setInvalid] = useState(false)

  const parse = (raw: string): { ok: boolean; value?: unknown } => {
    try {
      const parsed = raw.trim() ? JSON.parse(raw) : (arrayOnly ? [] : {})
      if (arrayOnly && !Array.isArray(parsed)) return { ok: false }
      if (!arrayOnly && !isRecord(parsed) && !Array.isArray(parsed)) return { ok: false }
      return { ok: true, value: parsed }
    } catch {
      return { ok: false }
    }
  }

  // Keep the operator's exact text when the value round-trips from our own
  // commit; re-format only for externally supplied values.
  useEffect(() => {
    setText(previous => {
      const parsed = parse(previous)
      return parsed.ok && JSON.stringify(parsed.value) === JSON.stringify(value)
        ? previous
        : format(value)
    })
    setInvalid(false)
  }, [value])

  const commit = (raw: string, announce: boolean) => {
    setText(raw)
    const parsed = parse(raw)
    if (!parsed.ok) {
      setInvalid(true)
      if (announce) toast.error(field.label + ' JSON 格式不正确，请修正后再提交')
      return
    }
    setInvalid(false)
    onChange(parsed.value)
  }

  const invalidRef = useRef(invalid)
  invalidRef.current = invalid
  useEffect(() => {
    reportJsonValidity?.(field.label, () => invalidRef.current)
    return () => reportJsonValidity?.(field.label, null)
  }, [reportJsonValidity, field.label])

  return <label className={'field' + (field.full ? ' full' : '')}>
    <span>{field.label}</span>
    <textarea
      value={text}
      onChange={(event) => commit(event.target.value, false)}
      onBlur={() => commit(text, true)}
      spellCheck={false}
      aria-invalid={invalid || undefined}
      className={invalid ? 'is-invalid' : undefined}
    />
  </label>
}

/** Generator params may reference editor context (e.g. $host -> the node address). */
function resolveGeneratorParams(generator: ProtocolFieldGenerator, context: Record<string, unknown>) {
  const params: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(generator.params ?? {})) {
    params[key] = typeof value === 'string' && value.startsWith('$')
      ? context[value.slice(1)] ?? ''
      : value
  }
  return params
}

/**
 * Text-like field that can mint its own value (key pairs, short ids, ECH keys).
 * Generating writes every mapped protocol_settings path, so a key pair lands in
 * both the private and the public field at once.
 */
function SecretInputField({
  field,
  generator,
  value,
  onChange,
  generatorContext,
}: {
  field: ProtocolFormField
  generator: ProtocolFieldGenerator
  value: unknown
  onChange: (path: string, value: unknown) => void
  generatorContext: Record<string, unknown>
}) {
  const [pending, setPending] = useState(false)
  const filled = String(value ?? '').trim().length > 0

  async function generate() {
    if (pending) return
    setPending(true)
    try {
      const material = await generateSecret(generator.kind, resolveGeneratorParams(generator, generatorContext))
      const written = Object.entries(generator.map).filter(([key]) => Boolean(material[key]))
      written.forEach(([key, path]) => onChange(path, material[key]))
      if (written.length === 0) {
        toast.error('生成失败，请稍后重试')
        return
      }
      toast.success(written.length > 1 ? '已生成新密钥对，相关字段已同步填充' : field.label + ' 已生成')
    } catch {
      // The API client already surfaces the failure toast; keep the current value.
    } finally {
      setPending(false)
    }
  }

  function clear() {
    Object.values(generator.map).forEach(path => onChange(path, ''))
  }

  const control = field.type === 'textarea'
    ? <textarea
        value={scalarValue(value)}
        aria-label={field.label}
        onChange={(event) => onChange(field.key, event.target.value)}
      />
    : <input
        value={scalarValue(value)}
        placeholder={field.placeholder}
        aria-label={field.label}
        onChange={(event) => onChange(field.key, event.target.value)}
      />

  return <div className={'field field-generator' + (field.full ? ' full' : '')}>
    <div className="field-generator-header">
      <span>{field.label}</span>
      {filled ? <small>重新生成会同时覆盖关联字段</small> : null}
    </div>
    <div className="field-generator-row">
      {control}
      <button
        type="button"
        className="button field-generator-button"
        onClick={generate}
        disabled={pending}
        title={generator.label || '生成'}
        aria-label={generator.label || '生成'}
      >
        {pending ? <LoaderCircle size={15} className="loading-spinner" aria-hidden="true"/> : <KeyRound size={15} aria-hidden="true"/>}
        {pending ? '生成中…' : filled ? '重新生成' : generator.label || '生成'}
      </button>
      {filled ? <button type="button" className="icon-button" onClick={clear} title="清空" aria-label="清空">
        <X size={15} aria-hidden="true"/>
      </button> : null}
    </div>
  </div>
}

export function ProtocolSchemaForm({
  fields,
  value,
  onChange,
  generatorContext = {},
  reportJsonValidity,
}: {
  fields: ProtocolFormField[]
  value: Record<string, unknown>
  onChange: (path: string, value: unknown) => void
  generatorContext?: Record<string, unknown>
  reportJsonValidity?: JsonValidityReporter
}) {
  return <div className="node-form-grid">
    {fields.filter((field) => isVisible(field, value)).map((field) => {
      const current = getAt(value, field.key)
      const className = field.full ? ' full' : ''

      if (field.type === 'checkbox') {
        return <label key={field.key} className={'check-field' + className}>
          <input
            type="checkbox"
            checked={Boolean(current)}
            onChange={(event) => onChange(field.key, event.target.checked)}
          />
          {field.label}
        </label>
      }

      if (field.type === 'json' || field.type === 'json-array') {
        return <JsonSchemaField
          key={field.key}
          field={field}
          value={current}
          onChange={(next) => onChange(field.key, next)}
          reportJsonValidity={reportJsonValidity}
        />
      }

      if (field.type === 'string-list') {
        const separator = field.separator === 'newline' ? '\n' : ', '
        const splitPattern = field.separator === 'newline' ? /\r?\n/ : /,/
        const items = Array.isArray(current) ? current.map(String) : []
        return <label key={field.key} className={'field' + className}>
          <span>{field.label}</span>
          {field.separator === 'newline'
            ? <textarea
                value={items.join(separator)}
                placeholder={field.placeholder}
                onChange={(event) => onChange(
                  field.key,
                  event.target.value.split(splitPattern).map((item) => item.trim()).filter(Boolean),
                )}
              />
            : <input
                value={items.join(separator)}
                placeholder={field.placeholder}
                onChange={(event) => onChange(
                  field.key,
                  event.target.value.split(splitPattern).map((item) => item.trim()).filter(Boolean),
                )}
              />}
        </label>
      }

      if (field.type === 'select') {
        return <label key={field.key} className={'field' + className}>
          <span>{field.label}</span>
          <select
            value={scalarValue(current)}
            onChange={(event) => {
              const option = field.options?.find((item) => String(item.value) === event.target.value)
              onChange(field.key, option ? option.value : event.target.value)
            }}
          >
            {(field.options || []).map((option) => (
              <option key={String(option.value)} value={String(option.value)}>{option.label}</option>
            ))}
          </select>
        </label>
      }

      if (field.generator?.map) {
        return <SecretInputField
          key={field.key}
          field={field}
          generator={field.generator}
          value={current}
          onChange={onChange}
          generatorContext={generatorContext}
        />
      }
      if (field.type === 'textarea') {
        return <label key={field.key} className={'field' + className}>
          <span>{field.label}</span>
          <textarea
            value={scalarValue(current)}
            placeholder={field.placeholder}
            onChange={(event) => onChange(field.key, event.target.value)}
          />
        </label>
      }

      if (field.type === 'number') {
        return <label key={field.key} className={'field' + className}>
          <span>{field.label}</span>
          <input
            type="number"
            min={field.min}
            max={field.max}
            step={field.step}
            value={scalarValue(current)}
            placeholder={field.placeholder}
            onChange={(event) => onChange(
              field.key,
              event.target.value.trim() === '' ? null : Number(event.target.value),
            )}
          />
        </label>
      }


      return <label key={field.key} className={'field' + className}>
        <span>{field.label}</span>
        <input
          value={scalarValue(current)}
          placeholder={field.placeholder}
          onChange={(event) => onChange(field.key, event.target.value)}
        />
      </label>
    })}
  </div>
}
