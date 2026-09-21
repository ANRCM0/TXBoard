import { readFileSync, readdirSync } from 'node:fs'
import { basename, dirname, join, resolve } from 'node:path'
import { gzipSync } from 'node:zlib'

const KB = 1024
const budgets = {
  initialJs: 150 * KB,
  routeJs: 110 * KB,
  css: 50 * KB,
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
  const entryGzip = gzipSize(entryPath)
  const cssFiles = readdirSync(assetsDir).filter(file => file.endsWith('.css'))
  const jsFiles = readdirSync(assetsDir).filter(file => file.endsWith('.js'))
  const largestCss = Math.max(0, ...cssFiles.map(file => gzipSize(join(assetsDir, file))))
  const largestRoute = Math.max(
    0,
    ...jsFiles
      .filter(file => file !== basename(entryPath))
      .map(file => gzipSize(join(assetsDir, file))),
  )

  const measurements = [
    ['initial JS', entryGzip, budgets.initialJs],
    ['largest route JS', largestRoute, budgets.routeJs],
    ['largest CSS', largestCss, budgets.css],
  ]

  for (const [label, actual, budget] of measurements) {
    console.log(name.padEnd(5) + ' ' + label.padEnd(18) + ' ' + format(actual) + ' / ' + format(budget))
    if (actual > budget) {
      throw new Error(name + ': ' + label + ' exceeds its gzip budget')
    }
  }
}

for (const app of ['admin', 'user']) checkApp(app)
