import { loadEnv } from 'vite'
import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  return {
    base: env.VITE_BASE_PATH || '/admin/',
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
        '/gateway': {
          target: env.VITE_GATEWAY_TARGET || 'http://127.0.0.1:8080',
          changeOrigin: true,
        },
      },
    },
  }
})
