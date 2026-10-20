import path from 'node:path'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig, loadEnv } from 'vite'

// The SPA is served on the same host the API identifies the tenant from:
// http://localhost:5173 (central admin) and http://<tenant>.localhost:5173.
// /api and /sanctum are proxied to Laravel WITHOUT rewriting the Host header,
// so Laravel sees acme.localhost and resolves the tenant; cookies stay first-party.
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const target = env.VITE_API_PROXY_TARGET || 'http://127.0.0.1:8000'

  const proxy = { target, changeOrigin: false, xfwd: true }

  return {
    plugins: [react(), tailwindcss()],
    resolve: {
      alias: { '@': path.resolve(import.meta.dirname, './src') },
    },
    server: {
      port: 5173,
      strictPort: true,
      proxy: {
        '/api': proxy,
        '/sanctum': proxy,
      },
    },
  }
})
