import clsx from 'clsx'
import { CalendarDays, Headset, MessageCircle, RotateCcw, Send, ShieldCheck, Sparkles, X } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import type { Program } from '@/lib/types'

type Msg = { id: string; sender: 'visitor' | 'bot' | 'admin'; body: string; created_at: string; program_codes?: string[]; offer_human?: boolean; by?: string | null }
type Stored = { id: string; token: string }
type State = { mode: 'bot' | 'human'; status: 'open' | 'closed'; needs_human: boolean; messages: Msg[] }

const KEY = 'tedc.chat'
const read = (): Stored | null => { try { return JSON.parse(localStorage.getItem(KEY) ?? 'null') } catch { return null } }
const write = (v: Stored | null) => { try { if (v) localStorage.setItem(KEY, JSON.stringify(v)); else localStorage.removeItem(KEY) } catch { /* storage unavailable */ } }

/** A floating assistant: answers questions about the center's programs, and hands over to the team on request. */
export default function ChatWidget() {
  const { t, i18n } = useTranslation()
  const lang = i18n.language === 'en' ? 'en' : 'ar'
  const [open, setOpen] = useState(false)
  const [conv, setConv] = useState<Stored | null>(read)
  const [messages, setMessages] = useState<Msg[]>([])
  const [mode, setMode] = useState<'bot' | 'human'>('bot')
  const [closed, setClosed] = useState(false)
  const [text, setText] = useState('')
  const [sending, setSending] = useState(false)
  const [unread, setUnread] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const bottom = useRef<HTMLDivElement>(null)
  const input = useRef<HTMLTextAreaElement>(null)
  const lastAt = useRef<string | null>(null)
  const seenIds = useRef(new Set<string>())

  const merge = useCallback((incoming: Msg[]) => {
    const fresh = incoming.filter((m) => !seenIds.current.has(m.id))
    if (!fresh.length) return false
    fresh.forEach((m) => seenIds.current.add(m.id))
    lastAt.current = fresh[fresh.length - 1].created_at
    setMessages((cur) => [...cur, ...fresh])
    return fresh
  }, [])

  const apply = useCallback((s: State, notifyUnread: boolean) => {
    setMode(s.mode)
    setClosed(s.status === 'closed')
    const fresh = merge(s.messages)
    if (fresh && notifyUnread && fresh.some((m) => m.sender === 'admin')) setUnread(true)
  }, [merge])

  const reset = useCallback(() => { write(null); setConv(null); setMessages([]); seenIds.current.clear(); lastAt.current = null; setMode('bot'); setClosed(false); setUnread(false); setError(null) }, [])

  // Load / refresh the conversation: quickly while it is open, rarely while it is closed (to flag a team reply).
  const poll = useCallback(async (c: Stored, notify: boolean) => {
    try {
      const { data } = await api.get(`/public/chat/${c.id}`, { params: { token: c.token, after: lastAt.current ?? undefined } })
      apply(data.data, notify)
    } catch (e) {
      if ((e as { response?: { status?: number } }).response?.status === 404) reset()
    }
  }, [apply, reset])

  useEffect(() => {
    if (!conv) return
    void poll(conv, !open)
    const id = window.setInterval(() => document.visibilityState === 'visible' && void poll(conv, !open), open ? (mode === 'human' ? 4000 : 10000) : 30000)
    return () => window.clearInterval(id)
  }, [conv, open, mode, poll])

  useEffect(() => { const on = () => setOpen(true); window.addEventListener('tedc:chat-open', on); return () => window.removeEventListener('tedc:chat-open', on) }, [])
  useEffect(() => { if (open) { setUnread(false); setTimeout(() => input.current?.focus(), 150) } }, [open])
  useEffect(() => { bottom.current?.scrollIntoView({ behavior: 'smooth', block: 'end' }) }, [messages, sending, open])
  useEffect(() => { const esc = (e: KeyboardEvent) => e.key === 'Escape' && setOpen(false); window.addEventListener('keydown', esc); return () => window.removeEventListener('keydown', esc) }, [])

  const ensure = async (): Promise<Stored> => {
    if (conv) return conv
    const { data } = await api.post('/public/chat', { locale: lang })
    const c = { id: data.data.id as string, token: data.data.token as string }
    write(c); setConv(c)
    apply(data.data, false)
    return c
  }

  const send = async (raw?: string) => {
    const body = (raw ?? text).trim()
    if (!body || sending || closed) return
    setSending(true); setError(null); setText('')
    try {
      const c = await ensure()
      const { data } = await api.post(`/public/chat/${c.id}/messages`, { token: c.token, message: body, after: lastAt.current ?? undefined })
      apply(data.data, false)
    } catch (e) {
      setError(errorMessage(e)); setText(body)
    } finally { setSending(false) }
  }

  const askHuman = async () => {
    setSending(true); setError(null)
    try {
      const c = await ensure()
      const { data } = await api.post(`/public/chat/${c.id}/human`, { token: c.token, after: lastAt.current ?? undefined })
      apply(data.data, false)
    } catch (e) { setError(errorMessage(e)) } finally { setSending(false) }
  }

  const suggestions = t('chat.suggestions', { returnObjects: true }) as string[]
  const showSuggestions = messages.length <= 1 && !sending
  const greeting: Msg = { id: 'local-greeting', sender: 'bot', body: t('chat.greeting'), created_at: '' }
  const thread = messages.length ? messages : [greeting]

  return (
    <>
      {open && (
        <section role="dialog" aria-label={t('chat.title')} aria-modal="false"
          className="fixed inset-x-3 bottom-24 z-50 flex max-h-[min(640px,calc(100vh-7rem))] flex-col overflow-hidden rounded-3xl border border-white/60 bg-ivory shadow-[0_30px_80px_-20px_rgba(90,14,36,.55)] animate-fade-up sm:inset-x-auto sm:end-6 sm:w-[390px]">
          <header className="relative overflow-hidden bg-gradient-to-l from-navy-950 via-navy-900 to-navy-800 px-4 py-4 text-white">
            <div className="pattern-bg absolute inset-0 opacity-20" />
            <div className="relative flex items-center gap-3">
              <span className="relative grid size-11 shrink-0 place-items-center rounded-2xl bg-gold-500 text-navy-950 shadow-lg shadow-gold-500/30"><Sparkles className="size-5" /><span className="absolute -bottom-0.5 -end-0.5 size-3 rounded-full bg-emerald-400 ring-2 ring-navy-900" /></span>
              <div className="min-w-0 flex-1">
                <div className="font-display text-base font-bold leading-tight">{t('chat.title')}</div>
                <div className="truncate text-xs text-white/70">{mode === 'human' ? t('chat.teamMode') : t('chat.subtitle')}</div>
              </div>
              {conv && <button type="button" onClick={reset} title={t('chat.newChat')} aria-label={t('chat.newChat')} className="grid size-9 place-items-center rounded-xl text-white/70 hover:bg-white/10 hover:text-white"><RotateCcw className="size-4" /></button>}
              <button type="button" onClick={() => setOpen(false)} aria-label={t('chat.close')} className="grid size-9 place-items-center rounded-xl text-white/80 hover:bg-white/10 hover:text-white"><X className="size-5" /></button>
            </div>
          </header>

          <div className="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 py-4" aria-live="polite">
            {thread.map((m) => <Bubble key={m.id} m={m} onHuman={askHuman} humanMode={mode === 'human'} />)}
            {sending && <div className="flex"><span className="inline-flex gap-1 rounded-2xl rounded-es-md border border-navy-100 bg-white px-4 py-3" aria-label={t('chat.typing')}>{[0, 150, 300].map((d) => <span key={d} className="size-2 animate-bounce rounded-full bg-gold-500" style={{ animationDelay: `${d}ms` }} />)}</span></div>}
            {showSuggestions && (
              <div className="flex flex-wrap gap-2 pt-1">
                {suggestions.map((s) => <button key={s} type="button" onClick={() => void send(s)} className="rounded-full border border-gold-300 bg-white px-3.5 py-1.5 text-[13px] font-semibold text-navy-800 transition hover:border-gold-500 hover:bg-gold-100/60">{s}</button>)}
              </div>
            )}
            <div ref={bottom} />
          </div>

          {error && <div role="alert" className="mx-4 mb-2 rounded-xl bg-red-50 px-3 py-2 text-xs font-semibold text-danger">{error}</div>}
          {closed ? (
            <div className="border-t border-navy-100 bg-white p-4 text-center text-sm text-slate-500">{t('chat.closed')}<button type="button" onClick={reset} className="ms-2 font-bold text-link hover:underline">{t('chat.newChat')}</button></div>
          ) : (
            <form onSubmit={(e) => { e.preventDefault(); void send() }} className="border-t border-navy-100 bg-white p-3">
              <div className="flex items-end gap-2 rounded-2xl border border-navy-100 bg-ivory px-3 py-2 focus-within:border-gold-500 focus-within:ring-4 focus-within:ring-gold-100">
                <textarea ref={input} rows={1} value={text} maxLength={600} onChange={(e) => setText(e.target.value)} placeholder={t('chat.placeholder')} aria-label={t('chat.placeholder')}
                  onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); void send() } }}
                  className="max-h-28 min-h-6 flex-1 resize-none bg-transparent text-sm leading-6 text-ink outline-none placeholder:text-slate-400" />
                <button type="submit" disabled={!text.trim() || sending} aria-label={t('chat.send')} className="grid size-9 shrink-0 place-items-center rounded-xl bg-navy-900 text-gold-300 transition hover:bg-navy-800 disabled:opacity-40"><Send className="size-4 rtl:-scale-x-100" /></button>
              </div>
              <div className="mt-2 flex items-center justify-between gap-2 text-[11px] text-slate-400">
                <span className="inline-flex items-center gap-1"><ShieldCheck className="size-3.5" />{t('chat.disclaimer')}</span>
                {mode === 'bot' ? <button type="button" onClick={() => void askHuman()} disabled={sending} className="inline-flex items-center gap-1 font-bold text-link hover:underline"><Headset className="size-3.5" />{t('chat.human')}</button> : <span className="font-semibold text-emerald-600">{t('chat.teamHandling')}</span>}
              </div>
            </form>
          )}
        </section>
      )}

      <button type="button" onClick={() => setOpen((o) => !o)} aria-label={open ? t('chat.close') : t('chat.open')} aria-expanded={open}
        className="group fixed bottom-5 end-5 z-50 grid size-14 place-items-center rounded-full bg-gradient-to-br from-navy-800 to-navy-950 text-gold-300 shadow-[0_14px_40px_-10px_rgba(90,14,36,.7)] ring-2 ring-gold-400/50 transition hover:scale-105 hover:ring-gold-400">
        {open ? <X className="size-6" /> : <MessageCircle className="size-6" />}
        {!open && <span className="pointer-events-none absolute inset-0 rounded-full ring-2 ring-gold-400/40" style={{ animation: 'live-ping 2.4s ease-out 1.5s 3' }} />}
        {unread && !open && <span className="absolute -end-0.5 -top-0.5 size-4 rounded-full bg-gold-500 ring-2 ring-white" />}
      </button>
    </>
  )
}

function Bubble({ m, onHuman, humanMode }: { m: Msg; onHuman: () => void; humanMode: boolean }) {
  const { t } = useTranslation()
  const mine = m.sender === 'visitor'
  const team = m.sender === 'admin'
  return (
    <div className={clsx('flex flex-col gap-2', mine ? 'items-end' : 'items-start')}>
      {team && <span className="inline-flex items-center gap-1 text-[11px] font-bold text-gold-700"><Headset className="size-3" />{m.by ?? t('chat.team')}</span>}
      <div className={clsx('max-w-[88%] whitespace-pre-line rounded-2xl px-4 py-2.5 text-sm leading-relaxed shadow-sm',
        mine ? 'rounded-ee-md bg-navy-900 text-white' : team ? 'rounded-es-md border border-gold-300 bg-gold-100/70 text-navy-950' : 'rounded-es-md border border-navy-100 bg-white text-ink')}>{m.body}</div>
      {!!m.program_codes?.length && <div className="flex w-full max-w-[92%] flex-col gap-2">{m.program_codes.slice(0, 3).map((c) => <ProgramMini key={c} code={c} />)}</div>}
      {m.offer_human && !humanMode && <button type="button" onClick={onHuman} className="inline-flex items-center gap-1.5 rounded-full border border-navy-100 bg-white px-3 py-1.5 text-xs font-bold text-navy-800 hover:border-gold-400"><Headset className="size-3.5 text-gold-600" />{t('chat.human')}</button>}
    </div>
  )
}

function ProgramMini({ code }: { code: string }) {
  const { t } = useTranslation()
  const { data } = useGet<{ data: Program }>(`/public/programs/${code}`, undefined, { staleTime: 5 * 60_000 })
  const p = data?.data
  if (!p) return <div className="skeleton h-16 rounded-2xl" />
  return (
    <Link to={`/programs/${p.code}`} className="group flex items-center gap-3 rounded-2xl border border-navy-100 bg-white p-2.5 shadow-sm transition hover:-translate-y-0.5 hover:border-gold-400 hover:shadow-glass">
      <span className="grid size-12 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-navy-900 to-navy-700 text-gold-300"><CalendarDays className="size-5" /></span>
      <span className="min-w-0 flex-1"><span className="block truncate text-sm font-bold text-navy-900 group-hover:text-link">{p.title}</span>
        <span className="block truncate text-[11px] text-slate-500">{fmt.date(p.start_date, { day: 'numeric', month: 'short' })} · {fmt.number(p.total_hours)} {t('common.hours')}</span></span>
    </Link>
  )
}
