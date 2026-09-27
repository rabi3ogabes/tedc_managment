import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig, loadEnv } from 'vite'

// On Vercel the API address must be explicit: /api/v1 when the API runs on Vercel too (the root vercel.json
// sets it), or an absolute URL when it runs elsewhere. Fail early instead of shipping an app whose requests 404.
const env = loadEnv(process.env.NODE_ENV ?? 'production', process.cwd(), 'VITE_')
if (process.env.VERCEL && !env.VITE_API_URL) {
  throw new Error('Set VITE_API_URL in Vercel → Settings → Environment Variables (see docs/DEPLOY-VERCEL.md).')
}

export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: { alias: { '@': '/src' } },
  server: {
    port: 5173,
    proxy: {
      '/api': { target: process.env.VITE_API_PROXY ?? 'http://localhost:8000', changeOrigin: true },
    },
  },
})
