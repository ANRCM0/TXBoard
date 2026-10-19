import { loadEnv } from 'vite'
import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'
import { coreBrowserProxy } from '../dev-core-proxy'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  return {
    base: env.VITE_BASE_PATH || '/admin/',
    plugins: [react()],
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
