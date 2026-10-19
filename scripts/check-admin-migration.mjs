import { readFile, stat } from 'node:fs/promises'
import { createHash } from 'node:crypto'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import ts from 'typescript'

const root = fileURLToPath(new URL('../', import.meta.url))
const source = JSON.parse(await readFile(path.join(root, 'contracts/frontend/source.v1.json'), 'utf8'))
const workspace = JSON.parse(await readFile(path.join(root, 'package.json'), 'utf8'))
const adminPackage = JSON.parse(await readFile(path.join(root, 'web/admin/package.json'), 'utf8'))
const userPackage = JSON.parse(await readFile(path.join(root, 'web/user/package.json'), 'utf8'))
const ownership = JSON.parse(await readFile(path.join(root, 'contracts/frontend/ownership.v1.json'), 'utf8'))
const expectedPaths = { admin: 'web/admin', user: 'web/user', shared: 'web/shared', sdk: 'gateway/packages/core-sdk' }
if (ownership.schema !== 'frontend-ownership/v1' || ownership.repository !== 'TXBoard'
    || ownership.independent_repository !== false
    || JSON.stringify(ownership.paths) !== JSON.stringify(expectedPaths)
    || !Object.values(expectedPaths).every(name => workspace.workspaces.includes(name))) {
  throw new Error('Frontend ownership must stay in the TXBoard source workspace')
}
for (const definition of [workspace, adminPackage, userPackage]) {
  for (const version of Object.values({ ...definition.dependencies, ...definition.devDependencies })) {
    if (typeof version === 'string' && /(?:TX-F|\.\.\/|\.\.\\)/i.test(version)) {
      throw new Error('Frontend cannot depend on an independent repository checkout')
    }
  }
}
if (!workspace.workspaces.includes('gateway/packages/core-sdk')
    || adminPackage.dependencies['@txboard/core-sdk'] !== '*'
    || userPackage.dependencies['@txboard/core-sdk'] !== '*') throw new Error('Frontend must use the same-repository SDK workspace')
const digest = data => createHash('sha256').update(data).digest('hex')
const changed = []
for (const file of source.files) {
  if (digest(await readFile(path.join(root, file.path))) !== file.sha256) changed.push(file.path)
}
const visited = new Set()
async function walk(file) {
  file = path.resolve(file)
  if (visited.has(file)) return
  visited.add(file)
  const relative = path.relative(root, file).replaceAll('\\', '/')
  if (/\/(plugins|api)\/.+/.test(relative) && !relative.endsWith('/api/comm.ts')) throw new Error('Dormant adapter is reachable: ' + relative)
  if (relative.endsWith('/lib/storage.ts')) throw new Error('Legacy token storage is reachable')
  const adapted = new Set(['SignInPage.tsx', 'NotFoundPage.tsx', 'users/UsersPage.tsx', 'users/UserDetailPage.tsx', 'finance/OrdersPage.tsx']
    .map(name => 'web/admin/src/pages/' + name))
  if (relative.includes('/src/pages/') && !adapted.has(relative)) throw new Error('Unadapted page is reachable: ' + relative)
  const text = await readFile(file, 'utf8')
  const ast = ts.createSourceFile(file, text, ts.ScriptTarget.Latest, true)
  const imports = []
  function visit(node) {
    if (ts.isImportDeclaration(node) && !node.importClause?.isTypeOnly && ts.isStringLiteral(node.moduleSpecifier)) {
      imports.push(node.moduleSpecifier.text)
    }
    if (ts.isCallExpression(node) && node.expression.kind === ts.SyntaxKind.ImportKeyword && ts.isStringLiteral(node.arguments[0])) {
      imports.push(node.arguments[0].text)
    }
    ts.forEachChild(node, visit)
  }
  visit(ast)
  for (const name of imports.filter(name => name.startsWith('.') && !name.endsWith('.css'))) {
    const base = path.resolve(path.dirname(file), name)
    const candidates = [base, base.replace(/\.js$/, '.ts'), base + '.ts', base + '.tsx', path.join(base, 'index.ts')]
    let resolved
    for (const candidate of candidates) {
      try { if ((await stat(candidate)).isFile()) { resolved = candidate; break } } catch {}
    }
    if (!resolved) throw new Error('Unresolved source import: ' + file + ': ' + name)
    await walk(resolved)
  }
}
await walk(path.join(root, 'web/admin/src/main.tsx'))
const sdkEntry = await readFile(path.join(root, 'web/admin/src/native/api.ts'), 'utf8')
if (!sdkEntry.includes('@txboard/core-sdk')) throw new Error('SDK consumer entry is missing')
console.log(JSON.stringify({ sourceCommit: source.sourceCommit, copiedFiles: source.files.length,
  unchangedFiles: source.files.length - changed.length, adaptedFiles: changed,
  reachableSourceModules: visited.size, legacyRuntimeReachable: false, sameRepositorySdk: true,
  activeRepository: ownership.repository, independentRepository: ownership.independent_repository }, null, 2))
