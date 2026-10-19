import clsx from 'clsx'
import { ChevronLeft, ChevronRight, Clock, Dices, Eraser, Expand, Grid3x3, Highlighter, MonitorUp, MousePointer2, PenLine, Play, Radio, ScanSearch, Square, SquareDashed, StickyNote, Timer, X, ZoomIn } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { ErrorState, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api } from '@/lib/api'
import { toast } from '@/lib/toast'
import { fmtClock, type FileView, type KitInfo, type ProgramDetail, type SessionRow } from '../api'
import { Shield } from '../shield'
import { channelName, COLORS, INITIAL_SHOW, newToken, type Msg, type Pointer, type Show, type Stroke, type Tool } from './state'
import { usePresentSource } from './source'
import Stage from './Stage'

const LS = (k: string) => { try { return localStorage.getItem(k) } catch { return null } }
const LSset = (k: string, v: string) => { try { localStorage.setItem(k, v) } catch { /* storage unavailable */ } }

function useBox() {
  const ref = useRef<HTMLDivElement>(null)
  const [box, setBox] = useState({ w: 800, h: 450 })
  useEffect(() => {
    const el = ref.current
    if (!el) return
    const ro = new ResizeObserver(() => setBox({ w: Math.floor(el.clientWidth), h: Math.floor(el.clientHeight) }))
    ro.observe(el)
    setBox({ w: Math.floor(el.clientWidth), h: Math.floor(el.clientHeight) })
    return () => ro.disconnect()
  }, [])
  return [ref, box] as const
}

/**
 * The presenter's studio — what PowerPoint's presenter view does, in the browser and tied to the session: the slide, the next
 * slide, the kit's speaker notes and the trainer's own, the session clock, ink / laser / spotlight / magnifier, the black and white
 * screens, a break timer, a random trainee from the group's roster, a slide sorter, and a second window for the room's screen that
 * follows the presenter live. The kit stays the centre's: the same protected viewer, watermark and trail as the reader.
 */
export default function PresentationStudio() {
  const { t, i18n } = useTranslation()
  const { kitId = '', fileId = '' } = useParams()
  const [params] = useSearchParams()
  const nav = useNavigate()
  const program = params.get('program') ?? ''
  const info = useGet<{ data: KitInfo }>(`/me/teaching/kits/${kitId}`, program ? { program } : undefined, { staleTime: 0, gcTime: 0 })
  const view = useGet<{ data: FileView }>(`/me/teaching/kits/${kitId}/files/${fileId}`, program ? { program } : undefined, { staleTime: 0, gcTime: 0 })
  const detail = useGet<{ data: ProgramDetail }>(program ? `/me/teaching/programs/${program}` : null, undefined, { staleTime: 0 })
  const source = usePresentSource(view.data?.data)
  const en = i18n.language === 'en'

  const token = useMemo(() => params.get('ch') ?? newToken(), [params])
  const [show, setShow] = useState<Show>(() => ({ ...INITIAL_SHOW, index: Math.max(0, Number(LS(`tedc.present.${fileId}`)) || 0) }))
  const [pointer, setPointer] = useState<Pointer>(null)
  const [tool, setTool] = useState<Tool>('none')
  const [color, setColor] = useState(COLORS[0])
  const [mode, setMode] = useState<'console' | 'screen'>('console')
  const [grid, setGrid] = useState(false)
  const [notesSize, setNotesSize] = useState(() => Number(LS('tedc.present.notesSize')) || 18)
  const [mine, setMine] = useState<Record<number, string>>(() => { try { return JSON.parse(LS(`tedc.present.mine.${fileId}`) ?? '{}') as Record<number, string> } catch { return {} } })
  const [jump, setJump] = useState('')
  const [now, setNow] = useState(Date.now())
  const [audience, setAudience] = useState(false)
  const [spin, setSpin] = useState<string | null>(null)
  const [dock, setDock] = useState(true)
  const started = useRef(Date.now())
  const seen = useRef(new Set<number>())
  const chan = useRef<BroadcastChannel | null>(null)
  const win = useRef<Window | null>(null)
  const picked = useRef(new Set<string>())
  const [stageRef, box] = useBox()

  const count = source.count
  const session: SessionRow | null = useMemo(() => {
    const list = detail.data?.data.sessions ?? []
    const id = params.get('session')
    const t0 = Date.now()
    return list.find((s) => s.id === id) ?? list.find((s) => s.status === 'live') ?? list.find((s) => new Date(s.starts_at).getTime() - 3600000 <= t0 && t0 <= new Date(s.ends_at).getTime()) ?? null
  }, [detail.data, params])
  const live = session?.status === 'live'
  const kit = info.data?.data

  // ───────── the clock, the second window and the trail
  useEffect(() => { const id = window.setInterval(() => setNow(Date.now()), 1000); return () => window.clearInterval(id) }, [])

  useEffect(() => {
    const ch = new BroadcastChannel(channelName(kitId, fileId, token))
    chan.current = ch
    ch.onmessage = (e: MessageEvent<Msg>) => { if (e.data.t === 'hello') { setAudience(true); sendState.current() } }
    return () => { ch.postMessage({ t: 'bye' } satisfies Msg); ch.close(); chan.current = null }
  }, [kitId, fileId, token])

  const showRef = useRef(show)
  const sendState = useRef(() => undefined as void)
  useEffect(() => {
    showRef.current = show
    sendState.current = () => chan.current?.postMessage({ t: 'state', s: showRef.current } satisfies Msg)
    const id = window.setTimeout(() => sendState.current(), 30)
    return () => window.clearTimeout(id)
  }, [show])

  useEffect(() => { const id = window.setInterval(() => { if (win.current?.closed) { win.current = null; setAudience(false) } }, 1500); return () => window.clearInterval(id) }, [])

  const logEvent = useCallback((reason: string, extra: Record<string, unknown> = {}) => {
    void api.post(`/me/teaching/kits/${kitId}/events`, { kind: 'presented', file_id: fileId, program_id: program || undefined, session_id: session?.id, meta: { reason, rehearsal: !live, ...extra } }).catch(() => undefined)
  }, [kitId, fileId, program, session?.id, live])
  const logged = useRef(false)
  useEffect(() => { if (kit && !logged.current && detail.isFetched !== false) { logged.current = true; logEvent('start') } }, [kit, detail.isFetched, logEvent])

  // ───────── moving through the slides
  const goto = useCallback((i: number) => {
    setShow((s) => { const n = Math.max(0, Math.min(Math.max(0, count - 1), i)); LSset(`tedc.present.${fileId}`, String(n)); seen.current.add(n); return { ...s, index: n, zoom: null } })
  }, [count, fileId])
  const go = useCallback((d: number) => goto(showRef.current.index + d), [goto])
  useEffect(() => { seen.current.add(show.index) }, [show.index])

  const sendPointer = useRef(0)
  const movePointer = useCallback((p: Pointer) => {
    setPointer(p)
    const t0 = performance.now()
    if (p && t0 - sendPointer.current < 16) return
    sendPointer.current = t0
    chan.current?.postMessage({ t: 'pointer', p } satisfies Msg)
  }, [])
  useEffect(() => { if (tool !== 'laser' && tool !== 'spot') movePointer(null) }, [tool, movePointer])

  const setStrokes = (index: number, strokes: Stroke[]) => setShow((s) => ({ ...s, strokes: { ...s.strokes, [index]: strokes } }))
  const clearInk = () => setShow((s) => ({ ...s, strokes: { ...s.strokes, [s.index]: [] } }))
  const blackout = (b: 'black' | 'white') => setShow((s) => ({ ...s, blackout: s.blackout === b ? 'off' : b }))

  // ───────── the room's screen
  const openAudience = () => {
    const url = `/admin/teaching/audience/${kitId}/${fileId}?ch=${token}${program ? `&program=${program}` : ''}`
    win.current = window.open(url, 'tedc-audience', 'popup=yes,width=1280,height=720')
    if (!win.current) toast(t('teach.present.popupBlocked'), 'error')
  }
  const enterScreen = () => { setMode('screen'); void document.documentElement.requestFullscreen?.().catch(() => undefined) }
  const leaveScreen = () => { setMode('console'); if (document.fullscreenElement) void document.exitFullscreen().catch(() => undefined) }
  useEffect(() => {
    const onFs = () => { if (!document.fullscreenElement) setMode((m) => (m === 'screen' ? 'console' : m)) }
    document.addEventListener('fullscreenchange', onFs)
    return () => document.removeEventListener('fullscreenchange', onFs)
  }, [])

  // ───────── break timer and a random trainee
  const startTimer = (minutes: number) => setShow((s) => ({ ...s, overlay: { kind: 'timer', endsAt: Date.now() + minutes * 60000, label: t('teach.present.breakLabel', { m: minutes }) } }))
  const clearOverlay = () => setShow((s) => ({ ...s, overlay: null }))
  const pick = () => {
    const names = (detail.data?.data.roster ?? []).map((r) => r.name)
    if (!names.length) { toast(t('teach.present.noRoster'), 'error'); return }
    let pool = names.filter((n) => !picked.current.has(n))
    if (!pool.length) { picked.current.clear(); pool = names }
    const winner = pool[Math.floor(Math.random() * pool.length)]
    let n = 0
    const id = window.setInterval(() => {
      setSpin(names[Math.floor(Math.random() * names.length)])
      if (++n > 16) { window.clearInterval(id); setSpin(null); picked.current.add(winner); setShow((s) => ({ ...s, overlay: { kind: 'picker', name: winner } })) }
    }, 85)
  }

  const startSession = async () => {
    if (!session) return
    try { await api.post(`/me/teaching/sessions/${session.id}/start`); await detail.refetch(); toast(t('teach.present.sessionStarted')) } catch (e) { toast((e as { response?: { data?: { message?: string } } }).response?.data?.message ?? t('teach.present.cannotStart'), 'error') }
  }

  const finish = () => {
    logEvent('end', { seconds: Math.round((Date.now() - started.current) / 1000), seen: seen.current.size, slides: count })
    win.current?.close()
    if (document.fullscreenElement) void document.exitFullscreen().catch(() => undefined)
    nav(program ? `/admin/teaching?program=${program}` : '/admin/teaching', { replace: true })
  }

  // ───────── the keyboard
  useEffect(() => {
    const rtl = document.documentElement.dir === 'rtl'
    const onKey = (e: KeyboardEvent) => {
      const el = e.target as HTMLElement | null
      if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA')) return
      if (e.ctrlKey || e.metaKey || e.altKey) return
      const k = e.key
      const next = () => go(1)
      const prev = () => go(-1)
      if (k === 'ArrowRight') { e.preventDefault(); if (rtl) prev(); else next() }
      else if (k === 'ArrowLeft') { e.preventDefault(); if (rtl) next(); else prev() }
      else if (k === 'ArrowDown' || k === 'PageDown' || k === ' ' || k === 'Enter') { e.preventDefault(); next() }
      else if (k === 'ArrowUp' || k === 'PageUp' || k === 'Backspace') { e.preventDefault(); prev() }
      else if (k === 'Home') goto(0)
      else if (k === 'End') goto(count - 1)
      else if (k === 'Escape') { if (showRef.current.overlay) clearOverlay(); else if (showRef.current.blackout !== 'off') setShow((s) => ({ ...s, blackout: 'off' })); else if (tool !== 'none') setTool('none'); else if (grid) setGrid(false); else if (mode === 'screen') leaveScreen() }
      else if (k.toLowerCase() === 'b') blackout('black')
      else if (k.toLowerCase() === 'w') blackout('white')
      else if (k.toLowerCase() === 'l') setTool((x) => (x === 'laser' ? 'none' : 'laser'))
      else if (k.toLowerCase() === 's') setTool((x) => (x === 'spot' ? 'none' : 'spot'))
      else if (k.toLowerCase() === 'p') setTool((x) => (x === 'pen' ? 'none' : 'pen'))
      else if (k.toLowerCase() === 'h') setTool((x) => (x === 'marker' ? 'none' : 'marker'))
      else if (k.toLowerCase() === 'z') setTool((x) => (x === 'zoom' ? 'none' : 'zoom'))
      else if (k.toLowerCase() === 'e') clearInk()
      else if (k.toLowerCase() === 'g') setGrid((g) => !g)
      else if (k.toLowerCase() === 'r') pick()
      else if (k.toLowerCase() === 'f') { if (mode === 'screen') leaveScreen(); else enterScreen() }
      else if (/^[0-9]$/.test(k)) setJump((j) => (j + k).slice(-3))
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [go, goto, count, tool, grid, mode, detail.data])
  useEffect(() => {
    if (!jump) return
    const id = window.setTimeout(() => { const n = Number(jump); if (n >= 1 && n <= count) goto(n - 1); setJump('') }, 900)
    return () => window.clearTimeout(id)
  }, [jump, count, goto])

  // ───────── the screen-mode dock hides itself
  useEffect(() => {
    if (mode !== 'screen') return
    let id = 0
    const wake = () => { setDock(true); window.clearTimeout(id); id = window.setTimeout(() => setDock(false), 2600) }
    wake()
    window.addEventListener('mousemove', wake)
    return () => { window.removeEventListener('mousemove', wake); window.clearTimeout(id) }
  }, [mode])

  if (info.isLoading || view.isLoading) return <div className="grid h-screen place-items-center bg-navy-950"><Spinner /></div>
  if (info.error || view.error || !kit || !view.data) return <ErrorState error={info.error ?? view.error} onRetry={() => { void info.refetch(); void view.refetch() }} home="/admin/teaching" />
  if (source.error || (!view.data.data.deck && view.data.data.file.kind !== 'pdf')) return <ErrorState message={t('teach.present.notPresentable')} home="/admin/teaching" />

  const elapsed = now - started.current
  const remaining = session ? new Date(session.ends_at).getTime() - now : null
  const title = en && kit.title_en ? kit.title_en : kit.title_ar
  const interactive = { tool, color, size: 3, onStrokes: setStrokes, onPointer: movePointer, onZoom: (z: Show['zoom']) => setShow((s) => ({ ...s, zoom: z })) }
  const noteText = source.notes(show.index)
  const tools: { id: Tool; icon: typeof PenLine; label: string; key: string }[] = [
    { id: 'laser', icon: MousePointer2, label: t('teach.present.laser'), key: 'L' }, { id: 'spot', icon: ScanSearch, label: t('teach.present.spot'), key: 'S' },
    { id: 'pen', icon: PenLine, label: t('teach.present.pen'), key: 'P' }, { id: 'marker', icon: Highlighter, label: t('teach.present.marker'), key: 'H' }, { id: 'zoom', icon: ZoomIn, label: t('teach.present.zoom'), key: 'Z' },
  ]
  const Dock = (
    <div className="flex flex-wrap items-center justify-center gap-2 rounded-2xl bg-white/10 px-3 py-2 backdrop-blur">
      <IconBtn label={t('teach.previous')} onClick={() => go(-1)}><ChevronRight className="size-5 ltr:rotate-180" /></IconBtn>
      <span className="min-w-16 rounded-full bg-black/30 px-3 py-1 text-center text-sm font-semibold tabular-nums" dir="ltr">{jump ? `${jump}…` : `${show.index + 1} / ${count}`}</span>
      <IconBtn label={t('teach.next')} onClick={() => go(1)}><ChevronLeft className="size-5 ltr:rotate-180" /></IconBtn>
      <span className="mx-1 h-6 w-px bg-white/20" />
      {tools.map((x) => <IconBtn key={x.id} label={`${x.label} (${x.key})`} active={tool === x.id} onClick={() => setTool((c) => (c === x.id ? 'none' : x.id))}><x.icon className="size-5" /></IconBtn>)}
      {(tool === 'pen' || tool === 'marker') && COLORS.map((c) => <button key={c} type="button" aria-label={c} onClick={() => setColor(c)} className={clsx('size-5 rounded-full ring-2', color === c ? 'ring-gold-300' : 'ring-white/20')} style={{ background: c }} />)}
      <IconBtn label={`${t('teach.present.clearInk')} (E)`} onClick={clearInk}><Eraser className="size-5" /></IconBtn>
      <span className="mx-1 h-6 w-px bg-white/20" />
      <IconBtn label={`${t('teach.present.black')} (B)`} active={show.blackout === 'black'} onClick={() => blackout('black')}><Square className="size-5 fill-current" /></IconBtn>
      <IconBtn label={`${t('teach.present.white')} (W)`} active={show.blackout === 'white'} onClick={() => blackout('white')}><SquareDashed className="size-5" /></IconBtn>
      <IconBtn label={`${t('teach.present.sorter')} (G)`} active={grid} onClick={() => setGrid((g) => !g)}><Grid3x3 className="size-5" /></IconBtn>
    </div>
  )

  return (
    <Shield settings={kit.protection} viewer={kit.viewer} surface="presenter" kitId={kit.id} fileId={fileId} programId={program || kit.program_id} sessionId={session?.id} sparse className="fixed inset-0 overflow-hidden bg-navy-950 text-white">
      {mode === 'screen' ? (
        <div className="absolute inset-0 grid place-items-center bg-black" style={{ cursor: dock ? 'default' : 'none' }}>
          <Stage source={source} show={show} pointer={pointer} width={window.innerWidth} height={window.innerHeight} interactive={interactive} />
          <div className={clsx('absolute inset-x-0 bottom-4 z-40 flex justify-center transition-opacity', dock ? 'opacity-100' : 'pointer-events-none opacity-0')}>{Dock}</div>
          <div className={clsx('absolute end-4 top-4 z-40 flex gap-2 transition-opacity', dock ? 'opacity-100' : 'pointer-events-none opacity-0')}><IconBtn label={t('teach.present.exitScreen')} onClick={leaveScreen}><X className="size-5" /></IconBtn></div>
        </div>
      ) : (
        <div className="flex h-full flex-col">
          <header className="flex shrink-0 flex-wrap items-center gap-3 border-b border-white/10 bg-navy-900/80 px-4 py-2.5">
            <div className="min-w-0 flex-1"><div className="truncate text-sm font-bold" dir="auto">{title}</div><div className="truncate text-xs text-white/55" dir="auto">{view.data.data.file.name}{detail.data ? ` · ${en ? detail.data.data.title_en : detail.data.data.title_ar}` : ''}</div></div>
            {session && (live
              ? <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/20 px-3 py-1 text-xs font-bold text-emerald-300"><Radio className="size-3.5 animate-pulse" />{t('teach.present.live')}</span>
              : <button type="button" onClick={startSession} className="inline-flex items-center gap-1.5 rounded-full bg-gold-500 px-3 py-1 text-xs font-bold text-navy-950 hover:brightness-110"><Play className="size-3.5" />{t('teach.present.startSession')}</button>)}
            {!live && <span className="rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-white/70">{t('teach.present.rehearsal')}</span>}
            <span className="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1 text-xs tabular-nums" title={t('teach.present.elapsed')} dir="ltr"><Clock className="size-3.5 text-gold-300" />{fmtClock(elapsed)}</span>
            {remaining !== null && <span className={clsx('inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold tabular-nums', remaining < 0 ? 'bg-red-500/25 text-red-200' : remaining < 300000 ? 'bg-amber-500/25 text-amber-200' : 'bg-white/10')} title={t('teach.present.remaining')} dir="ltr"><Timer className="size-3.5" />{remaining < 0 ? `+${fmtClock(-remaining)}` : fmtClock(remaining)}</span>}
            <span className="hidden text-xs tabular-nums text-white/55 sm:inline" dir="ltr">{new Date(now).toLocaleTimeString(en ? 'en-GB' : 'ar-QA-u-nu-latn', { hour: '2-digit', minute: '2-digit' })}</span>
            <button type="button" onClick={openAudience} className={clsx('inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-xs font-bold transition', audience ? 'border-emerald-400/50 bg-emerald-500/15 text-emerald-200' : 'border-white/20 bg-white/10 hover:bg-white/20')}><MonitorUp className="size-4" />{audience ? t('teach.present.audienceOn') : t('teach.present.audience')}</button>
            <button type="button" onClick={enterScreen} className="inline-flex items-center gap-1.5 rounded-xl border border-white/20 bg-white/10 px-3 py-1.5 text-xs font-bold hover:bg-white/20"><Expand className="size-4" />{t('teach.present.presentHere')}</button>
            <button type="button" onClick={finish} className="inline-flex items-center gap-1.5 rounded-xl bg-red-500/85 px-3 py-1.5 text-xs font-bold hover:bg-red-500"><X className="size-4" />{t('teach.present.end')}</button>
          </header>

          <div className="grid min-h-0 flex-1 gap-4 p-4 lg:grid-cols-[1fr_380px]">
            <div className="flex min-h-0 min-w-0 flex-col gap-3">
              <div ref={stageRef} className="grid min-h-0 flex-1 place-items-center overflow-hidden rounded-2xl bg-black/50 ring-1 ring-white/10">
                {source.ready ? <Stage source={source} show={show} pointer={pointer} width={box.w} height={box.h} interactive={interactive} /> : <Spinner />}
              </div>
              {Dock}
            </div>

            <aside className="flex min-h-0 flex-col gap-3 overflow-y-auto pe-1">
              <Panel title={t('teach.present.next')}>
                <div className="grid place-items-center overflow-hidden rounded-xl bg-black">
                  {show.index + 1 < count ? <Stage source={source} show={{ ...INITIAL_SHOW, index: show.index + 1 }} pointer={null} width={340} height={191} /> : <div className="grid h-[191px] place-items-center text-sm text-white/50">{t('teach.present.lastSlide')}</div>}
                </div>
              </Panel>
              <Panel title={t('teach.present.notes')} action={<span className="flex gap-1"><button type="button" aria-label="A−" onClick={() => { const n = Math.max(12, notesSize - 2); setNotesSize(n); LSset('tedc.present.notesSize', String(n)) }} className="rounded bg-white/10 px-2 text-xs">A−</button><button type="button" aria-label="A+" onClick={() => { const n = Math.min(40, notesSize + 2); setNotesSize(n); LSset('tedc.present.notesSize', String(n)) }} className="rounded bg-white/10 px-2 text-xs">A+</button></span>}>
                <div className="max-h-56 overflow-y-auto whitespace-pre-wrap leading-relaxed text-white/90" style={{ fontSize: notesSize }} dir="auto">{noteText || <span className="text-white/40">{t('teach.present.noNotes')}</span>}</div>
              </Panel>
              <Panel title={<span className="inline-flex items-center gap-1.5"><StickyNote className="size-3.5" />{t('teach.present.mine')}</span>}>
                <textarea value={mine[show.index] ?? ''} onChange={(e) => { const v = { ...mine, [show.index]: e.target.value }; setMine(v); LSset(`tedc.present.mine.${fileId}`, JSON.stringify(v)) }} rows={3} placeholder={t('teach.present.minePh')} className="w-full resize-none rounded-lg border border-white/15 bg-black/30 p-2 text-sm text-white placeholder:text-white/35 focus:border-gold-400 focus:outline-none" dir="auto" />
                <p className="mt-1 text-[11px] text-white/40">{t('teach.present.mineHint')}</p>
              </Panel>
              <Panel title={t('teach.present.classroom')}>
                <div className="flex flex-wrap gap-2">
                  <button type="button" onClick={pick} className="inline-flex items-center gap-1.5 rounded-xl bg-white/10 px-3 py-2 text-xs font-bold hover:bg-white/20"><Dices className="size-4 text-gold-300" />{spin ?? t('teach.present.pick')}</button>
                  {[5, 10, 15].map((m) => <button key={m} type="button" onClick={() => startTimer(m)} className="inline-flex items-center gap-1 rounded-xl bg-white/10 px-3 py-2 text-xs font-bold hover:bg-white/20"><Timer className="size-4 text-gold-300" />{t('teach.present.breakBtn', { m })}</button>)}
                  {show.overlay && <button type="button" onClick={clearOverlay} className="rounded-xl bg-red-500/80 px-3 py-2 text-xs font-bold">{t('teach.present.hideCard')}</button>}
                </div>
                <p className="mt-2 text-[11px] text-white/40">{detail.data ? t('teach.present.rosterN', { n: detail.data.data.roster.length }) : ''}</p>
              </Panel>
              <p className="px-1 text-[11px] leading-5 text-white/35">{t('teach.present.keys')}</p>
              <p className="px-1 text-[11px] font-semibold text-gold-300/80">{t('teach.ownership')}</p>
            </aside>
          </div>

          {grid && (
            <div className="absolute inset-0 z-30 overflow-y-auto bg-navy-950/95 p-6" onClick={() => setGrid(false)}>
              <div className="mx-auto grid max-w-6xl grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                {Array.from({ length: count }, (_, i) => (
                  <button key={i} type="button" onClick={(e) => { e.stopPropagation(); goto(i); setGrid(false) }} className={clsx('overflow-hidden rounded-xl ring-2 transition hover:scale-[1.02]', i === show.index ? 'ring-gold-400' : 'ring-white/10')}>
                    {source.thumb ? source.thumb(i, 280) : <div className="grid aspect-video place-items-center bg-white/5 text-2xl font-bold text-white/60">{i + 1}</div>}
                    <div className="bg-black/40 py-1 text-center text-xs tabular-nums">{i + 1}</div>
                  </button>
                ))}
              </div>
            </div>
          )}

          {source.thumb && count > 0 && (
            <footer className="flex shrink-0 gap-2 overflow-x-auto border-t border-white/10 bg-navy-900/60 px-4 py-2">
              {Array.from({ length: count }, (_, i) => (
                <button key={i} type="button" onClick={() => goto(i)} className={clsx('shrink-0 overflow-hidden rounded-lg ring-2 transition', i === show.index ? 'ring-gold-400' : 'ring-transparent opacity-70 hover:opacity-100')}>{source.thumb!(i, 112)}</button>
              ))}
            </footer>
          )}
        </div>
      )}
    </Shield>
  )
}

function IconBtn({ children, label, onClick, active }: { children: React.ReactNode; label: string; onClick: () => void; active?: boolean }) {
  return <button type="button" title={label} aria-label={label} onClick={onClick} className={clsx('grid size-9 place-items-center rounded-xl transition', active ? 'bg-gold-500 text-navy-950' : 'bg-white/10 text-white hover:bg-white/20')}>{children}</button>
}

function Panel({ title, action, children }: { title: React.ReactNode; action?: React.ReactNode; children: React.ReactNode }) {
  return (
    <section className="rounded-2xl border border-white/10 bg-white/[0.04] p-3">
      <div className="mb-2 flex items-center justify-between text-xs font-bold uppercase tracking-wide text-gold-300/90"><span>{title}</span>{action}</div>
      {children}
    </section>
  )
}
