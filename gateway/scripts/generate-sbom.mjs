#!/usr/bin/env node
/**
 * GW-207: generate CycloneDX SBOMs for the gateway workspace.
 *
 * Produces two artifacts under sbom/:
 *   - gateway.cdx.json : production/runtime closure of @txboard/gateway (dev omitted)
 *   - full.cdx.json    : full workspace closure including dev + test tooling
 *
 * The generator is pinned so CI output is reproducible. The package tree is read
 * from the committed lockfile (--package-lock-only), so no install happens; the
 * network is only used to fetch the pinned generator on a cold npx cache.
 */
import { spawnSync } from 'node:child_process'
import { mkdirSync, existsSync, readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, join } from 'node:path'

const GENERATOR = '@cyclonedx/cyclonedx-npm@6.0.1'
const root = join(dirname(fileURLToPath(import.meta.url)), '..')
const outDir = join(root, 'sbom')
mkdirSync(outDir, { recursive: true })

function generate(name, extraArgs) {
  const out = join(outDir, name)
  const args = [
    '--yes', GENERATOR,
    '--ignore-npm-errors',
    '--output-format', 'JSON',
    '--output-file', out,
    '--package-lock-only',
    ...extraArgs,
  ]
  const res = spawnSync('npx', args, { cwd: root, stdio: 'inherit' })
  if (res.status !== 0 || !existsSync(out)) throw new Error(`SBOM generation failed for ${name}`)
  const bom = JSON.parse(readFileSync(out, 'utf8'))
  return { name, specVersion: bom.specVersion, components: bom.components?.length ?? 0 }
}

const runtime = generate('gateway.cdx.json', ['--omit', 'dev', '--workspace', '@txboard/gateway'])
const full = generate('full.cdx.json', [])

console.log('SBOM written:')
console.log(`  sbom/${runtime.name}  CycloneDX ${runtime.specVersion}, ${runtime.components} components (runtime)`)
console.log(`  sbom/${full.name}     CycloneDX ${full.specVersion}, ${full.components} components (incl. dev)`)
