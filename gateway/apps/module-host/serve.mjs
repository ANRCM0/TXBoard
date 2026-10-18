import { createServer } from 'node:http'
import { readFile } from 'node:fs/promises'
import { hostDocument } from './document.mjs'

const resources = new Map([['/', ['index.html', 'text/html']], ['/host.js', ['host.js', 'text/javascript']]])
createServer(async (request, response) => {
  const resource = resources.get(new URL(request.url, 'http://localhost').pathname)
  if (!resource) { response.writeHead(404).end(); return }
  try { response.writeHead(200, { 'Content-Type': resource[1], 'Cache-Control': 'no-store' }).end(resource[0] === 'index.html' ? await hostDocument() : await readFile(new URL(`dist/${resource[0]}`, import.meta.url))) }
  catch { response.writeHead(503).end('Build the acceptance host first.') }
}).listen(4317, '127.0.0.1', () => process.stdout.write('Module acceptance host: http://127.0.0.1:4317\n'))
