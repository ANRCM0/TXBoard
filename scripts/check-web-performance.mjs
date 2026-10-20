import { readFileSync, readdirSync } from 'node:fs'
import { basename, dirname, join, resolve } from 'node:path'
import { gzipSync } from 'node:zlib'

const KB = 1024
const budgets = {
  initialJs: 150 * KB,
  routeJs: 110 * KB,
  css: 50 * KB,
  editorChunk: 450 * KB,
  editorTotal: 1200 * KB,
  editorWorker: 250 * KB,
  editorWorkers: 750 * KB,
}

function gzipSize(path) {
  return gzipSync(readFileSync(path), { level: 9 }).length
}

function format(bytes) {
  return (bytes / KB).toFixed(1) + ' KB'
}

function checkApp(name) {
  const dist = resolve('web', name, 'dist')
  const html = readFileSync(join(dist, 'index.html'), 'utf8')
  const scriptMatch = html.match(/<script[^>]+src="([^"]+.js)"/)
  if (!scriptMatch) throw new Error(name + ': module entry script was not found')

  const entryPath = resolve(dist, 'assets', basename(scriptMatch[1]))
  const assetsDir = dirname(entryPath)
  let initialGzip = gzipSize(entryPath)
  const cssFiles = readdirSync(assetsDir).filter(file => file.endsWith('.css'))
  const jsFiles = readdirSync(assetsDir).filter(file => file.endsWith('.js'))
  const editorFiles = new Set()
  const editorMeasurements = []
  if (name === 'admin') {
    const editor = JSON.parse(readFileSync(join(dist, 'editor-assets.json'), 'utf8'))
    const manifest = JSON.parse(readFileSync(join(dist, '.vite', 'manifest.json'), 'utf8'))
    if (editor.schema !== 'txboard-lazy-editor-assets/v1' || !editor.chunks.length || editor.workers.length !== 4) throw new Error('Missing local editor resource ownership')
    for (const file of [...editor.chunks, ...editor.workers]) {
      if (!/^assets\/[A-Za-z0-9_.-]+[.]js$/.test(file) || editorFiles.has(file)) throw new Error('Invalid editor resource manifest')
      editorFiles.add(file)
    }
    const entry = Object.entries(manifest).find(([, item]) => item.file === 'assets/' + basename(entryPath))?.[0]
    if (!entry) throw new Error('Missing admin build entry manifest')
    const visited = new Set()
    function visit(key) {
      if (visited.has(key)) return
      visited.add(key)
      const chunk = manifest[key]
      if (!chunk) throw new Error('Missing static dependency in build manifest')
      if (editorFiles.has(chunk.file)) throw new Error('Local editor must not be part of initial static imports')
      for (const dependency of chunk.imports ?? []) visit(dependency)
    }
    visit(entry)
    initialGzip = [...visited].reduce((total, key) => total + gzipSize(join(dist, manifest[key].file)), 0)
    const sizes = files => files.map(file => gzipSize(join(dist, file)))
    const chunkSizes = sizes(editor.chunks), workerSizes = sizes(editor.workers)
    editorMeasurements.push(['largest editor JS', Math.max(...chunkSizes), budgets.editorChunk],
      ['all editor JS', chunkSizes.reduce((sum, size) => sum + size, 0), budgets.editorTotal],
      ['largest worker JS', Math.max(...workerSizes), budgets.editorWorker],
      ['all worker JS', workerSizes.reduce((sum, size) => sum + size, 0), budgets.editorWorkers])
  }
  const largestCss = Math.max(0, ...cssFiles.map(file => gzipSize(join(assetsDir, file))))
  const largestRoute = Math.max(
    0,
    ...jsFiles
      .filter(file => file !== basename(entryPath) && !editorFiles.has('assets/' + file))
      .map(file => gzipSize(join(assetsDir, file))),
  )

  const measurements = [
    ['initial JS', initialGzip, budgets.initialJs],
    ['largest route JS', largestRoute, budgets.routeJs],
    ['largest CSS', largestCss, budgets.css],
    ...editorMeasurements,
  ]

  for (const [label, actual, budget] of measurements) {
    console.log(name.padEnd(5) + ' ' + label.padEnd(18) + ' ' + format(actual) + ' / ' + format(budget))
    if (actual > budget) {
      throw new Error(name + ': ' + label + ' exceeds its gzip budget')
    }
  }
}

for (const app of ['admin', 'user']) checkApp(app)
