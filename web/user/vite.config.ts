import { loadEnv } from 'vite'
import { defineConfig } from 'vitest/config'
import vue from '@vitejs/plugin-vue'
import path from 'node:path'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  return {
    base: env.VITE_BASE_PATH || '/',
    plugins: [vue()],
    resolve: {
      alias: { '@': path.resolve(__dirname, 'src') },
    },
    test: {
      environment: 'jsdom',
      setupFiles: ['./src/test/setup.ts'],
      include: ['src/**/*.test.ts'],
    },
    server: {
      port: Number(env.VITE_USER_PORT || 5173),
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
