import { useEffect, useRef, useState } from 'react'

export function JsonEditor({ value, onChange }: { value: Record<string, unknown>; onChange: (value: Record<string, unknown>) => void }) {
  const [text, setText] = useState(() => JSON.stringify(value, null, 2))
  const [error, setError] = useState('')
  const lastEmitted = useRef(JSON.stringify(value))
  useEffect(() => {
    const serialized = JSON.stringify(value)
    if (serialized === lastEmitted.current) return
    lastEmitted.current = serialized
    setText(JSON.stringify(value, null, 2))
  }, [value])
  return <div>
    <textarea className="json-editor" value={text} onChange={e => {
      const next=e.target.value; setText(next)
      try { const parsed=JSON.parse(next); setError(''); lastEmitted.current=JSON.stringify(parsed); onChange(parsed) } catch { setError('JSON 格式有误') }
    }}/>
    {error && <div className="field-error">{error}</div>}
  </div>
}
