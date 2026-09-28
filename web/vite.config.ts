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
  define: { __BUILD_ID__: JSON.stringify(process.env.VERCEL_GIT_COMMIT_SHA ?? String(Date.now())) },
  build: {
    target: 'es2022',
    cssCodeSplit: true,
    rollupOptions: {
      output: {
        // Long-lived vendor chunks (cached across deployments) and one icon chunk instead of dozens of tiny files.
        manualChunks(id) {
          if (!id.includes('node_modules')) return undefined
          if (/[\\/](react|react-dom|scheduler|react-router|react-router-dom)[\\/]/.test(id)) return 'react'
          if (id.includes('@tanstack')) return 'query'
          if (id.includes('i18next')) return 'i18n'
          if (id.includes('lucide-react')) return 'icons'
          if (id.includes('axios')) return 'http'
          return undefined
        },
      },
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': { target: process.env.VITE_API_PROXY ?? 'http://localhost:8000', changeOrigin: true },
    },
  },
})
