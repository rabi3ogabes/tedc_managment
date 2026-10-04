import clsx from 'clsx'
import { ArrowDown, ArrowUp, Check, CalendarClock, Copy, ExternalLink, GripVertical, ImagePlus, Loader2, Pause, Play, RefreshCcw, Save, Trash2, Tv } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Field, PageHeader, Spinner } from '@/components/ui'
import { Switch } from './notifications/shared'
import LobbyView, { STAGE, type LobbyData, type LobbySettings, type Transition } from '@/components/lobby/LobbyView'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'

type Slide = { id: string; title: string; url: string; seconds: number | null; transition: Transition | null; enabled: boolean; from: string | null; to: string | null }
type Payload = { settings: LobbySettings; token: string; slides: Slide[]; preview: LobbyData }
const TRANSITIONS: Transition[] = ['fade', 'slide', 'zoom', 'none']

/** Settings → Today's programs screen: the address, the timing and transition, and the images shown after the automatic program slides. */
export default function LobbyScreenSettings() {
  const { t } = useTranslation()
  const { data, isLoading, refetch } = useGet<{ data: Payload }>('/admin/settings/lobby-screen')
  const [draft, setDraft] = useState<LobbySettings | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [note, setNote] = useState<{ ok: boolean; text: string } | null>(null)
  const [playing, setPlaying] = useState(true)
  const [copied, setCopied] = useState(false)
  const [dragging, setDragging] = useState<string | null>(null)
  const [over, setOver] = useState<string | null>(null)
  const file = useRef<HTMLInputElement>(null)

  useEffect(() => { if (data && !draft) setDraft({ ...data.data.settings }) }, [data, draft])
  if (isLoading || !data || !draft) return <Spinner />
  const d = data.data
  const link = `${window.location.origin}/lobby-screen/${d.token}`
  const set = <K extends keyof LobbySettings>(k: K, v: LobbySettings[K]) => setDraft((x) => x && { ...x, [k]: v })
  const run = async (key: string, job: () => Promise<unknown>, ok?: string) => {
    setBusy(key); setNote(null)
    try { await job(); await refetch(); ok && setNote({ ok: true, text: ok }) } catch (e) { setNote({ ok: false, text: errorMessage(e) }) } finally { setBusy(null) }
  }

  const saveSettings = () => run('save', async () => { await api.put('/admin/settings/lobby-screen', draft); setDraft(null) }, t('lobby.saved'))
  const upload = (files: FileList | File[]) => run('upload', async () => {
    for (const f of Array.from(files)) {
      const body = new FormData()
      body.append('image', f)
      await api.post('/admin/settings/lobby-screen/slides', body)
    }
  })
  const patchSlide = (id: string, patch: Partial<Slide>) => run(`s${id}`, () => api.put(`/admin/settings/lobby-screen/slides/${id}`, patch))
  const reorder = (ids: string[]) => run('order', () => api.put('/admin/settings/lobby-screen/slides/order', { ids }))
  const move = (id: string, dir: -1 | 1) => {
    const ids = d.slides.map((s) => s.id)
    const at = ids.indexOf(id)
    const to = at + dir
    if (to < 0 || to >= ids.length) return
    ;[ids[at], ids[to]] = [ids[to], ids[at]]
    void reorder(ids)
  }
  const drop = (target: string) => {
    if (!dragging || dragging === target) return
    const ids = d.slides.map((s) => s.id).filter((x) => x !== dragging)
    ids.splice(ids.indexOf(target) + (d.slides.findIndex((s) => s.id === dragging) < d.slides.findIndex((s) => s.id === target) ? 1 : 0), 0, dragging)
    void reorder(ids)
  }
  const copy = () => { void navigator.clipboard?.writeText(link); setCopied(true); setTimeout(() => setCopied(false), 1500) }
  const previewWidth = 300

  return (
    <div className="space-y-6 pb-8">
      <PageHeader title={<span className="flex items-center gap-3"><span className="grid size-11 place-items-center rounded-2xl bg-navy-900 text-gold-300"><Tv className="size-5" /></span>{t('lobby.title')}</span>} subtitle={t('lobby.subtitle')}
        actions={<label className="flex items-center gap-2 text-sm font-bold text-navy-900">{t('lobby.enabled')}<Switch checked={d.settings.enabled} label={t('lobby.enabled')} onChange={(v) => void run('enabled', () => api.put('/admin/settings/lobby-screen', { enabled: v }))} /></label>} />
      {note && <div role="status" className={clsx('flex items-center gap-2 rounded-2xl p-3 text-sm font-semibold', note.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-danger')}>{note.ok && <Check className="size-4" />}{note.text}</div>}

      <div className="grid gap-6 xl:grid-cols-[19rem_minmax(0,1fr)]">
        {/* Live preview + address */}
        <div className="space-y-4">
          <Card>
            <div className="mb-3 flex items-center justify-between"><h2 className="font-bold text-navy-900">{t('lobby.preview')}</h2>
              <button type="button" onClick={() => setPlaying((v) => !v)} className="inline-flex items-center gap-1.5 rounded-lg border border-navy-100 px-2.5 py-1 text-xs font-bold text-navy-800 hover:border-gold-400">{playing ? <Pause className="size-3.5" /> : <Play className="size-3.5" />}{t(playing ? 'lobby.pause' : 'lobby.play')}</button></div>
            <div dir="ltr" className="relative mx-auto overflow-hidden rounded-2xl bg-black shadow-glass ring-1 ring-navy-100" style={{ width: previewWidth, height: (previewWidth * STAGE.h) / STAGE.w }}>
              <div style={{ position: 'absolute', left: 0, top: 0, width: STAGE.w, height: STAGE.h, transform: `scale(${previewWidth / STAGE.w})`, transformOrigin: 'top left' }}><LobbyView data={{ ...d.preview, settings: { ...d.preview.settings, ...draft } }} playing={playing} /></div>
            </div>
            <p className="mt-2 text-center text-[11px] text-slate-400">{t('lobby.size')} · {t('lobby.programsCount', { count: d.preview.programs.length })}</p>
          </Card>
          <Card>
            <h2 className="font-bold text-navy-900">{t('lobby.link')}</h2>
            <p className="mt-1 text-xs text-slate-500">{t('lobby.linkHint')}</p>
            <div className="mt-3 break-all rounded-xl bg-ivory p-3 font-mono text-[11px] text-navy-800" dir="ltr">{link}</div>
            <div className="mt-3 flex flex-wrap gap-2">
              <Button size="sm" variant="outline" icon={copied ? <Check className="size-4 text-emerald-600" /> : <Copy className="size-4" />} onClick={copy}>{copied ? t('lobby.copied') : t('lobby.copy')}</Button>
              <Button size="sm" variant="gold" icon={<ExternalLink className="size-4" />} onClick={() => window.open(link, '_blank', 'noopener')}>{t('lobby.open')}</Button>
              <Button size="sm" variant="ghost" icon={<RefreshCcw className="size-4" />} loading={busy === 'token'} onClick={() => window.confirm(t('lobby.regenerateConfirm')) && void run('token', () => api.post('/admin/settings/lobby-screen/token'))}>{t('lobby.regenerate')}</Button>
            </div>
          </Card>
        </div>

        <div className="space-y-6">
          {/* Timing and transition */}
          <Card className="space-y-5">
            <h2 className="font-bold text-navy-900">{t('lobby.settings')}</h2>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label={t('lobby.language')}><select className="input" value={draft.language} onChange={(e) => set('language', e.target.value as 'ar' | 'en')}><option value="ar">{t('lobby.ar')}</option><option value="en">{t('lobby.en')}</option></select></Field>
              <p className="rounded-xl bg-ivory px-3 py-2 text-xs text-slate-500 sm:col-span-2">{t('lobby.autoLayout')}</p>
              <Field label={t('lobby.programsSeconds')}><input type="number" min={5} max={300} className="input" value={draft.programs_seconds} onChange={(e) => set('programs_seconds', Number(e.target.value))} /></Field>
              <Field label={t('lobby.slideSeconds')}><input type="number" min={3} max={600} className="input" value={draft.slide_seconds} onChange={(e) => set('slide_seconds', Number(e.target.value))} /></Field>
            </div>
            <div>
              <span className="label">{t('lobby.transition')}</span>
              <div role="radiogroup" className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {TRANSITIONS.map((x) => <button key={x} type="button" role="radio" aria-checked={draft.transition === x} onClick={() => set('transition', x)} className={clsx('rounded-xl border px-3 py-2.5 text-sm font-bold transition', draft.transition === x ? 'border-navy-900 bg-navy-900 text-white shadow' : 'border-navy-100 bg-white text-slate-600 hover:border-gold-400')}>{t(`lobby.transitions.${x}`)}</button>)}
              </div>
            </div>
            <Field label={`${t('lobby.transitionMs')}: ${draft.transition_ms} ${t('lobby.ms')}`}><input type="range" min={0} max={3000} step={100} className="w-full accent-gold-600" value={draft.transition_ms} onChange={(e) => set('transition_ms', Number(e.target.value))} /></Field>
            <div className="grid gap-3 sm:grid-cols-3">
              {([['show_programs', 'lobby.showPrograms'], ['show_clock', 'lobby.showClock'], ['show_progress', 'lobby.showProgress']] as const).map(([k, label]) => (
                <label key={k} className="flex items-center justify-between gap-3 rounded-xl border border-navy-100 p-3 text-sm font-semibold text-navy-900">{t(label)}<Switch small checked={draft[k]} label={t(label)} onChange={(v) => set(k, v)} /></label>
              ))}
            </div>
            <div className="flex justify-end"><Button variant="gold" icon={<Save className="size-4" />} loading={busy === 'save'} onClick={saveSettings}>{t('lobby.save')}</Button></div>
          </Card>

          {/* The slides */}
          <Card className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div><h2 className="font-bold text-navy-900">{t('lobby.slides')}</h2><p className="text-xs text-slate-500">{t('lobby.slidesHint')}</p></div>
              <Button variant="gold" icon={busy === 'upload' ? <Loader2 className="size-4 animate-spin" /> : <ImagePlus className="size-4" />} disabled={busy === 'upload'} onClick={() => file.current?.click()}>{busy === 'upload' ? t('lobby.uploading') : t('lobby.upload')}</Button>
              <input ref={file} type="file" multiple accept="image/jpeg,image/png,image/webp" className="hidden" onChange={(e) => { if (e.target.files?.length) void upload(e.target.files); e.target.value = '' }} />
            </div>

            {/* Always first: today's programs */}
            <div className="flex items-center gap-4 rounded-2xl border border-gold-300 bg-gold-100/40 p-3.5">
              <span className="grid size-12 shrink-0 place-items-center rounded-xl bg-navy-900 text-gold-300"><CalendarClock className="size-6" /></span>
              <div className="min-w-0 flex-1"><div className="font-bold text-navy-900">{t('lobby.auto')}</div><div className="text-xs text-slate-500">{t('lobby.autoHint')}</div></div>
              <span className="rounded-full bg-white px-3 py-1 text-xs font-bold text-navy-800">1</span>
            </div>

            <div onDragOver={(e) => e.preventDefault()} onDrop={(e) => { if (e.dataTransfer.files.length) { e.preventDefault(); void upload(e.dataTransfer.files) } }} className="space-y-3">
              {d.slides.length === 0 && (
                <button type="button" onClick={() => file.current?.click()} className="grid w-full place-items-center gap-1.5 rounded-2xl border-2 border-dashed border-navy-200 bg-ivory/60 px-6 py-10 text-slate-500 transition hover:border-gold-400 hover:text-navy-900">
                  <ImagePlus className="size-8 text-gold-600" /><span className="font-semibold">{t('lobby.drop')}</span><span className="text-xs">{t('lobby.dropHint')}</span><span className="mt-1 text-xs">{t('lobby.empty')}</span>
                </button>
              )}
              {d.slides.map((s, i) => (
                <div key={s.id} draggable onDragStart={() => setDragging(s.id)} onDragOver={(e) => { e.preventDefault(); setOver(s.id) }} onDragLeave={() => setOver((c) => (c === s.id ? null : c))} onDrop={(e) => { e.stopPropagation(); setOver(null); drop(s.id) }} onDragEnd={() => { setDragging(null); setOver(null) }}
                  className={clsx('flex flex-wrap items-start gap-4 rounded-2xl border bg-white p-3.5 transition', s.enabled ? 'border-navy-100' : 'border-slate-200 bg-slate-50/70 opacity-75', over === s.id && 'ring-2 ring-gold-400', dragging === s.id && 'opacity-40')}>
                  <div className="flex items-center gap-1.5"><GripVertical className="size-5 cursor-grab text-slate-300" /><span className="grid size-7 place-items-center rounded-full bg-navy-100 text-xs font-black text-navy-800">{i + 2}</span></div>
                  <img src={s.url} alt={s.title} className="h-32 w-[4.5rem] shrink-0 rounded-xl border border-navy-100 object-cover" loading="lazy" />
                  <div className="grid min-w-0 flex-1 basis-72 gap-3 sm:grid-cols-2">
                    <Field label={t('lobby.titleField')} className="sm:col-span-2"><input className="input !py-1.5 text-sm" defaultValue={s.title} dir="auto" onBlur={(e) => e.target.value !== s.title && void patchSlide(s.id, { title: e.target.value })} /></Field>
                    <Field label={`${t('lobby.seconds')} (${t('lobby.secondsHint')}: ${draft.slide_seconds})`}><input type="number" min={3} max={600} className="input !py-1.5 text-sm" defaultValue={s.seconds ?? ''} placeholder={String(draft.slide_seconds)} onBlur={(e) => { const v = e.target.value === '' ? null : Number(e.target.value); if (v !== s.seconds) void patchSlide(s.id, { seconds: v }) }} /></Field>
                    <Field label={t('lobby.transition')}><select className="input !py-1.5 text-sm" value={s.transition ?? ''} onChange={(e) => void patchSlide(s.id, { transition: (e.target.value || null) as Transition | null })}><option value="">{t('lobby.useDefault')}</option>{TRANSITIONS.map((x) => <option key={x} value={x}>{t(`lobby.transitions.${x}`)}</option>)}</select></Field>
                    <Field label={t('lobby.from')}><input type="date" className="input !py-1.5 text-sm" defaultValue={s.from ?? ''} onBlur={(e) => (e.target.value || null) !== s.from && void patchSlide(s.id, { from: e.target.value || null })} /></Field>
                    <Field label={t('lobby.to')}><input type="date" className="input !py-1.5 text-sm" defaultValue={s.to ?? ''} onBlur={(e) => (e.target.value || null) !== s.to && void patchSlide(s.id, { to: e.target.value || null })} /></Field>
                  </div>
                  <div className="flex flex-col items-center gap-2">
                    <label className="flex flex-col items-center gap-1 text-[11px] font-semibold text-slate-500">{t('lobby.show')}<Switch small checked={s.enabled} label={t('lobby.show')} onChange={(v) => void patchSlide(s.id, { enabled: v })} /></label>
                    <div className="flex gap-1">
                      <button type="button" aria-label={t('lobby.up')} disabled={i === 0} onClick={() => move(s.id, -1)} className="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-navy-100/60 hover:text-navy-900 disabled:opacity-30"><ArrowUp className="size-4" /></button>
                      <button type="button" aria-label={t('lobby.down')} disabled={i === d.slides.length - 1} onClick={() => move(s.id, 1)} className="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-navy-100/60 hover:text-navy-900 disabled:opacity-30"><ArrowDown className="size-4" /></button>
                      <button type="button" aria-label={t('lobby.remove')} onClick={() => window.confirm(t('lobby.removeConfirm')) && void run(`d${s.id}`, () => api.delete(`/admin/settings/lobby-screen/slides/${s.id}`))} className="grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-red-50 hover:text-danger"><Trash2 className="size-4" /></button>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          </Card>
        </div>
      </div>
    </div>
  )
}
