import { readFileSync } from 'node:fs'

const workflows = [
  'ci-api.yml',
  'ci-web.yml',
  'ci-mcp.yml',
  'docker-txboard.yml',
]

for (const workflow of workflows) {
  const content = readFileSync(new URL(`../.github/workflows/${workflow}`, import.meta.url), 'utf8')
    .replace(/\r\n/g, '\n')
  const pullRequest = content.match(/^  pull_request:\n((?: {4}[^\n]*\n| {6}[^\n]*\n)*)/m)?.[1]
  const push = content.match(/^  push:\n((?: {4}[^\n]*\n| {6}[^\n]*\n)*)/m)?.[1]

  if (!pullRequest || !/^ {4}branches: \[main\]$/m.test(pullRequest)) {
    throw new Error(`${workflow}: pull_request must target main`)
  }
  if (/^ {4}paths(?:-ignore)?:/m.test(pullRequest)) {
    throw new Error(`${workflow}: PR path filtering can skip contract-only checks`)
  }
  if (!push || !/^ {4}paths:$/m.test(push)) {
    throw new Error(`${workflow}: retain path-filtered pushes (especially image publishing)`)
  }
}

console.log(`PR trigger coverage: ${workflows.length} workflows target every PR to main; pushes stay filtered.`)
