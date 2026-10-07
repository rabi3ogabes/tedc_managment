import { Award, ChevronLeft, ChevronRight, MapPin, Pause, Play, RotateCcw, Sparkles } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'
import CountUp from './CountUp'
import { AMBIENT, ENTRY, FLOOR_ROUTES, PLAN, STAGES, length, pointAt, route, type Floor, type Pt } from './campus/campusData'
import CampusPlan from './campus/CampusPlan'
import { SectionTitle } from './Section'

type View = Floor | 'night'
const DWELL = 4800          // how long a stage stays before the next one starts on its own
const SPEED = 360           // pixels of the plan per second the walker covers
const ease = (p: number) => (p < 0.5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2)
const pct = (p: Pt) => ({ left: `${(p[0] / PLAN.w) * 100}%`, top: `${(p[1] / PLAN.h) * 100}%` })
const wait = (ms: number) => new Promise<void>((r) => setTimeout(r, ms))

/**
 * An animated tour of the training centre on the real floor plans: a trainee walks the corridors from room to room while the stages of
 * the training journey (needs → registration → training → practice → assessment → certificate → impact) light up with live figures.
 * Plays by itself while it is on screen; it stops for visitors who ask for reduced motion, and everything can be driven by hand.
 */
export default function CampusJourney() {
  const { t, i18n } = useTranslation()
  const lang = i18n.language === 'en' ? 'en' : 'ar'
  const stats = useGet<{ data: Record<string, number> }>('/public/stats', undefined, { staleTime: 5 * 60_000 })
  const reduce = useMemo(() => typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches, [])
  const [i, setI] = useState(0)
  const [view, setView] = useState<View>('ground')
  const [arrived, setArrived] = useState(true)
  const [playing, setPlaying] = useState(!reduce)
  const [onScreen, setOnScreen] = useState(false)
  const [hovering, setHovering] = useState(false)
  const root = useRef<HTMLElement>(null)
  const token = useRef<SVGGElement>(null)
  const trail = useRef<SVGPolylineElement>(null)
  const at = useRef<{ floor: Floor; node: string } | null>(null)
  const run = useRef(0)
  const stage = STAGES[i]
  const last = i === STAGES.length - 1

  const place = useCallback((p: Pt | null) => {
    const g = token.current
    if (!g) return
    g.style.opacity = p ? '1' : '0'
    if (p) g.style.transform = `translate(${p[0]}px, ${p[1]}px)`
  }, [])
  const draw = useCallback((pts: Pt[] | null) => { trail.current?.setAttribute('points', pts ? pts.map((p) => p.join(',')).join(' ') : '') }, [])

  /** Stops whatever walk is running (a newer stage, or leaving the page). */
  const cancel = useCallback(() => { run.current += 1 }, [])

  /** Walks the token along a route (a rAF loop: it can be interrupted at any frame by the next stage). */
  const walk = useCallback(async (pts: Pt[], id: number) => {
    const len = length(pts)
    if (reduce || len < 2) { place(pts[pts.length - 1]); draw(null); return }
    const ms = Math.max(700, Math.min(2600, (len / SPEED) * 1000))
    await new Promise<void>((resolve) => {
      const t0 = performance.now()
      const tick = (now: number) => {
        if (run.current !== id) return resolve()
        const f = Math.min(1, (now - t0) / ms)
        const p = pointAt(pts, ease(f))
        place(p)
        // the route behind the walker glows until it arrives
        const done = pts.filter((_, k) => length(pts.slice(0, k + 1)) <= ease(f) * len)
        draw([...done, p])
        if (f < 1) requestAnimationFrame(tick); else resolve()
      }
      requestAnimationFrame(tick)
    })
  }, [draw, place, reduce])

  // Go to a stage: switch the plan, walk the corridors (via the stairs when the floor changes), then show the room.
  useEffect(() => {
    const id = ++run.current
    const s = STAGES[i]
    void (async () => {
      setArrived(false)
      if (s.view === 'campus') {
        place(null); draw(null); at.current = null
        setView('night')
        if (!reduce) await wait(450)
        if (run.current === id) setArrived(true)
        return
      }
      const floor = s.view as Floor
      const nodes = FLOOR_ROUTES[floor].nodes
      if (!at.current) {
        setView(floor)
        at.current = { floor, node: ENTRY[floor] }
        // a first visit starts outside the building and walks in through the door
        const door = nodes[ENTRY[floor]]
        const outside: Pt = [door[0], PLAN.h - 24]
        place(reduce ? door : outside)
        if (!reduce) { await wait(450); await walk([outside, door], id) }
      } else if (at.current.floor !== floor) {
        const from = at.current
        await walk(route(from.floor, from.node, 'stairs'), id)
        if (run.current !== id) return
        setView(floor); at.current = { floor, node: 'stairs' }
        place(nodes.stairs); draw(null)
        if (!reduce) await wait(420)
      } else {
        setView(floor)
      }
      if (run.current !== id) return
      const from = at.current!
      await walk(route(floor, from.node, s.node!), id)
      if (run.current !== id) return
      at.current = { floor, node: s.node! }
      draw(null)
      if (run.current === id) { place(nodes[s.node!]); setArrived(true) }
    })()
    return cancel
  }, [i, draw, place, reduce, walk, cancel])

  // It plays only while it is on screen, the tab is visible and nobody is pointing at it.
  useEffect(() => {
    const el = root.current
    if (!el || !('IntersectionObserver' in window)) { setOnScreen(true); return }
    const io = new IntersectionObserver(([e]) => setOnScreen(e.isIntersecting), { threshold: 0.35 })
    io.observe(el)
    return () => io.disconnect()
  }, [])
  const running = playing && onScreen && !hovering && !reduce
  useEffect(() => {
    if (!running || !arrived) return
    const timer = setTimeout(() => setI((n) => (n + 1) % STAGES.length), DWELL)
    return () => clearTimeout(timer)
  }, [running, arrived, i])
  useEffect(() => {
    const vis = () => { if (document.hidden) setPlaying(false) }
    document.addEventListener('visibilitychange', vis)
    return () => document.removeEventListener('visibilitychange', vis)
  }, [])

  const go = (n: number) => { setPlaying(false); setI((n + STAGES.length) % STAGES.length) }
  const stat = stats.data?.data[stage.stat]
  const floorOf = (v: View) => (v === 'ground' || v === 'first' ? v : null)
  const jumpToFloor = (f: Floor) => { const n = STAGES.findIndex((s) => s.view === f); if (n >= 0) go(n) }
  const svgPath = (pts: Pt[]) => 'M ' + pts.map((p) => p.join(' ')).join(' L ')
  const fmtStat = (n: number) => (stage.stat === 'satisfaction' ? fmt.percent(n) : fmt.number(Math.round(n)))

  return (
    <section ref={root} aria-labelledby="campus-title" className="relative overflow-hidden bg-gradient-to-b from-ivory to-white py-24">
      <div className="pattern-bg absolute inset-0 opacity-[0.06]" aria-hidden />
      <div className="container-x relative">
        <div className="max-w-2xl"><SectionTitle eyebrow={t('campus.eyebrow')} title={t('campus.title')} text={t('campus.text')} /></div>
        <span id="campus-title" className="sr-only">{t('campus.title')}</span>

        <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1.75fr)_minmax(0,1fr)]">
          {/* The plan */}
          <div onMouseEnter={() => setHovering(true)} onMouseLeave={() => setHovering(false)} onFocus={() => setHovering(true)} onBlur={() => setHovering(false)}
            className="relative overflow-hidden rounded-[2rem] border border-navy-100 bg-white shadow-glass" dir="ltr">
            <div className="relative w-full overflow-hidden bg-[radial-gradient(ellipse_at_50%_40%,#fff,var(--color-ivory-dark,#efe9e1))]" style={{ aspectRatio: `${PLAN.w} / ${PLAN.h}` }}>
              {(['ground', 'first'] as Floor[]).map((f) => (
                <div key={f} aria-hidden={view !== f} className={`absolute inset-0 transition-opacity duration-[450ms] ease-out ${view === f ? 'opacity-100' : 'pointer-events-none opacity-0'}`}>
                  <CampusPlan floor={f} lang={lang} />
                </div>
              ))}
              <img src="/campus/campus-night.jpg" alt={String(t('campus.planAlt.night'))} aria-hidden={view !== 'night'} loading="lazy" decoding="async" draggable={false}
                className={`absolute inset-0 size-full select-none object-cover transition-opacity duration-[450ms] ease-out ${view === 'night' ? `opacity-100 ${reduce ? '' : 'campus-drift'}` : 'opacity-0'}`} />

              {/* routes, walkers and the trainee */}
              <svg viewBox={`0 0 ${PLAN.w} ${PLAN.h}`} className={`absolute inset-0 size-full transition-opacity duration-300 ${floorOf(view) ? 'opacity-100' : 'opacity-0'}`} aria-hidden>
                <defs>
                  <radialGradient id="cj-glow"><stop offset="0" stopColor="var(--color-gold-400)" stopOpacity=".95" /><stop offset="1" stopColor="var(--color-gold-400)" stopOpacity="0" /></radialGradient>
                </defs>
                {!reduce && floorOf(view) && AMBIENT[view as Floor].map((key, k) => {
                  const [a, b] = key.split('>')
                  return (
                    <circle key={`${view}-${key}`} r="7" fill={k % 2 ? '#fff' : 'var(--color-navy-700)'} stroke="var(--color-gold-500)" strokeWidth="2.5" opacity=".9">
                      <animateMotion dur={`${16 + k * 5}s`} begin={`${-k * 4.3}s`} repeatCount="indefinite" path={svgPath(route(view as Floor, a, b))} keyPoints="0;1;0" keyTimes="0;.5;1" calcMode="linear" />
                    </circle>
                  )
                })}
                <polyline ref={trail} fill="none" stroke="var(--color-gold-500)" strokeWidth="5" strokeLinecap="round" strokeLinejoin="round" strokeDasharray="1 11" opacity=".95" />
                <g ref={token} style={{ opacity: 0, transition: 'opacity 250ms ease-out' }}>
                  <circle r="46" fill="url(#cj-glow)" />
                  <circle r="19" fill="var(--color-navy-900)" stroke="#fff" strokeWidth="5" />
                  <circle r="7" fill="var(--color-gold-300)" />
                </g>
              </svg>

              {/* the room the walker reached */}
              {stage.pin && floorOf(view) && (
                <div style={pct(stage.pin)} className={`absolute z-10 -translate-x-1/2 -translate-y-full transition-[opacity,transform] duration-200 ease-out ${arrived ? 'opacity-100' : 'translate-y-[-90%] opacity-0'}`}>
                  <div className="relative flex flex-col items-center" dir="auto">
                    <span className="whitespace-nowrap rounded-full bg-navy-900 px-2 py-1 text-[10px] font-bold text-white shadow-glass sm:px-3 sm:py-1.5 sm:text-xs">
                      <MapPin className="me-1 inline size-3.5 text-gold-300" aria-hidden />{t(`campus.stages.${stage.key}.place`)}
                    </span>
                    <span className="h-3 w-0.5 bg-navy-900" aria-hidden />
                    <span className="relative grid size-3 place-items-center" aria-hidden><span className="absolute size-3 animate-ping rounded-full bg-gold-500/70" /><span className="size-2.5 rounded-full bg-gold-500 ring-2 ring-white" /></span>
                  </div>
                </div>
              )}
              {!floorOf(view) && (
                <div className={`absolute inset-x-0 bottom-0 z-10 bg-gradient-to-t from-black/60 to-transparent px-5 pb-4 pt-16 text-white transition-opacity duration-300 ${arrived ? 'opacity-100' : 'opacity-0'}`} dir="auto">
                  <span className="rounded-full bg-white/15 px-3 py-1 text-xs font-bold backdrop-blur"><MapPin className="me-1 inline size-3.5" aria-hidden />{t(`campus.stages.${stage.key}.place`)}</span>
                </div>
              )}

              {/* chips */}
              <div className="absolute bottom-2 left-2 z-20 flex gap-1 rounded-full border border-navy-100 bg-white/90 p-0.5 sm:bottom-3 sm:left-3 sm:gap-1.5 sm:p-1 shadow-glass backdrop-blur" role="group" aria-label={String(t('campus.floorTitle'))} dir="auto">
                {(['ground', 'first'] as Floor[]).map((f) => (
                  <button key={f} type="button" aria-pressed={view === f} onClick={() => jumpToFloor(f)}
                    className={`rounded-full px-2 py-1 text-[10px] font-bold transition-colors duration-150 sm:px-3 sm:text-xs ${view === f ? 'bg-navy-900 text-white' : 'text-navy-800 hover:bg-navy-100/70'}`}>{t(`campus.floors.${f}`)}</button>
                ))}
              </div>
              <div className="absolute left-2 top-2 z-20 min-w-[5.5rem] rounded-xl border border-navy-100 bg-white/92 px-2.5 py-1.5 shadow-glass backdrop-blur sm:left-3 sm:top-3 sm:min-w-[8.5rem] sm:rounded-2xl sm:px-3.5 sm:py-2.5" dir="auto" aria-live="off">
                <div className="flex items-center gap-1.5 text-[10px] font-bold text-emerald-700"><span className="relative flex size-2"><span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-500/70" /><span className="relative size-2 rounded-full bg-emerald-500" /></span>{t('campus.live')}</div>
                <div className="font-display text-lg font-bold leading-tight text-navy-900 sm:text-2xl"><CountUp key={stage.key} value={stat} format={fmtStat} /></div>
                <div className="text-[10px] leading-tight text-slate-500 sm:text-[11px]">{t(`campus.stats.${stage.stat}`)}</div>
              </div>
              {last && arrived && (
                <div className="absolute bottom-3 right-3 z-20 flex w-max max-w-[60%] items-center gap-2 rounded-full bg-gold-500 px-4 py-2 text-xs font-bold text-navy-950 shadow-gold campus-pop" dir="auto">
                  <Award className="size-4" aria-hidden />{t('campus.done')}
                </div>
              )}
            </div>
            <p className="border-t border-navy-100 bg-ivory/70 px-4 py-2 text-center text-[11px] text-slate-500" dir="auto">{t('campus.illustration')}</p>
          </div>

          {/* The journey */}
          <div className="space-y-4">
            <div className="rounded-3xl border border-navy-100 bg-white p-6 shadow-glass" aria-live={playing ? 'off' : 'polite'}>
              <div className="flex items-center justify-between text-xs font-bold text-gold-700">
                <span>{t('campus.step', { n: i + 1, total: STAGES.length })}</span>
                <Sparkles className="size-4" aria-hidden />
              </div>
              <div className="mt-2 h-1 overflow-hidden rounded-full bg-navy-100" aria-hidden>
                <div key={`${i}-${running}`} className="h-full origin-left rounded-full bg-gold-500 rtl:origin-right" style={{ transform: `scaleX(${running && arrived ? 1 : arrived ? (i + 1) / STAGES.length : 0})`, transition: running && arrived ? `transform ${DWELL}ms linear` : 'transform 300ms ease-out' }} />
              </div>
              <h3 className="mt-4 text-xl font-bold text-navy-900">{t(`campus.stages.${stage.key}.title`)}</h3>
              <p className="mt-2 leading-7 text-slate-600">{t(`campus.stages.${stage.key}.text`)}</p>
              <div className="mt-5 flex flex-wrap items-center gap-2">
                <button type="button" onClick={() => go(i - 1)} aria-label={String(t('campus.prev'))} className="grid size-10 place-items-center rounded-full border border-navy-100 text-navy-800 transition hover:border-gold-400 active:scale-95"><ChevronRight className="size-5 ltr:rotate-180" /></button>
                <button type="button" onClick={() => setPlaying((p) => !p)} disabled={reduce} aria-label={String(t(playing ? 'campus.pause' : 'campus.play'))} className="grid size-10 place-items-center rounded-full bg-navy-900 text-white transition hover:bg-navy-800 active:scale-95 disabled:opacity-40">
                  {playing && !reduce ? <Pause className="size-5" /> : <Play className="size-5" />}
                </button>
                <button type="button" onClick={() => go(i + 1)} aria-label={String(t('campus.next'))} className="grid size-10 place-items-center rounded-full border border-navy-100 text-navy-800 transition hover:border-gold-400 active:scale-95"><ChevronLeft className="size-5 ltr:rotate-180" /></button>
                {last && <button type="button" onClick={() => { go(0); setPlaying(!reduce) }} className="inline-flex items-center gap-1.5 rounded-full px-3 py-2 text-xs font-bold text-navy-800 hover:bg-navy-100/60"><RotateCcw className="size-4" />{t('campus.replay')}</button>}
                {stage.link && <Link to={stage.link} className="ms-auto text-sm font-bold text-link hover:opacity-80">{t('campus.open')}</Link>}
              </div>
            </div>

            <ol className="grid grid-cols-4 gap-2 sm:grid-cols-8 lg:grid-cols-4" aria-label={String(t('campus.stepsLabel'))}>
              {STAGES.map((s, n) => (
                <li key={s.key}>
                  <button type="button" onClick={() => go(n)} aria-current={n === i ? 'step' : undefined} title={String(t(`campus.stages.${s.key}.title`))}
                    className={`flex w-full flex-col items-center gap-1 rounded-2xl border px-1.5 py-2.5 text-center transition-colors duration-200 ${n === i ? 'border-gold-500 bg-gold-500/10' : n < i ? 'border-navy-100 bg-navy-900/5' : 'border-navy-100 bg-white hover:border-gold-400'}`}>
                    <span className={`grid size-7 place-items-center rounded-full font-display text-xs font-bold transition-colors duration-200 ${n === i ? 'bg-navy-900 text-gold-300' : n < i ? 'bg-gold-500 text-navy-950' : 'bg-navy-100 text-navy-800'}`}>{n + 1}</span>
                    <span className="line-clamp-2 text-[10px] font-semibold leading-tight text-navy-900 sm:text-[11px]">{t(`campus.stages.${s.key}.title`)}</span>
                  </button>
                </li>
              ))}
            </ol>
          </div>
        </div>
      </div>
    </section>
  )
}
