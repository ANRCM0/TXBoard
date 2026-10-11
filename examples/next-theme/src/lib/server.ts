// Server-only SDK access for the Next.js SSR theme (GW-506).
//
// Hard rules enforced here:
//  1. `createClient()` MUST be called once per request, with the request's own
//     `cookie` header. There is deliberately no module-level singleton: a
//     global client would capture the FIRST request's token and replay it to
//     every subsequent user — the cross-request session pollution the task
//     explicitly forbids.
//  2. The user bearer never appears in any serialized payload, never enters
//     the client bundle, and is never written to localStorage. The token is
//     read from the httpOnly cookie, used server-side, and discarded with
//     the request.
//  3. Anything in this file that could leak the token to the browser fails
//     the structural guardrail (examples/theme-template-check.mjs), so keep
//     it server-only and never export raw cookie values.
import 'server-only'
import { cookies } from 'next/headers'
import { createTXBoardClient, type TXBoardClient } from '@txboard/theme-sdk'
export type { BootstrapData } from '@txboard/theme-sdk'

/**
 * The Gateway HTTP contract major version this template is written against.
 * Mirrors `CONTRACT_VERSION` in apps/gateway/src/middleware/request-context.ts
 * (the `meta.version` field of every envelope). GW-507 version negotiation
 * compares this against the live Gateway response — mismatch = refuse.
 */
export const CONTRACT_VERSION = '1'

/**
 * Name of the httpOnly session cookie set by the login server action.
 * `httpOnly` means the browser JS can never read it: the token physically
 * cannot leak through XSS, which is why the SPA templates' localStorage
 * approach is intentionally NOT reused here.
 */
export const SESSION_COOKIE = 'txboard_session'

const GATEWAY_BASE_URL = process.env.GATEWAY_BASE_URL || '/gateway/v1'

/**
 * Build a request-scoped SDK client. The token comes from THIS request's
 * cookie header via next/headers' cookies(); two concurrent requests with
 * different sessions can never observe each other's token because each
 * call closes over its own cookie store.
 */
export function createClient(): TXBoardClient {
  return createTXBoardClient({
    baseURL: GATEWAY_BASE_URL,
    // Read the httpOnly cookie at call time (not at client creation) so the
    // getToken closure always resolves against the active request.
    getToken: () => cookies().get(SESSION_COOKIE)?.value,
    timeoutMs: 10000,
    fetchImpl: globalThis.fetch,
  })
}

/** True when the current request carries a session cookie. */
export function hasSession(): boolean {
  return !!cookies().get(SESSION_COOKIE)?.value
}
