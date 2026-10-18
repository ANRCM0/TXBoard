import { ExtensionClient, parseBridgeRequest } from '@txboard/core-sdk'

export class ModuleBridge {
  private readonly seen = new Set<string>()
  private pending = 0
  private active = true

  constructor(private readonly source: object, private readonly client: ExtensionClient) {}

  close(): void { this.active = false }

  async receive(source: unknown, origin: string, value: unknown): Promise<Record<string, unknown> | null> {
    if (!this.active || source !== this.source || origin !== 'null') return null
    const input = parseBridgeRequest(value)
    if (!input || this.seen.has(input.id) || this.seen.size >= 512 || this.pending >= 8) return null
    this.seen.add(input.id)
    this.pending++
    let result: Record<string, unknown>
    try {
      const data = await this.client.call(input.operation, input.input, { idempotencyKey: input.idempotencyKey })
      result = { channel: 'core-module/v1', id: input.id, data }
    } catch (error) {
      const code = error instanceof Error && /^[A-Z][A-Z0-9_]{1,79}$/.test(error.message) ? error.message : 'MODULE_REQUEST_FAILED'
      result = { channel: 'core-module/v1', id: input.id, error: code }
    } finally { this.pending-- }
    return this.active ? result : null
  }
}
