import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig, loadEnv } from 'vite'

// On Vercel the web app is static and the Laravel API runs elsewhere (e.g. Railway):
// fail the build early instead of shipping an app whose requests all 404.
const env = loadEnv(process.env.NODE_ENV ?? 'production', process.cwd(), 'VITE_')
if (process.env.VERCEL && !/^https?:\/\//.test(env.VITE_API_URL ?? '')) {
  throw new Error('Set VITE_API_URL in Vercel → Settings → Environment Variables to the API address, e.g. https://<your-api>.up.railway.app/api/v1 (see docs/DEPLOY-VERCEL.md).')
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
