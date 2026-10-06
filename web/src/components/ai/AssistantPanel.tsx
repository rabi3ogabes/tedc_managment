/* eslint-disable @typescript-eslint/no-explicit-any */
import clsx from 'clsx'
import { Bot, Loader2, Plus, Send, ThumbsDown, ThumbsUp, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Button } from '@/components/ui'
import { useFeature } from '@/hooks/useFeature'
import { activeRoleStore } from '@/lib/activeRole'
import { API_URL, api, errorMessage, sessionStore } from '@/lib/api'
import { toast } from '@/lib/toast'

type Msg = { id?: string; role: 'user' | 'assistant'; content: string; citations?: any[]; meta?: any; feedback?: string | null }

/** The trainee's assistant: a side panel with streamed answers, sources, feedback and a hand-over to the trainer or support. */
export default function AssistantPanel({ portal }: { portal: boolean }) {
  const { t, i18n } = useTranslation()
  const enabled = useFeature('ai')
  const [open, setOpen] = useState(false)
  const [msgs, setMsgs] = useState<Msg[]>([])
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const [conv, setConv] = useState<string | null>(null)
  const endRef = useRef<HTMLDivElement>(null)
  useEffect(() => { endRef.current?.scrollIntoView({ behavior: 'smooth' }) }, [msgs, open])
  if (!enabled) return null

  const ask = async (q: string) => {
    const question = q.trim()
    if (!question || busy) return
    setText(''); setBusy(true)
    setMsgs((m) => [...m, { role: 'user', content: question }, { role: 'assistant', content: '' }])
    const patch = (fn: (m: Msg) => Msg) => setMsgs((all) => all.map((m, i) => (i === all.length - 1 ? fn(m) : m)))
    try {
      const session = sessionStore.get()
      const res = await fetch(`${API_URL}/me/assistant/messages`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream', ...(session ? { Authorization: `Bearer ${session.access_token}` } : {}), ...(activeRoleStore.get() ? { 'X-Active-Role': activeRoleStore.get()! } : {}), 'X-Locale': i18n.language === 'en' ? 'en' : 'ar' },
        body: JSON.stringify({ message: question, conversation_id: conv, stream: true }),
      })
      if (!res.ok || !res.body) throw new Error(String(res.status))
      const reader = res.body.getReader()
      const dec = new TextDecoder()
      let buf = ''
      let streamed = ''
      for (;;) {
        const { done, value } = await reader.read()
        if (done) break
        buf += dec.decode(value, { stream: true })
        const parts = buf.split('\n\n')
        buf = parts.pop() ?? ''
        for (const part of parts) {
          const isDone = part.startsWith('event: done')
          const line = part.split('\n').find((l) => l.startsWith('data: '))
          if (!line) continue
          const data = JSON.parse(line.slice(6))
          if (isDone) { setConv(data.conversation_id); patch(() => ({ ...data.message })) } else { streamed += data.delta; patch((m) => ({ ...m, content: streamed })) }
        }
      }
    } catch (e) {
      patch((m) => ({ ...m, content: String(t('aix.assistant.error')) + (e instanceof Error && e.message ? ` (${e.message})` : '') }))
    } finally { setBusy(false) }
  }

  const rate = async (m: Msg, feedback: 'up' | 'down') => {
    if (!m.id) return
    try { await api.post(`/me/assistant/messages/${m.id}/feedback`, { feedback }); setMsgs((all) => all.map((x) => (x.id === m.id ? { ...x, feedback } : x))); toast(String(t('aix.assistant.thanks'))) } catch (e) { toast(errorMessage(e), 'error') }
  }
  const escalate = async (m: Msg, target: 'trainer' | 'support', programId?: string) => {
    try { await api.post(`/me/assistant/messages/${m.id}/escalate`, { target, program_id: programId }); toast(String(t(target === 'trainer' ? 'aix.assistant.sentTrainer' : 'aix.assistant.sentSupport'))); setMsgs((all) => all.map((x) => (x.id === m.id ? { ...x, meta: { ...x.meta, escalate: null, escalated: target } } : x))) } catch (e) { toast(errorMessage(e), 'error') }
  }
  const suggestions = t('aix.assistant.suggestions', { returnObjects: true }) as unknown as string[]

  return (
    <>
      {!open && (
        <button type="button" onClick={() => setOpen(true)} aria-label={String(t('aix.assistant.open'))}
          className="fixed bottom-5 end-5 z-40 inline-flex items-center gap-2 rounded-full bg-navy-900 px-5 py-3 text-sm font-bold text-white shadow-glass transition hover:-translate-y-0.5 hover:bg-navy-800 print:hidden">
          <Bot className="size-5 text-gold-300" />{t('aix.assistant.open')}
        </button>
      )}
      {open && (
        <aside role="dialog" aria-label={String(t('aix.assistant.title'))} className="fixed inset-y-0 end-0 z-50 flex w-full max-w-md flex-col border-s border-navy-100 bg-white shadow-2xl print:hidden">
          <header className="flex items-center gap-3 bg-navy-950 px-5 py-4 text-white">
            <Bot className="size-6 text-gold-300" />
            <div className="min-w-0 flex-1"><div className="font-display font-bold">{t('aix.assistant.title')}</div><div className="truncate text-xs text-navy-200">{t('aix.assistant.subtitle')}</div></div>
            <button type="button" aria-label={String(t('aix.assistant.newChat'))} title={String(t('aix.assistant.newChat'))} onClick={() => { setMsgs([]); setConv(null) }} className="rounded-lg p-2 hover:bg-white/10"><Plus className="size-5" /></button>
            <button type="button" aria-label={String(t('aix.assistant.close'))} onClick={() => setOpen(false)} className="rounded-lg p-2 hover:bg-white/10"><X className="size-5" /></button>
          </header>
          <div className="flex-1 space-y-4 overflow-y-auto bg-ivory p-4" aria-live="polite">
            {msgs.length === 0 && (
              <div className="space-y-3 pt-6 text-center">
                <p className="text-sm text-slate-500">{t('aix.assistant.empty')}</p>
                <div className="flex flex-wrap justify-center gap-2">{suggestions.map((s) => <button key={s} type="button" onClick={() => ask(s)} className="rounded-full border border-navy-100 bg-white px-3 py-1.5 text-xs font-semibold text-navy-800 hover:border-gold-400">{s}</button>)}</div>
              </div>
            )}
            {msgs.map((m, i) => (
              <div key={i} className={clsx('flex', m.role === 'user' ? 'justify-end' : 'justify-start')}>
                <div className={clsx('max-w-[88%] rounded-2xl px-4 py-3 text-sm leading-relaxed', m.role === 'user' ? 'bg-navy-900 text-white' : 'border border-navy-100 bg-white text-ink')}>
                  {m.role === 'assistant' && !m.content && busy ? <span className="inline-flex items-center gap-2 text-slate-400"><Loader2 className="size-4 animate-spin" />{t('aix.assistant.thinking')}</span> : <p className="whitespace-pre-wrap">{m.content}</p>}
                  {m.citations && m.citations.length > 0 && (
                    <div className="mt-3 border-t border-navy-50 pt-2 text-xs">
                      <div className="mb-1 font-bold text-slate-500">{t('aix.assistant.sources')}</div>
                      <ul className="space-y-0.5">{m.citations.map((c: any) => <li key={c.n}><Link to={c.route.replace('/portal', portal ? '/portal' : '/portal')} onClick={() => setOpen(false)} className="text-link hover:underline">[{c.n}] {c.title}</Link></li>)}</ul>
                    </div>
                  )}
                  {m.role === 'assistant' && m.id && (
                    <div className="mt-2 flex items-center gap-1 text-slate-400">
                      <button type="button" aria-label={String(t('aix.assistant.helpful'))} aria-pressed={m.feedback === 'up'} onClick={() => rate(m, 'up')} className={clsx('rounded p-1 hover:text-emerald-600', m.feedback === 'up' && 'text-emerald-600')}><ThumbsUp className="size-4" /></button>
                      <button type="button" aria-label={String(t('aix.assistant.notHelpful'))} aria-pressed={m.feedback === 'down'} onClick={() => rate(m, 'down')} className={clsx('rounded p-1 hover:text-danger', m.feedback === 'down' && 'text-danger')}><ThumbsDown className="size-4" /></button>
                      {m.meta?.source && <span className="ms-auto text-[10px]">{t(m.meta.source === 'ai' ? 'aix.assistant.sourceAi' : 'aix.assistant.sourceRules')}</span>}
                    </div>
                  )}
                  {m.meta?.escalate && (
                    <div className="mt-3 rounded-xl bg-gold-50 p-3 text-xs">
                      <div className="mb-2 font-semibold text-gold-700">{t('aix.assistant.notSure')}</div>
                      <div className="flex flex-wrap gap-2">
                        {(m.meta.escalate.trainer_programs ?? []).map((p: any) => <Button key={p.id} size="sm" variant="outline" onClick={() => escalate(m, 'trainer', p.id)}>{t('aix.assistant.askTrainer')}: {i18n.language === 'en' ? p.title_en : p.title_ar}</Button>)}
                        <Button size="sm" variant="outline" onClick={() => escalate(m, 'support')}>{t('aix.assistant.askSupport')}</Button>
                      </div>
                    </div>
                  )}
                </div>
              </div>
            ))}
            <div ref={endRef} />
          </div>
          <form className="flex items-end gap-2 border-t border-navy-100 bg-white p-3" onSubmit={(e) => { e.preventDefault(); void ask(text) }}>
            <textarea className="input max-h-32 min-h-11 flex-1 resize-none" rows={1} value={text} onChange={(e) => setText(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); void ask(text) } }} placeholder={String(t('aix.assistant.placeholder'))} maxLength={2000} />
            <Button type="submit" variant="gold" loading={busy} disabled={!text.trim()} aria-label={String(t('aix.assistant.send'))}><Send className="size-4 rtl:rotate-180" /></Button>
          </form>
        </aside>
      )}
    </>
  )
}
