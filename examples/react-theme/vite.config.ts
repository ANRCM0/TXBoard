import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  server: {
    port: 5174,
    // Same-origin proxy during development so the Origin header always
    // matches; the theme never contacts Laravel directly.
    proxy: {
      '/gateway/v1': {
        target: process.env.VITE_GATEWAY_PROXY_TARGET || 'http://127.0.0.1:8080',
        changeOrigin: false,
      },
    },
  },
})
