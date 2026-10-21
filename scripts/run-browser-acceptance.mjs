import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const root = fileURLToPath(new URL('../', import.meta.url))
const scripts = [
  'admin-browser.mjs',
  'admin-workflows-browser.mjs',
  'admin-catalog-network-browser.mjs',
  'admin-management-browser.mjs',
  'admin-code-editor-browser.mjs',
  'user-browser.mjs',
]
const failures = []
for (const script of scripts) {
  console.log(`\nBrowser acceptance: ${script}`)
  const result = spawnSync(process.execPath, ['scripts/' + script], { cwd: root, stdio: 'inherit' })
  if (result.error) console.error(result.error)
  if (result.signal) {
    console.error(`Browser acceptance interrupted by ${result.signal}`)
    process.exit(1)
  }
  if (result.status !== 0) failures.push(script)
}
if (failures.length) {
  console.error(`\nFailed browser acceptance: ${failures.join(', ')}`)
  process.exitCode = 1
} else console.log(`\nPassed all ${scripts.length} browser acceptance groups.`)
