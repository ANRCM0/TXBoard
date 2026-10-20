import { loadEnv } from 'vite'
import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'
import { coreBrowserProxy } from '../dev-core-proxy'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  return {
    base: env.VITE_BASE_PATH || '/admin/',
    plugins: [react(), {
      name: 'txboard-editor-resource-manifest',
      generateBundle(_options, bundle) {
        const chunks: string[] = [], workers: string[] = []
        for (const item of Object.values(bundle)) {
          if (item.type === 'chunk') {
            const modules = Object.keys(item.modules).map(id => id.replaceAll('\\', '/'))
            if (modules.some(id => id.includes('/node_modules/monaco-editor/'))
              && !modules.some(id => id.includes('/web/admin/src/'))) chunks.push(item.fileName)
          } else if (/^assets\/(editor|json|html|css)[.]worker-[a-zA-Z0-9_-]+[.]js$/.test(item.fileName)) workers.push(item.fileName)
        }
        const assets = new Set<string>(workers)
        function include(file: string) {
          if (assets.has(file)) return
          const item = bundle[file]
          if (!item) throw new Error('Missing local editor dependency: ' + file)
          assets.add(file)
          if (item.type === 'chunk') {
            for (const dependency of item.imports) include(dependency)
            const metadata = (item as typeof item & { viteMetadata?: { importedCss?: Set<string>; importedAssets?: Set<string> } }).viteMetadata
            for (const dependency of metadata?.importedCss ?? []) include(dependency)
            for (const dependency of metadata?.importedAssets ?? []) include(dependency)
          }
        }
        for (const file of chunks) include(file)
        this.emitFile({ type: 'asset', fileName: 'editor-assets.json', source: JSON.stringify({
          schema: 'txboard-lazy-editor-assets/v1', chunks: chunks.sort(), workers: workers.sort(), assets: [...assets].sort(),
        }) })
      },
    }],
    build: { manifest: true },
    // Keep the SDK's Zod 4 scoped to its workspace instead of sharing the
    // optimizer entry for the login forms' Zod 3 dependency.
    optimizeDeps: {
      include: ['@txboard/core-sdk'],
    },
    test: {
      environment: 'jsdom',
      // Avoid starving the router's dynamic import when the full suite runs under load.
      maxWorkers: 4,
      setupFiles: ['./src/test/setup.ts'],
      include: ['src/**/*.test.{ts,tsx}'],
    },
    server: {
      port: Number(env.VITE_PORT || 3001),
      proxy: {
        '/gateway': coreBrowserProxy(env.VITE_GATEWAY_TARGET || 'http://127.0.0.1:8080'),
      },
    },
  }
})
