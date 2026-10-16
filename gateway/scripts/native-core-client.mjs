#!/usr/bin/env node
import { NativeCoreClient, loadNativeServiceConfig } from '../apps/gateway/dist/services/native-internal.js'
import { parseStrictJson } from '../apps/gateway/dist/security/strict-json.js'

// A deployable server/operations consumer, not a browser SDK. Read business
// input from stdin, actor credentials from the process secret environment.
// Never pass passwords or bearer credentials as command-line arguments.
const operation = process.argv[2]
const domain = process.argv[3] ?? 'user'
if (!operation || !['user', 'admin'].includes(domain)) {
  process.stderr.write('Usage: node scripts/native-core-client.mjs OPERATION [user|admin] < input.json\n')
  process.exit(2)
}
try {
  const service = loadNativeServiceConfig(process.env, domain)
  if (!service) throw new Error('SERVICE_DISABLED')
  const client = new NativeCoreClient(new URL(process.env.TXBOARD_UPSTREAM_URL ?? ''), service)
  const chunks = []
  let bytes = 0
  for await (const chunk of process.stdin) {
    bytes += chunk.length
    if (bytes > 32768) throw new Error('INPUT_LIMIT')
    chunks.push(chunk)
  }
  const text = new TextDecoder('utf-8', { fatal: true }).decode(Buffer.concat(chunks))
  const input = text.trim() ? parseStrictJson(text) : {}
  if (!input || typeof input !== 'object' || Array.isArray(input)) throw new Error('INPUT_OBJECT_REQUIRED')
  const result = await client.call(operation, {
    input, authorization: process.env.CORE_ACTOR_BEARER,
    adminPath: process.env.CORE_ADMIN_PATH, idempotencyKey: process.env.CORE_IDEMPOTENCY_KEY,
  })
  process.stdout.write(`${JSON.stringify(result)}\n`)
} catch {
  // Do not echo network errors, TLS paths, input, service or actor credentials.
  process.stderr.write('CORE_OPERATION_FAILED; preserve the original idempotency key for recovery\n')
  process.exitCode = 1
}
