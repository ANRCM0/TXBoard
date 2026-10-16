function fail(): never { throw new SyntaxError('Invalid JSON') }

/** Standard JSON parsing plus duplicate decoded key rejection; input bytes must be bounded by the caller. */
export function parseStrictJson(text: string): unknown {
  let offset = 0
  const space = () => { while (offset < text.length && /\s/.test(text[offset]!)) offset++ }
  const string = (): string => {
    const start = offset++
    while (offset < text.length) {
      const char = text[offset++]
      if (char === '\\') offset++
      else if (char === '"') return JSON.parse(text.slice(start, offset)) as string
    }
    return fail()
  }
  const scan = (depth: number): void => {
    if (depth > 30) fail()
    space(); const char = text[offset]
    if (char === '"') { string(); return }
    if (char === '{' || char === '[') {
      const object = char === '{', end = object ? '}' : ']'
      offset++; space(); const seen = new Set<string>()
      if (text[offset] === end) { offset++; return }
      for (;;) {
        space()
        if (object) {
          if (text[offset] !== '"') fail()
          const key = string(); if (seen.has(key)) fail(); seen.add(key)
          space(); if (text[offset++] !== ':') fail()
        }
        scan(depth + 1); space(); const separator = text[offset++]
        if (separator === end) return
        if (separator !== ',') fail()
      }
    }
    const start = offset
    while (offset < text.length && !/[\s,\]}]/.test(text[offset]!)) offset++
    if (start === offset) fail()
  }
  try {
    const value = JSON.parse(text) as unknown
    scan(0); space(); if (offset !== text.length) fail()
    return value
  } catch { return fail() }
}
