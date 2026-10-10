import type { ReactNode } from 'react'

export function MarkdownLite({ content, empty = '暂无内容' }: { content: string; empty?: string }) {
  if (!content.trim()) return <div className="markdown-preview muted">{empty}</div>
  return <div className="markdown-preview">
    {content.split('\n').map((line, index) => {
      const text = line.trim()
      if (!text) return <div className="md-gap" key={index}/>
      if (text.startsWith('### ')) return <h4 key={index}>{inline(text.slice(4))}</h4>
      if (text.startsWith('## ')) return <h3 key={index}>{inline(text.slice(3))}</h3>
      if (text.startsWith('# ')) return <h2 key={index}>{inline(text.slice(2))}</h2>
      if (text.startsWith('- ')) return <div className="md-bullet" key={index}>• {inline(text.slice(2))}</div>
      if (/^\d+\.\s/.test(text)) return <div className="md-bullet" key={index}>{inline(text)}</div>
      return <p key={index}>{inline(text)}</p>
    })}
  </div>
}

function inline(text: string): ReactNode[] {
  const parts = text.split(/(\*\*[^*]+\*\*|\x60[^\x60]+\x60)/g)
  return parts.map((part, index) => {
    if (part.startsWith('**') && part.endsWith('**')) return <strong key={index}>{part.slice(2, -2)}</strong>
    if (part.charCodeAt(0) === 96 && part.charCodeAt(part.length - 1) === 96) return <code key={index}>{part.slice(1, -1)}</code>
    return part
  })
}
