/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Comma-separated central hosts; must match the backend CENTRAL_DOMAINS. */
  readonly VITE_CENTRAL_DOMAINS?: string
  /** Where Vite proxies /api and /sanctum in dev (default http://127.0.0.1:8000). */
  readonly VITE_API_PROXY_TARGET?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
