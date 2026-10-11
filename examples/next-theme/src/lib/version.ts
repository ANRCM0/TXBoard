// Runtime version negotiation (GW-507).
//
// Three independently-versioned planes must agree before the theme talks to
// the Gateway:
//
//   1. Gateway HTTP contract — `meta.version` on every envelope ('1' today).
//      The SDK already rejects anything else with `VERSION_MISMATCH`.
//   2. SDK package — the client code the theme imports.
//   3. Theme manifest — `txboard-theme.json` `engines`/`gateway` fields,
//      injected at build time.
//
// Rule: incompatibility is REFUSED (fail-closed), never silently downgraded.
// Capability gating (bootstrap.capabilities) is a separate, additive
// mechanism: a declared capability is a UI visibility hint only, never an
// authorization. The Gateway's route policy is the only authority.

export type VersionVerdict =
  | { ok: true }
  | { ok: false; plane: 'gateway' | 'sdk' | 'theme'; reason: string }

/** Contract major version this template was compiled against. */
export const THEME_CONTRACT_MAJOR = '1' as const
/** SDK version this template was compiled against (from package.json). */
export const THEME_SDK_VERSION = '0.1.0' as const
/** Gateway engines range declared by the theme manifest. */
export const THEME_ENGINES_GATEWAY = '>=1 <2' as const

export type ManifestEngines = {
  /** Manifest-declared gateway contract range, e.g. '>=1 <2'. */
  gateway: string
  /** Manifest-declared SDK range, e.g. '>=0.1.0 <1.0.0'. */
  sdk: string
  /** Manifest-declared contract version, e.g. '1.x'. */
  contract: string
}

/** Default manifest declaration for this template (mirrors txboard-theme.json). */
export const THEME_MANIFEST_ENGINES: ManifestEngines = {
  gateway: THEME_ENGINES_GATEWAY,
  sdk: '>=0.1.0 <1.0.0',
  contract: '1.x',
}

/** Minimal semver comparison for single-major-range checks: '>=1 <2'. */
function inRange(value: string, range: string): boolean {
  const major = parseInt(value.split('.')[0] ?? '0', 10)
  const ge = range.match(/^>=\s*(\d+)/)
  const lt = range.match(/<\s*(\d+)/)
  if (ge && major < parseInt(ge[1] ?? '0', 10)) return false
  if (lt && major >= parseInt(lt[1] ?? '0', 10)) return false
  return true
}

/**
 * Full three-plane negotiation. `liveContractMajor` comes from the live
 * Gateway envelope (`meta.version`); the SDK client would have already thrown
 * `VERSION_MISMATCH` on a mismatch, but the SSR theme checks BEFORE any
 * protected call so a version incompatibility surfaces as a page-level
 * refusal rather than a per-call failure.
 */
export function negotiateVersion(
  liveContractMajor: string,
  manifest: ManifestEngines = THEME_MANIFEST_ENGINES,
): VersionVerdict {
  // Plane 1: Gateway contract vs the live Gateway response.
  if (liveContractMajor !== THEME_CONTRACT_MAJOR) {
    return { ok: false, plane: 'gateway', reason: `Gateway contract ${liveContractMajor} not supported (template requires ${THEME_CONTRACT_MAJOR})` }
  }
  // Plane 2: SDK in use vs the manifest's declared SDK range.
  if (!inRange(THEME_SDK_VERSION, manifest.sdk)) {
    return { ok: false, plane: 'sdk', reason: `SDK ${THEME_SDK_VERSION} outside manifest range ${manifest.sdk}` }
  }
  // Plane 3: manifest's declared engines.gateway range vs the live Gateway.
  if (!inRange(liveContractMajor, manifest.gateway)) {
    return { ok: false, plane: 'theme', reason: `Manifest engines.gateway ${manifest.gateway} excludes contract ${liveContractMajor}` }
  }
  if (manifest.contract.split('.')[0] !== liveContractMajor) {
    return { ok: false, plane: 'theme', reason: `Manifest contract ${manifest.contract} incompatible with Gateway ${liveContractMajor}` }
  }
  return { ok: true }
}

/**
 * Capability gating (separate from version negotiation): a UI element appears
 * only when the Gateway advertised the operation in bootstrap.capabilities.
 * This is a visibility hint, NOT authorization — the Gateway route policy is
 * the sole authority (GW-405/GW-501 zero-weight principle).
 */
export function hasCapability(caps: readonly string[] | undefined, cap: string): boolean {
  return Array.isArray(caps) && caps.includes(cap)
}
