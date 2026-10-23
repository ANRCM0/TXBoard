import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'

export default defineConfig({
  plugins: [react()],
  test: { environment: 'jsdom', maxWorkers: 2, setupFiles: ['./src/test/setup.ts'],
    include: ['src/native/**/*.test.{ts,tsx}', 'src/components/**/*.test.{ts,tsx}', 'src/navigation/core.test.ts'] },
})
