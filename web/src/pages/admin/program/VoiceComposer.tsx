import clsx from 'clsx'
import { AlertTriangle, AudioLines, Check, Keyboard, Languages, Mic, MicOff, Play, RotateCcw, Sparkles, X } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Field, Modal } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

/* The browser's speech recognition (Chrome, Edge, Safari); typed in by hand because the DOM lib does not ship it. */
type RecognitionResult = { isFinal: boolean; 0: { transcript: string } }
type RecognitionEvent = { resultIndex: number; results: ArrayLike<RecognitionResult> }
type Recognition = { lang: string; continuous: boolean; interimResults: boolean; maxAlternatives: number; start: () => void; stop: () => void; abort: () => void; onresult: ((e: RecognitionEvent) => void) | null; onerror: ((e: { error: string }) => void) | null; onend: (() => void) | null }
type RecognitionCtor = new () => Recognition
const speechCtor = (): RecognitionCtor | null => {
  const w = window as unknown as { SpeechRecognition?: RecognitionCtor; webkitSpeechRecognition?: RecognitionCtor }
  return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null
}

type Text = { ar: string; en: string }
export type ParsedRequest = {
  transcript: string; language: 'ar' | 'en'; engine: 'rules' | 'ai+rules'; confidence: number; missing: string[]; notes: { code: string; ar: string; en: string }[]
  understood: Text
  draft: { topic?: string | null; title_ar?: string; title_en?: string; summary_ar?: string; summary_en?: string; job_title_ids: string[]; job_titles: { id: string; code: string; name_ar: string; name_en: string }[]; genders: string[]; experience_min?: number | null; experience_max?: number | null
    trainer?: { id: string | null; name?: string | null; name_en?: string | null; query?: string | null; score?: number | null; optional: boolean } | null; total_hours?: number | null; capacity?: number | null; delivery_mode?: string | null; level?: string | null; start_date?: string | null; model?: string | null }
  program: { code: string; title_ar: string; title_en: string; summary_ar: string; summary_en: string; delivery_mode: string; level: string; total_hours: number; capacity: number; start_date: string | null; trainers: { id: string; role: string }[]; target_groups: { job_title_id: string }[]; audience: Record<string, unknown>; audience_summary: string; english: boolean }
}
type Status = { ai_available: boolean; min_chars: number; max_chars: number; examples: Text[] }
type Lookups = { job_titles: { id: string; name_ar: string; name_en: string }[]; trainers: { id: string; name_ar: string; name_en: string }[] }
type Phase = 'idle' | 'listening' | 'parsing' | 'review'

/**
 * The spoken order to create a programme. The person speaks (or types) in Arabic or English; the server reads the
 * request into a clear, structured draft they can check and correct; "start" fills the programme form, and the
 * person verifies and saves. Nothing is created from the voice alone.
 */
export default function VoiceComposer({ open, onClose, lookups, onApply }: { open: boolean; onClose: () => void; lookups: Lookups; onApply: (r: ParsedRequest) => void }) {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const status = useGet<{ data: Status }>(open ? '/admin/programs/assistant' : null, undefined, { staleTime: 60_000 })
  const supported = !!speechCtor()
  const [lang, setLang] = useState<'ar' | 'en'>(en ? 'en' : 'ar')
  const [phase, setPhase] = useState<Phase>('idle')
  const [text, setText] = useState('')
  const [interim, setInterim] = useState('')
  const [typed, setTyped] = useState(!supported)
  const [result, setResult] = useState<ParsedRequest | null>(null)
  const [error, setError] = useState<string | null>(null)
  const rec = useRef<Recognition | null>(null)
  const name = (o: { name_ar: string; name_en: string }) => (en && o.name_en ? o.name_en : o.name_ar)

  const stop = useCallback(() => { try { rec.current?.stop() } catch { /* already stopped */ } rec.current = null; setPhase((p) => (p === 'listening' ? 'idle' : p)) }, [])
  useEffect(() => () => { try { rec.current?.abort() } catch { /* unmounting */ } }, [])
  useEffect(() => { if (!open) { stop(); setPhase('idle'); setResult(null); setInterim(''); setError(null) } }, [open, stop])

  const listen = () => {
    const Ctor = speechCtor()
    if (!Ctor) { setTyped(true); return }
    setError(null); setInterim('')
    const r = new Ctor()
    r.lang = lang === 'ar' ? 'ar-QA' : 'en-US'
    r.continuous = true; r.interimResults = true; r.maxAlternatives = 1
    let finalText = text.trim()
    r.onresult = (e) => {
      let live = ''
      for (let i = e.resultIndex; i < e.results.length; i++) {
        const piece = e.results[i][0].transcript
        if (e.results[i].isFinal) finalText = `${finalText} ${piece}`.trim(); else live += piece
      }
      setText(finalText); setInterim(live)
    }
    r.onerror = (e) => { setError(e.error === 'not-allowed' ? String(t('admin.programs.voice.micDenied')) : e.error === 'no-speech' ? String(t('admin.programs.voice.noSpeech')) : e.error); setPhase('idle'); rec.current = null }
    r.onend = () => { rec.current = null; setInterim(''); setPhase((p) => (p === 'listening' ? 'idle' : p)) }
    rec.current = r
    setPhase('listening')
    r.start()
  }

  const parse = async () => {
    const body = text.trim()
    if (body.length < 3) { setError(String(t('admin.programs.voice.tooShort'))); return }
    stop(); setPhase('parsing'); setError(null)
    try {
      const { data } = await api.post<{ data: ParsedRequest }>('/admin/programs/assistant/parse', { text: body, lang, source: typed ? 'text' : 'voice' })
      setResult(data.data); setPhase('review')
    } catch (e) { setError(errorMessage(e)); setPhase('idle') }
  }

  const patch = (fn: (r: ParsedRequest) => ParsedRequest) => setResult((r) => (r ? fn(r) : r))
  const setDraft = (p: Partial<ParsedRequest['draft']>) => patch((r) => {
    const draft = { ...r.draft, ...p }
    const job_titles = lookups.job_titles.filter((j) => draft.job_title_ids.includes(j.id)).map((j) => ({ id: j.id, code: '', name_ar: j.name_ar, name_en: j.name_en }))
    const trainer = draft.trainer?.id ? lookups.trainers.find((x) => x.id === draft.trainer?.id) : null
    const audience: Record<string, unknown> = {}
    if (draft.job_title_ids.length) audience.job_title_ids = draft.job_title_ids
    if (draft.genders.length) audience.genders = draft.genders
    if (draft.experience_min != null) audience.experience_min = draft.experience_min
    if (draft.experience_max != null) audience.experience_max = draft.experience_max
    return { ...r, draft: { ...draft, job_titles, trainer: draft.trainer ? { ...draft.trainer, name: trainer?.name_ar ?? draft.trainer.name, name_en: trainer?.name_en ?? draft.trainer.name_en } : draft.trainer },
      missing: [...(draft.title_ar?.trim() ? [] : ['topic']), ...(draft.job_title_ids.length ? [] : ['audience'])],
      program: { ...r.program, title_ar: draft.title_ar ?? r.program.title_ar, title_en: draft.title_en ?? r.program.title_en, total_hours: draft.total_hours ?? r.program.total_hours, capacity: draft.capacity ?? r.program.capacity, delivery_mode: draft.delivery_mode ?? r.program.delivery_mode, level: draft.level ?? r.program.level, start_date: draft.start_date ?? r.program.start_date,
        trainers: draft.trainer?.id ? [{ id: draft.trainer.id, role: 'lead' }] : [], target_groups: draft.job_title_ids.map((id) => ({ job_title_id: id })), audience } }
  })
  const apply = () => { if (!result) return; if (result.missing.length) { toast(String(t('admin.programs.voice.completeFirst')), 'error'); return } onApply(result); onClose() }

  const d = result?.draft
  const tx = (x: Text) => (en ? x.en : x.ar)
  return (
    <Modal open={open} onClose={onClose} title={<span className="inline-flex items-center gap-2"><Sparkles className="size-5 text-gold-600" />{t('admin.programs.voice.title')}</span>} wide>
      <p className="text-sm text-slate-600">{t('admin.programs.voice.subtitle')}</p>

      {phase !== 'review' && (
        <div className="mt-4 space-y-4">
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-xs font-semibold text-slate-500"><Languages className="me-1 inline size-3.5" />{t('admin.programs.voice.language')}</span>
            {(['ar', 'en'] as const).map((l) => <button key={l} type="button" disabled={phase === 'listening'} onClick={() => setLang(l)} className={clsx('rounded-full px-3 py-1 text-xs font-semibold transition', lang === l ? 'bg-navy-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200')}>{l === 'ar' ? 'العربية' : 'English'}</button>)}
            <span className="ms-auto text-xs text-slate-400">{status.data?.data.ai_available ? t('admin.programs.voice.aiOn') : t('admin.programs.voice.aiOff')}</span>
          </div>

          {!typed && (
            <div className={clsx('relative overflow-hidden rounded-3xl border p-6 text-center transition-colors', phase === 'listening' ? 'border-gold-300 bg-gold-50/60' : 'border-navy-100 bg-ivory')}>
              <button type="button" onClick={phase === 'listening' ? stop : listen} aria-label={String(t(phase === 'listening' ? 'admin.programs.voice.stop' : 'admin.programs.voice.speak'))}
                className={clsx('relative mx-auto grid size-24 place-items-center rounded-full text-white shadow-lg transition-transform hover:scale-105', phase === 'listening' ? 'bg-red-600' : 'bg-navy-900')}>
                {phase === 'listening' && <span className="absolute inset-0 animate-ping rounded-full bg-red-400/50" />}
                {phase === 'listening' ? <MicOff className="relative size-9" /> : <Mic className="relative size-9" />}
              </button>
              <div className="mt-3 text-sm font-semibold text-navy-900">{phase === 'listening' ? t('admin.programs.voice.listening') : t('admin.programs.voice.tapToSpeak')}</div>
              <div className="mt-1 text-xs text-slate-500">{t('admin.programs.voice.hint')}</div>
              {(text || interim) && (
                <div className="mx-auto mt-4 max-w-2xl rounded-2xl bg-white/80 px-4 py-3 text-start text-sm text-navy-900 shadow-sm" dir={lang === 'ar' ? 'rtl' : 'ltr'}>
                  <AudioLines className="me-2 inline size-4 text-gold-600" />{text}<span className="text-slate-400"> {interim}</span>
                </div>
              )}
            </div>
          )}

          <Field label={typed ? t('admin.programs.voice.typeIt') : t('admin.programs.voice.transcript')} hint={!supported ? t('admin.programs.voice.unsupported') : undefined}>
            <textarea className="input min-h-24" dir={lang === 'ar' ? 'rtl' : 'ltr'} value={text} onChange={(e) => setText(e.target.value)} placeholder={String(t('admin.programs.voice.placeholder'))} maxLength={1000} />
          </Field>
          {!!status.data?.data.examples.length && (
            <div className="flex flex-wrap gap-1.5">
              {status.data.data.examples.map((ex, i) => <button key={i} type="button" onClick={() => setText(lang === 'ar' ? ex.ar : ex.en)} className="rounded-full border border-navy-100 bg-white px-3 py-1 text-xs text-slate-600 hover:border-gold-400 hover:text-navy-900">{t('admin.programs.voice.example')} {i + 1}</button>)}
            </div>
          )}
          {error && <div className="flex items-start gap-2 rounded-xl bg-red-50 p-3 text-sm text-danger"><AlertTriangle className="mt-0.5 size-4 shrink-0" />{error}</div>}
          <div className="flex flex-wrap items-center justify-between gap-2">
            {supported && <button type="button" className="inline-flex items-center gap-1 text-xs text-slate-500 hover:text-navy-900" onClick={() => { stop(); setTyped(!typed) }}>{typed ? <Mic className="size-3.5" /> : <Keyboard className="size-3.5" />}{typed ? t('admin.programs.voice.useMic') : t('admin.programs.voice.useKeyboard')}</button>}
            <div className="ms-auto flex gap-2">
              {text && <Button variant="outline" icon={<RotateCcw className="size-4" />} onClick={() => { stop(); setText(''); setInterim('') }}>{t('admin.programs.voice.clear')}</Button>}
              <Button variant="gold" icon={<Sparkles className="size-4" />} loading={phase === 'parsing'} disabled={text.trim().length < 3} onClick={parse}>{t('admin.programs.voice.understand')}</Button>
            </div>
          </div>
        </div>
      )}

      {phase === 'review' && result && d && (
        <div className="mt-4 space-y-4">
          <div className="rounded-2xl border border-gold-200 bg-gold-50/60 p-4">
            <div className="mb-1 flex flex-wrap items-center gap-2 text-xs">
              <Badge color={result.engine === 'ai+rules' ? 'gold' : 'navy'}>{result.engine === 'ai+rules' ? t('admin.programs.voice.engineAi') : t('admin.programs.voice.engineRules')}</Badge>
              <Badge color={result.confidence >= 0.8 ? 'green' : result.confidence >= 0.5 ? 'amber' : 'red'}>{t('admin.programs.voice.confidence', { n: Math.round(result.confidence * 100) })}</Badge>
              <span className="text-slate-500">{t('admin.programs.voice.heard')}: <q className="text-navy-900">{result.transcript}</q></span>
            </div>
            <div className="text-base font-bold text-navy-900">{tx(result.understood)}</div>
            <div className="mt-1 text-xs text-slate-500" dir={en ? 'rtl' : 'ltr'}>{en ? result.understood.ar : result.understood.en}</div>
          </div>
          {(result.missing.length > 0 || result.notes.length > 0) && (
            <ul className="space-y-1 text-sm">
              {result.missing.map((m) => <li key={m} className="flex items-center gap-2 text-amber-800"><AlertTriangle className="size-4" />{t(`admin.programs.voice.missing.${m}`)}</li>)}
              {result.notes.map((n) => <li key={n.code} className="flex items-center gap-2 text-slate-600"><AlertTriangle className="size-4 text-amber-600" />{tx(n)}</li>)}
            </ul>
          )}

          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('admin.programs.titleAr')} className="sm:col-span-2"><input className={clsx('input', !d.title_ar && 'border-amber-400')} value={d.title_ar ?? ''} onChange={(e) => setDraft({ title_ar: e.target.value })} /></Field>
            {(lang === 'en' || d.title_en) && <Field label={t('admin.programs.titleEn')} className="sm:col-span-2"><input className="input" dir="ltr" value={d.title_en ?? ''} onChange={(e) => setDraft({ title_en: e.target.value })} /></Field>}
            <Field label={t('admin.programs.audience')} className="sm:col-span-2">
              <div className={clsx('flex flex-wrap gap-1.5 rounded-xl border p-2', d.job_title_ids.length ? 'border-navy-100' : 'border-amber-400')}>
                {lookups.job_titles.map((j) => { const on = d.job_title_ids.includes(j.id); return <button key={j.id} type="button" onClick={() => setDraft({ job_title_ids: on ? d.job_title_ids.filter((x) => x !== j.id) : [...d.job_title_ids, j.id] })} className={clsx('rounded-full px-2.5 py-1 text-xs font-semibold transition', on ? 'bg-navy-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200')}>{on && <Check className="me-1 inline size-3" />}{name(j)}</button> })}
              </div>
            </Field>
            <Field label={t('admin.programs.voice.gender')}>
              <div className="flex gap-1.5">{([['', 'all'], ['male', 'male'], ['female', 'female']] as const).map(([v, k]) => <button key={k} type="button" onClick={() => setDraft({ genders: v ? [v] : [] })} className={clsx('rounded-full px-3 py-1 text-xs font-semibold', (v ? d.genders[0] === v && d.genders.length === 1 : !d.genders.length) ? 'bg-navy-900 text-white' : 'bg-slate-100 text-slate-600')}>{t(`admin.programs.voice.genders.${k}`)}</button>)}</div>
            </Field>
            <Field label={t('admin.programs.voice.experience')}>
              <div className="flex items-center gap-2"><input type="number" min={0} max={60} className="input" placeholder={String(t('admin.programs.voice.from'))} value={d.experience_min ?? ''} onChange={(e) => setDraft({ experience_min: e.target.value === '' ? null : Number(e.target.value) })} /><span className="text-slate-400">–</span><input type="number" min={0} max={60} className="input" placeholder={String(t('admin.programs.voice.to'))} value={d.experience_max ?? ''} onChange={(e) => setDraft({ experience_max: e.target.value === '' ? null : Number(e.target.value) })} /></div>
            </Field>
            <Field label={t('admin.programs.trainers')} hint={d.trainer && !d.trainer.id && d.trainer.query ? t('admin.programs.voice.trainerUnmatched', { q: d.trainer.query }) : d.trainer?.optional ? t('admin.programs.voice.trainerOptional') : undefined}>
              <select className="input" value={d.trainer?.id ?? ''} onChange={(e) => setDraft({ trainer: e.target.value ? { id: e.target.value, optional: d.trainer?.optional ?? false } : null })}><option value="">—</option>{lookups.trainers.map((tr) => <option key={tr.id} value={tr.id}>{name(tr)}</option>)}</select>
            </Field>
            <div className="grid grid-cols-2 gap-2">
              <Field label={t('admin.programs.hours')}><input type="number" min={1} className="input" value={d.total_hours ?? ''} onChange={(e) => setDraft({ total_hours: e.target.value === '' ? null : Number(e.target.value) })} /></Field>
              <Field label={t('admin.programs.capacity')}><input type="number" min={1} className="input" value={d.capacity ?? ''} onChange={(e) => setDraft({ capacity: e.target.value === '' ? null : Number(e.target.value) })} /></Field>
            </div>
            <Field label={t('programs.mode')}><select className="input" value={d.delivery_mode ?? ''} onChange={(e) => setDraft({ delivery_mode: e.target.value || null })}><option value="">—</option>{['in_person', 'online', 'hybrid'].map((m) => <option key={m} value={m}>{t(`modes.${m}`)}</option>)}</select></Field>
            <Field label={t('admin.programs.startDate')}><input type="date" className="input" value={d.start_date ?? ''} onChange={(e) => setDraft({ start_date: e.target.value || null })} /></Field>
          </div>
          <p className="text-xs text-slate-400">{t('admin.programs.voice.advisory')}</p>
          <div className="flex flex-wrap justify-between gap-2">
            <Button variant="outline" icon={<RotateCcw className="size-4" />} onClick={() => { setPhase('idle'); setResult(null) }}>{t('admin.programs.voice.again')}</Button>
            <div className="flex gap-2">
              <Button variant="ghost" icon={<X className="size-4" />} onClick={onClose}>{t('common.cancel')}</Button>
              <Button variant="gold" icon={<Play className="size-4" />} onClick={apply}>{t('admin.programs.voice.start')}</Button>
            </div>
          </div>
        </div>
      )}
    </Modal>
  )
}
