/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_API_V2_PREFIX?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
