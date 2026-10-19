import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitest/config'

export default defineConfig({ resolve: { alias: { '@txboard/core-sdk': fileURLToPath(new URL('../../packages/core-sdk/src/index.ts', import.meta.url)) } } })
