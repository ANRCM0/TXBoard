import { loadEnv } from 'vite'
import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  return {
    base: env.VITE_BASE_PATH || '/',
    plugins: [react()],
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
        '/txapi': {
          target: env.VITE_PROXY_TARGET || 'http://127.0.0.1:7801',
          changeOrigin: true,
        },
        '/api': {
          target: env.VITE_PROXY_TARGET || 'http://127.0.0.1:7801',
          changeOrigin: true,
        },
      },
    },
  }
})
