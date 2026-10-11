/// <reference types="vite/client" />

interface ImportMetaEnv {
  readonly VITE_GATEWAY_BASE_URL?: string
  readonly VITE_ENCRYPTED_LOGIN?: string
  readonly VITE_GATEWAY_PROXY_TARGET?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
