import clsx from 'clsx'
import { ArrowLeft, ArrowRight, Bot, CircleCheck, Download, Headset, MessagesSquare, Search, Send, ShieldAlert, Sparkles, UserRound } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'

type Row = { id: string; name: string | null; email: string | null; is_member: boolean; mode: 'bot' | 'human'; status: 'open' | 'closed'; needs_human: boolean; unread: number; messages: number; last_at: string | null; preview: string | null; last_sender: string | null }
type Line = { id: string; sender: 'visitor' | 'bot' | 'admin'; body: string; created_at: string; by: string | null; refused: boolean; source: string | null; program_codes: string[] }
type Detail = Row & { thread: Line[]; created_at: string; locale: string; device: string | null }
type List = { summary: { unread: number; needs_human: number; open: number; total: number }; data: { data: Row[] } | Row[] }

const FILTERS = ['all', 'needs_human', 'unread', 'open', 'closed'] as const

function ago(iso: string | null, t: (k: string, o?: Record<string, unknown>) => string) {
  if (!iso) return ''
  const s = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000))
  if (s < 60) return t('mgmt.live.ago.now')
  if (s < 3600) return t('mgmt.live.ago.min', { n: Math.round(s / 60) })
  if (s < 86400) return t('mgmt.live.ago.hour', { n: Math.round(s / 3600) })
  return fmt.date(iso, { day: 'numeric', month: 'short' })
}

/** Chat inbox: every conversation of the website assistant with its full history — answer manually or hand back to the bot. */
export default function ChatInbox() {
  const { t, i18n } = useTranslation()
  const [filter, setFilter] = useState<(typeof FILTERS)[number]>('all')
  const [q, setQ] = useState('')
  const [selected, setSelected] = useState<string | null>(null)
  const list = useGet<List>('/admin/chats', { filter, q: q || undefined }, { refetchInterval: 8000, staleTime: 0 })
  const rows: Row[] = Array.isArray(list.data?.data) ? list.data.data : (list.data?.data as { data: Row[] } | undefined)?.data ?? []
  const summary = list.data?.summary
  const Back = i18n.dir() === 'rtl' ? ArrowRight : ArrowLeft

  return (
    <div>
      <PageHeader
        title={<span className="flex items-center gap-3"><span className="grid size-11 place-items-center rounded-2xl bg-navy-900 text-gold-300"><MessagesSquare className="size-5" /></span>{t('mgmt.chat.title')}</span>}
        subtitle={t('mgmt.chat.subtitle')}
      />
      {summary && (
        <div className="mb-5 grid gap-3 sm:grid-cols-4">
          {[{ l: t('mgmt.chat.stats.needs'), v: summary.needs_human, tone: summary.needs_human ? 'border-gold-400 bg-gold-100/50' : 'border-navy-100 bg-white' }, { l: t('mgmt.chat.stats.unread'), v: summary.unread, tone: 'border-navy-100 bg-white' }, { l: t('mgmt.chat.stats.open'), v: summary.open, tone: 'border-navy-100 bg-white' }, { l: t('mgmt.chat.stats.total'), v: summary.total, tone: 'border-navy-100 bg-white' }].map((s) => (
            <div key={s.l} className={clsx('rounded-2xl border p-4', s.tone)}><div className="text-xs font-semibold text-slate-500">{s.l}</div><div className="mt-1 text-2xl font-extrabold text-navy-900">{fmt.number(s.v)}</div></div>
          ))}
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-[22rem_minmax(0,1fr)]">
        <Card padded={false} className={clsx('flex max-h-[calc(100vh-14rem)] min-h-[28rem] flex-col', selected && 'max-lg:hidden')}>
          <div className="space-y-3 border-b border-navy-100 p-3">
            <div className="relative"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input !ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('mgmt.chat.search')} aria-label={t('mgmt.chat.search')} /></div>
            <div role="tablist" className="flex flex-wrap gap-1.5">
              {FILTERS.map((f) => <button key={f} type="button" role="tab" aria-selected={filter === f} onClick={() => setFilter(f)} className={clsx('rounded-full px-3 py-1 text-xs font-bold ring-1 ring-inset transition', filter === f ? 'bg-navy-900 text-white ring-navy-900' : 'bg-white text-slate-600 ring-navy-100 hover:ring-gold-400')}>{t(`mgmt.chat.filters.${f}`)}</button>)}
            </div>
          </div>
          <ul className="min-h-0 flex-1 divide-y divide-navy-100 overflow-y-auto">
            {list.isLoading ? <li className="p-6"><Spinner /></li> : rows.length === 0 ? <li className="p-6"><Empty text={t('mgmt.chat.empty')} /></li> : rows.map((r) => (
              <li key={r.id}>
                <button type="button" onClick={() => setSelected(r.id)} className={clsx('flex w-full items-start gap-3 px-4 py-3 text-start transition hover:bg-ivory', selected === r.id && 'bg-gold-100/40')}>
                  <span className={clsx('grid size-10 shrink-0 place-items-center rounded-full text-sm font-extrabold', r.needs_human ? 'bg-gold-500 text-navy-950' : 'bg-navy-900 text-gold-300')}>{(r.name ?? '?').slice(0, 1)}</span>
                  <span className="min-w-0 flex-1">
                    <span className="flex items-center gap-2"><span className="truncate font-bold text-navy-900">{r.name ?? t('mgmt.chat.visitor')}</span><span className="ms-auto shrink-0 text-[11px] text-slate-400">{ago(r.last_at, t)}</span></span>
                    <span className="mt-0.5 block truncate text-xs text-slate-500">{r.last_sender === 'visitor' ? '' : r.last_sender === 'admin' ? `${t('mgmt.chat.you')}: ` : '🤖 '}{r.preview ?? '—'}</span>
                    <span className="mt-1.5 flex flex-wrap items-center gap-1.5">
                      {r.needs_human && <Badge color="gold">{t('mgmt.chat.needsTeam')}</Badge>}
                      {r.mode === 'human' && !r.needs_human && <Badge color="navy">{t('mgmt.chat.teamHandling')}</Badge>}
                      {r.status === 'closed' && <Badge color="gray">{t('mgmt.chat.closed')}</Badge>}
                      {r.is_member && <Badge color="green">{t('mgmt.chat.member')}</Badge>}
                      {r.unread > 0 && <span className="ms-auto rounded-full bg-danger px-1.5 text-[10px] font-bold text-white">{r.unread}</span>}
                    </span>
                  </span>
                </button>
              </li>
            ))}
          </ul>
        </Card>

        {selected ? <Thread id={selected} onBack={() => setSelected(null)} Back={Back} onChange={() => void list.refetch()} /> : (
          <Card className="hidden min-h-[28rem] place-items-center lg:grid"><div className="text-center text-slate-400"><MessagesSquare className="mx-auto size-12 text-navy-200" /><p className="mt-3 font-semibold">{t('mgmt.chat.pick')}</p></div></Card>
        )}
      </div>
    </div>
  )
}

function Thread({ id, onBack, Back, onChange }: { id: string; onBack: () => void; Back: typeof ArrowLeft; onChange: () => void }) {
  const { t } = useTranslation()
  const { data, refetch } = useGet<{ data: Detail }>(`/admin/chats/${id}`, undefined, { refetchInterval: 4000, staleTime: 0 })
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const end = useRef<HTMLDivElement>(null)
  const c = data?.data
  useEffect(() => { end.current?.scrollIntoView({ block: 'end' }) }, [c?.thread.length, id])

  const act = async (call: () => Promise<unknown>) => { setBusy(true); setError(null); try { await call(); await refetch(); onChange() } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) } }
  const reply = () => { const body = text.trim(); if (!body) return; setText(''); void act(() => api.post(`/admin/chats/${id}/messages`, { body })) }
  const quick = t('mgmt.chat.quick', { returnObjects: true }) as string[]

  if (!c) return <Card className="grid min-h-[28rem] place-items-center"><Spinner /></Card>
  return (
    <Card padded={false} className="flex max-h-[calc(100vh-14rem)] min-h-[28rem] flex-col">
      <div className="flex flex-wrap items-center gap-3 border-b border-navy-100 px-4 py-3">
        <button type="button" onClick={onBack} aria-label={t('common.back')} className="grid size-9 place-items-center rounded-lg text-slate-500 hover:bg-navy-100/60 lg:hidden"><Back className="size-5" /></button>
        <span className="grid size-10 place-items-center rounded-full bg-navy-900 font-extrabold text-gold-300">{(c.name ?? '?').slice(0, 1)}</span>
        <div className="min-w-0 flex-1"><div className="truncate font-bold text-navy-900">{c.name ?? t('mgmt.chat.visitor')}</div><div className="truncate text-xs text-slate-500" dir="ltr">{c.email ?? '—'} · {fmt.dateTime(c.created_at)}</div></div>
        <div role="group" className="inline-flex rounded-xl border border-navy-100 p-1 text-xs font-bold" aria-label={t('mgmt.chat.handling')}>
          {(['bot', 'human'] as const).map((m) => (
            <button key={m} type="button" aria-pressed={c.mode === m} disabled={busy} onClick={() => c.mode !== m && void act(() => api.post(`/admin/chats/${id}/mode`, { mode: m }))} className={clsx('inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 transition', c.mode === m ? 'bg-navy-900 text-white' : 'text-slate-500')}>
              {m === 'bot' ? <Bot className="size-3.5" /> : <Headset className="size-3.5" />}{t(`mgmt.chat.modes.${m}`)}
            </button>
          ))}
        </div>
        <Button size="sm" variant="outline" icon={<CircleCheck className="size-4" />} loading={busy} onClick={() => void act(() => api.post(`/admin/chats/${id}/status`, { status: c.status === 'open' ? 'closed' : 'open' }))}>{c.status === 'open' ? t('mgmt.chat.close') : t('mgmt.chat.reopen')}</Button>
        <Button size="sm" variant="ghost" icon={<Download className="size-4" />} onClick={() => downloadFile(`/admin/chats/${id}/export`, `chat-${id}.csv`)} aria-label={t('mgmt.live.exportCsv')} />
      </div>

      <div className="min-h-0 flex-1 space-y-3 overflow-y-auto bg-ivory/60 px-5 py-4">
        {c.thread.map((m) => {
          const visitor = m.sender === 'visitor', admin = m.sender === 'admin'
          return (
            <div key={m.id} className={clsx('flex flex-col gap-1', admin ? 'items-end' : 'items-start')}>
              <span className="inline-flex items-center gap-1.5 text-[11px] font-semibold text-slate-400">
                {visitor ? <UserRound className="size-3" /> : admin ? <Headset className="size-3 text-gold-600" /> : <Sparkles className="size-3 text-navy-700" />}
                {visitor ? (c.name ?? t('mgmt.chat.visitor')) : admin ? (m.by ?? t('mgmt.chat.team')) : t('mgmt.chat.assistant')} · {fmt.time(m.created_at)}
                {m.refused && <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-amber-700"><ShieldAlert className="size-3" />{t('mgmt.chat.refused')}</span>}
                {m.sender === 'bot' && m.source && <span className="rounded-full bg-navy-100/70 px-2 py-0.5 text-[10px] uppercase">{m.source}</span>}
              </span>
              <div className={clsx('max-w-[85%] whitespace-pre-line rounded-2xl px-4 py-2.5 text-sm leading-relaxed shadow-sm', visitor ? 'rounded-es-md bg-white ring-1 ring-navy-100' : admin ? 'rounded-ee-md bg-navy-900 text-white' : 'rounded-es-md bg-gold-100/60 ring-1 ring-gold-300/60')}>{m.body}</div>
            </div>
          )
        })}
        <div ref={end} />
      </div>

      {error && <div role="alert" className="mx-4 mt-2 rounded-xl bg-red-50 px-3 py-2 text-xs font-semibold text-danger">{error}</div>}
      <div className="border-t border-navy-100 bg-white p-3">
        <div className="mb-2 flex flex-wrap gap-1.5">{quick.map((qr) => <button key={qr} type="button" onClick={() => setText((v) => (v ? `${v} ${qr}` : qr))} className="rounded-full border border-navy-100 bg-ivory px-3 py-1 text-xs font-semibold text-navy-800 hover:border-gold-400">{qr}</button>)}</div>
        <div className="flex items-end gap-2">
          <textarea rows={2} value={text} maxLength={2000} onChange={(e) => setText(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); reply() } }} placeholder={t('mgmt.chat.reply')} aria-label={t('mgmt.chat.reply')} className="input min-h-14 flex-1 resize-none" />
          <Button variant="gold" icon={<Send className="size-4 rtl:-scale-x-100" />} loading={busy} disabled={!text.trim()} onClick={reply}>{t('mgmt.chat.send')}</Button>
        </div>
        <p className="mt-1.5 text-[11px] text-slate-400">{c.mode === 'bot' ? t('mgmt.chat.takeoverHint') : t('mgmt.chat.humanHint')} · Ctrl/⌘ + Enter</p>
      </div>
    </Card>
  )
}
