/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Comma-separated central hosts; must match the backend CENTRAL_DOMAINS. */
  readonly VITE_CENTRAL_DOMAINS?: string
  /** Where Vite proxies /api and /sanctum in dev (default http://127.0.0.1:8000). */
  readonly VITE_API_PROXY_TARGET?: string
  /** Reverb (live Today board). Without a key the board polls every 30 s. */
  readonly VITE_REVERB_APP_KEY?: string
  readonly VITE_REVERB_HOST?: string
  readonly VITE_REVERB_PORT?: string
  readonly VITE_REVERB_SCHEME?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
