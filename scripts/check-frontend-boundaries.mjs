import { readFile, stat } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import ts from 'typescript'

const root = fileURLToPath(new URL('../', import.meta.url))
const workspace = JSON.parse(await readFile(path.join(root, 'package.json'), 'utf8'))
const ownership = JSON.parse(await readFile(path.join(root, 'contracts/frontend/ownership.v1.json'), 'utf8'))
const expectedPaths = { admin: 'web/admin', user: 'web/user', shared: 'web/shared', sdk: 'gateway/packages/core-sdk' }
for (const version of Object.values({ ...workspace.dependencies, ...workspace.devDependencies })) {
  if (typeof version === 'string' && /(?:TX-F|\.\.\/|\.\.\\)/i.test(version)) throw new Error('Root workspace cannot depend on an independent repository checkout')
}
if (ownership.schema !== 'frontend-ownership/v1' || ownership.repository !== 'TXBoard'
    || ownership.independent_repository !== false
    || JSON.stringify(ownership.paths) !== JSON.stringify(expectedPaths)
    || !Object.values(expectedPaths).every(name => workspace.workspaces.includes(name))) {
  throw new Error('Frontend ownership must stay in the TXBoard source workspace')
}
for (const directory of ['web/admin', 'web/user']) {
  const definition = JSON.parse(await readFile(path.join(root, directory, 'package.json'), 'utf8'))
  if (definition.dependencies['@txboard/core-sdk'] !== '*' || definition.dependencies['@txboard/shared'] !== '*') {
    throw new Error(directory + ' must consume the same-repository SDK and shared workspace')
  }
  for (const version of Object.values({ ...definition.dependencies, ...definition.devDependencies })) {
    if (typeof version === 'string' && /(?:TX-F|\.\.\/|\.\.\\)/i.test(version)) {
      throw new Error('Frontend cannot depend on an independent repository checkout')
    }
  }
}
const allowedAdminPages = new Set(['SignInPage.tsx', 'NotFoundPage.tsx', 'users/UsersPage.tsx',
  'users/UserDetailPage.tsx', 'finance/OrdersPage.tsx'].map(name => 'web/admin/src/pages/' + name))
const visited = new Set()
async function resolveImport(file, name) {
  if (name === '@txboard/shared') return path.join(root, 'web/shared/src/index.ts')
  if (!name.startsWith('.')) return undefined
  const base = path.resolve(path.dirname(file), name.split('?')[0])
  if (![path.join(root, 'web') + path.sep, path.join(root, 'contracts') + path.sep].some(prefix => base.startsWith(prefix))) throw new Error('Frontend relative import escapes its workspace: ' + name)
  for (const candidate of [base, base.replace(/\.js$/, '.ts'), base + '.ts', base + '.tsx', base + '.vue', base + '.css',
    path.join(base, 'index.ts'), path.join(base, 'index.tsx')]) {
    try { if ((await stat(candidate)).isFile()) return candidate } catch { /* Check the next supported source extension. */ }
  }
  throw new Error('Unresolved source import: ' + file + ': ' + name)
}
async function walk(file) {
  file = path.resolve(file)
  if (visited.has(file)) return
  visited.add(file)
  const relative = path.relative(root, file).replaceAll('\\', '/')
  if (/\/(?:plugins|api)\/.+/.test(relative) && relative !== 'web/admin/src/api/comm.ts') throw new Error('Legacy adapter is reachable: ' + relative)
  if (relative.endsWith('/lib/storage.ts') || relative.includes('/stores/')) throw new Error('Legacy persisted session is reachable: ' + relative)
  if (relative.startsWith('web/admin/src/pages/') && !relative.endsWith('.css') && !allowedAdminPages.has(relative)) {
    throw new Error('Unadapted administrator page is reachable: ' + relative)
  }
  if (relative.startsWith('web/user/src/pages/')) throw new Error('Unadapted user page is reachable: ' + relative)
  const text = await readFile(file, 'utf8')
  const imports = []
  if (file.endsWith('.css')) {
    for (const match of text.matchAll(/@import\s+(?:url\()?['"]([^'"]+)/g)) imports.push(match[1])
  } else if (/\.(?:ts|tsx|vue)$/.test(file)) {
    const source = file.endsWith('.vue') ? [...text.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)].map(match => match[1]).join('\n') : text
    const ast = ts.createSourceFile(file, source, ts.ScriptTarget.Latest, true)
    function visit(node) {
      if ((ts.isImportDeclaration(node) || ts.isExportDeclaration(node)) && node.moduleSpecifier && ts.isStringLiteral(node.moduleSpecifier)) imports.push(node.moduleSpecifier.text)
      if (ts.isCallExpression(node) && node.expression.kind === ts.SyntaxKind.ImportKeyword) {
        if (!ts.isStringLiteral(node.arguments[0])) throw new Error('Unbounded dynamic frontend import: ' + relative)
        imports.push(node.arguments[0].text)
      }
      ts.forEachChild(node, visit)
    }
    visit(ast)
  }
  for (const name of imports) {
    if (name === 'axios') throw new Error('Uncontrolled HTTP adapter is reachable: ' + relative)
    const resolved = await resolveImport(file, name)
    if (resolved) await walk(resolved)
  }
}
await walk(path.join(root, 'web/admin/src/main.tsx'))
await walk(path.join(root, 'web/user/src/main.ts'))
for (const entry of ['web/admin/src/native/api.ts', 'web/user/src/native/session.ts']) {
  if (!(await readFile(path.join(root, entry), 'utf8')).includes('@txboard/core-sdk')) throw new Error('SDK consumer entry is missing: ' + entry)
}
console.log(JSON.stringify({ check: 'frontend source boundaries', reachableSourceModules: visited.size,
  legacyRuntimeReachable: false, sameRepositorySdk: true, activeRepository: ownership.repository,
  independentRepository: ownership.independent_repository }, null, 2))
