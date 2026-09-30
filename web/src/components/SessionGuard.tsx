import clsx from 'clsx'
import { AlertCircle, Eye, EyeOff, Loader2, LockKeyhole, LogOut, Timer } from 'lucide-react'
import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { Avatar } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'

const ACTIVE_KEY = 'tedc.active'
const LOCK_KEY = 'tedc.locked'
const HEARTBEAT_MS = 30_000
const WARN_SECONDS = 60

const read = (key: string) => { try { return localStorage.getItem(key) } catch { return null } }
const write = (key: string, value: string | null) => { try { if (value === null) localStorage.removeItem(key); else localStorage.setItem(key, value) } catch { /* storage unavailable */ } }

/**
 * Runs for every signed-in user of the website:
 *  - presence: a heartbeat with the page and the seconds since the last real input (feeds "Who is online now");
 *  - idle lock (administration team only): after the configured minutes without activity the dashboard is locked
 *    and the password is asked again. The server enforces it too, so an unattended tab cannot keep working.
 */
export default function SessionGuard() {
  const { user } = useAuth()
  if (!user) return null
  return <Guard key={user.id} />
}

function Guard() {
  const { t } = useTranslation()
  const { user, logout } = useAuth()
  const { pathname } = useLocation()
  const queryClient = useQueryClient()
  const [locked, setLocked] = useState(read(LOCK_KEY) === '1')
  const [lock, setLock] = useState<{ enabled: boolean; seconds: number }>({ enabled: false, seconds: 600 })
  const [warn, setWarn] = useState<number | null>(null)
  const lastActive = useRef(Date.now())
  const pathRef = useRef(pathname)
  pathRef.current = pathname

  const idleSeconds = () => Math.max(0, Math.round((Date.now() - Math.max(lastActive.current, Number(read(ACTIVE_KEY)) || 0)) / 1000))

  const lockNow = useCallback((notifyServer: boolean) => {
    setLocked(true)
    write(LOCK_KEY, '1')
    if (notifyServer) void api.post('/auth/lock').catch(() => undefined)
  }, [])

  const heartbeat = useCallback(async () => {
    try {
      const { data } = await api.post('/me/presence', { platform: 'web', path: pathRef.current, idle_seconds: idleSeconds() })
      if (data.data.lock) setLock(data.data.lock)
      if (data.data.locked) lockNow(false)
    } catch { /* offline: the next beat will do */ }
  }, [lockNow])

  // Real user input (not polling) keeps the session alive; shared between tabs through localStorage.
  useEffect(() => {
    let last = 0
    const onInput = () => {
      const now = Date.now()
      lastActive.current = now
      if (now - last > 5000) { last = now; write(ACTIVE_KEY, String(now)) }
    }
    const events = ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'wheel'] as const
    events.forEach((e) => window.addEventListener(e, onInput, { passive: true }))
    write(ACTIVE_KEY, String(Date.now()))
    return () => events.forEach((e) => window.removeEventListener(e, onInput))
  }, [])

  // Heartbeat every 30 s and whenever the page changes.
  useEffect(() => {
    void heartbeat()
    const id = window.setInterval(() => document.visibilityState === 'visible' && void heartbeat(), HEARTBEAT_MS)
    return () => window.clearInterval(id)
  }, [heartbeat])
  useEffect(() => { const id = window.setTimeout(() => void heartbeat(), 800); return () => window.clearTimeout(id) }, [pathname, heartbeat])

  // Idle detection + a warning shortly before locking.
  useEffect(() => {
    if (!lock.enabled || locked) { setWarn(null); return }
    const id = window.setInterval(() => {
      const left = lock.seconds - idleSeconds()
      if (left <= 0) { setWarn(null); lockNow(true) } else setWarn(left <= WARN_SECONDS ? left : null)
    }, 1000)
    return () => window.clearInterval(id)
  }, [lock, locked, lockNow])

  // The server refused a dashboard call because the session is locked; other tabs lock / unlock together.
  useEffect(() => {
    const onLocked = () => lockNow(false)
    const onStorage = (e: StorageEvent) => { if (e.key === LOCK_KEY) setLocked(e.newValue === '1') }
    window.addEventListener('tedc:locked', onLocked)
    window.addEventListener('storage', onStorage)
    return () => { window.removeEventListener('tedc:locked', onLocked); window.removeEventListener('storage', onStorage) }
  }, [lockNow])

  const unlocked = useCallback(() => {
    lastActive.current = Date.now()
    write(ACTIVE_KEY, String(Date.now()))
    write(LOCK_KEY, null)
    setLocked(false)
    void queryClient.invalidateQueries()
    void heartbeat()
  }, [heartbeat, queryClient])

  if (locked) return <LockScreen name={user!.name} email={user!.email} minutes={Math.round(lock.seconds / 60)} onUnlocked={unlocked} onSignOut={() => { write(LOCK_KEY, null); logout() }} />
  if (warn === null) return null
  return (
    <div role="status" className="fixed bottom-5 start-1/2 z-[90] flex -translate-x-1/2 items-center gap-3 rounded-2xl border border-gold-300 bg-navy-950/95 px-4 py-3 text-sm text-white shadow-2xl backdrop-blur rtl:translate-x-1/2">
      <span className="grid size-8 place-items-center rounded-full bg-gold-500 text-navy-950"><Timer className="size-4" /></span>
      <span>{t('mgmt.lock.warning', { seconds: warn })}</span>
    </div>
  )
}

function LockScreen({ name, email, minutes, onUnlocked, onSignOut }: { name: string; email: string; minutes: number; onUnlocked: () => void; onSignOut: () => void }) {
  const { t } = useTranslation()
  const [password, setPassword] = useState('')
  const [show, setShow] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [shake, setShake] = useState(0)
  const input = useRef<HTMLInputElement>(null)
  useEffect(() => { input.current?.focus() }, [])

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    if (!password || busy) return
    setBusy(true)
    setError(null)
    try {
      await api.post('/auth/unlock', { password })
      setPassword('')
      onUnlocked()
    } catch (err) {
      setError(errorMessage(err))
      setShake((n) => n + 1)
      setPassword('')
      input.current?.focus()
    } finally {
      setBusy(false)
    }
  }

  return (
    <div role="dialog" aria-modal="true" aria-labelledby="lock-title" className="fixed inset-0 z-[100] grid place-items-center bg-navy-950/90 p-4 backdrop-blur-2xl">
      <div className="pointer-events-none absolute -top-40 start-1/2 size-[36rem] -translate-x-1/2 rounded-full bg-gold-500/15 blur-3xl" />
      <form onSubmit={submit} key={shake} className={clsx('relative w-full max-w-sm rounded-3xl border border-white/10 bg-gradient-to-b from-white/15 to-white/5 p-7 text-center text-white shadow-2xl', shake > 0 && 'animate-[shake_.4s_ease]')}>
        <span className="mx-auto grid size-16 place-items-center rounded-full bg-gold-500 text-navy-950 shadow-lg shadow-gold-500/30"><LockKeyhole className="size-8" /></span>
        <h1 id="lock-title" className="mt-4 text-xl font-extrabold">{t('mgmt.lock.title')}</h1>
        <p className="mt-1 text-xs leading-relaxed text-white/70">{t('mgmt.lock.subtitle', { minutes })}</p>
        <div className="mt-5 flex items-center gap-3 rounded-2xl bg-white/10 p-3 text-start">
          <Avatar name={name} size={40} />
          <div className="min-w-0"><div className="truncate text-sm font-bold">{name}</div><div className="truncate text-xs text-white/60" dir="ltr">{email}</div></div>
        </div>
        <div className="relative mt-4">
          <input ref={input} type={show ? 'text' : 'password'} autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} placeholder={t('mgmt.lock.password')} aria-label={t('mgmt.lock.password')}
            className="w-full rounded-2xl border border-white/15 bg-white/10 py-3 pe-11 ps-4 text-white outline-none transition placeholder:text-white/40 focus:border-gold-400 focus:ring-4 focus:ring-gold-400/20" />
          <button type="button" onClick={() => setShow((v) => !v)} aria-label={show ? t('mgmt.lock.hide') : t('mgmt.lock.show')} className="absolute end-3 top-1/2 -translate-y-1/2 text-white/60 hover:text-white">{show ? <EyeOff className="size-5" /> : <Eye className="size-5" />}</button>
        </div>
        {error && <p role="alert" className="mt-3 flex items-center justify-center gap-1.5 text-sm font-semibold text-red-300"><AlertCircle className="size-4" />{error}</p>}
        <button type="submit" disabled={!password || busy} className="mt-4 flex w-full items-center justify-center gap-2 rounded-2xl bg-gold-500 py-3 font-bold text-navy-950 transition hover:bg-gold-400 disabled:opacity-50">
          {busy && <Loader2 className="size-4 animate-spin" />}{t('mgmt.lock.unlock')}
        </button>
        <button type="button" onClick={onSignOut} className="mt-3 inline-flex items-center gap-1.5 text-sm text-white/60 hover:text-white"><LogOut className="size-4" />{t('mgmt.lock.signOut')}</button>
      </form>
    </div>
  )
}
