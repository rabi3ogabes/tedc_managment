import { createSyncStoragePersister } from '@tanstack/query-sync-storage-persister'
import { QueryClient } from '@tanstack/react-query'
import { PersistQueryClientProvider } from '@tanstack/react-query-persist-client'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import '@/i18n'
import './index.css'
import App from './App'
import DialogHost from '@/components/ui/DialogHost'
import ToastHost from '@/components/ui/ToastHost'
import { registerLusail } from '@/lib/fonts'
import { AuthProvider } from '@/lib/auth'
import { ThemeProvider } from '@/lib/ThemeProvider'

import i18n from '@/i18n'
import { api } from '@/lib/api'
import { installErrorReporting } from '@/lib/errorReporter'
import AppErrorBoundary, { installStaleCodeRecovery } from '@/components/AppErrorBoundary'
import { bootLabels } from '@/lib/labels'

const queryClient = new QueryClient({
  defaultOptions: { queries: { staleTime: 60_000, gcTime: 24 * 60 * 60_000, retry: 1, refetchOnWindowFocus: false } },
})

// Public website data is remembered between visits: pages render instantly from the last copy while a fresh
// one loads. Personal data (/me, /admin) is never written to the browser's storage.
const persister = createSyncStoragePersister({ storage: window.localStorage, key: 'tedc.cache', throttleTime: 2000 })
const persistOptions = {
  persister,
  maxAge: 24 * 60 * 60_000,
  buster: __BUILD_ID__,
  dehydrateOptions: {
    shouldDehydrateQuery: (q: { queryKey: readonly unknown[]; state: { status: string } }) =>
      q.state.status === 'success' && String(q.queryKey[0] ?? '').startsWith('/public/'),
  },
}

// A tab opened before a new release can hold stale code: recover instead of showing a white screen.
installStaleCodeRecovery()
installErrorReporting()

// Names of menus and buttons set by the administrators (Settings → Labels).
bootLabels()

// Start loading the home page data in parallel with the application code (no request waterfall).
if (window.location.pathname === '/') {
  const lang = i18n.language === 'en' ? 'en' : 'ar'
  void queryClient.prefetchQuery({ queryKey: ['/public/home', undefined, lang], queryFn: async () => (await api.get('/public/home')).data })
}

void registerLusail()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <PersistQueryClientProvider client={queryClient} persistOptions={persistOptions}>
      <BrowserRouter>
        <ThemeProvider>
          <AuthProvider>
            <AppErrorBoundary><App /></AppErrorBoundary>
          </AuthProvider>
          <ToastHost />
          <DialogHost />
        </ThemeProvider>
      </BrowserRouter>
    </PersistQueryClientProvider>
  </StrictMode>,
)
