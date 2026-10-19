import { build } from 'esbuild'
import { mkdir, copyFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'

await mkdir('dist', { recursive: true })
await build({ entryPoints: ['src/main.ts'], bundle: true, outfile: 'dist/host.js', format: 'esm', target: 'es2022', sourcemap: true,
  alias: { '@txboard/core-sdk': fileURLToPath(new URL('../../packages/core-sdk/src/index.ts', import.meta.url)) } })
await copyFile('index.html', 'dist/index.html')
