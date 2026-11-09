import { Award, ChevronLeft, ChevronRight, ClipboardCheck, DoorOpen, FlaskConical, GraduationCap, ListChecks, MapPin, Pause, Play, RotateCcw, Sparkles, Target, TrendingUp, type LucideIcon } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'
import CountUp from './CountUp'
import { AMBIENT, ENTRY, FLOOR_ROUTES, PLAN, STAGES, length, pointAt, route, type Floor, type Pt, type Stage } from './campus/campusData'
import CampusPlan from './campus/CampusPlan'

type Lit = Floor | 'both'
const DWELL = 4800          // how long a stage stays before the next one starts on its own
const SPEED = 380           // pixels of the composition per second the walker covers
const BRIDGE_MS = 1150      // the climb between the floors
const GAP_X = 170           // the space between the two plans when they stand side by side
const GAP_Y = 130           // ... and when they are stacked on a phone
const ease = (p: number) => (p < 0.5 ? 4 * p * p * p : 1 - Math.pow(-2 * p + 2, 3) / 2)
const wait = (ms: number) => new Promise<void>((r) => setTimeout(r, ms))
const ICONS: Record<Stage['key'], LucideIcon> = { arrive: DoorOpen, needs: Target, register: ClipboardCheck, train: GraduationCap, practise: FlaskConical, assess: ListChecks, certify: Award, impact: TrendingUp }

function useWide() {
  return useSyncExternalStore(
    (cb) => { const mq = window.matchMedia('(min-width: 1024px)'); mq.addEventListener('change', cb); return () => mq.removeEventListener('change', cb) },
    () => window.matchMedia('(min-width: 1024px)').matches,
    () => true,
  )
}

/** A point on a cubic curve. */
const bez = (a: Pt, c1: Pt, c2: Pt, b: Pt, u: number): Pt => {
  const m = 1 - u
  return [m * m * m * a[0] + 3 * m * m * u * c1[0] + 3 * m * u * u * c2[0] + u * u * u * b[0], m * m * m * a[1] + 3 * m * m * u * c1[1] + 3 * m * u * u * c2[1] + u * u * u * b[1]]
}

/**
 * The training centre on the home page: both floors on stage at once — the day plan of the ground floor beside the night plan of the
 * first — and a trainee who walks the corridors, climbs the stairs between them and lights each room as the stages of the journey
 * (needs → registration → training → practice → assessment → certificate → impact) are told, with live figures.
 * It plays by itself while it is on screen, stops for visitors who ask for reduced motion, and everything can be driven by hand.
 */
export default function CampusJourney() {
  const { t, i18n } = useTranslation()
  const lang = i18n.language === 'en' ? 'en' : 'ar'
  const wide = useWide()
  const stats = useGet<{ data: Record<string, number> }>('/public/stats', undefined, { staleTime: 5 * 60_000 })
  const reduce = useMemo(() => typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches, [])
  const [i, setI] = useState(0)
  const [lit, setLit] = useState<Lit>('ground')
  const [sweep, setSweep] = useState<Record<Floor, number>>({ ground: 0, first: 0 })
  const [linkLive, setLinkLive] = useState(false)
  const [arrived, setArrived] = useState(true)
  const [playing, setPlaying] = useState(!reduce)
  const [onScreen, setOnScreen] = useState(false)
  const [hovering, setHovering] = useState(false)
  const root = useRef<HTMLElement>(null)
  const rail = useRef<HTMLOListElement>(null)
  const stage3d = useRef<HTMLDivElement>(null)
  const token = useRef<SVGGElement>(null)
  const trail = useRef<SVGPolylineElement>(null)
  const at = useRef<{ floor: Floor; node: string } | null>(null)
  const run = useRef(0)
  const stage = STAGES[i]
  const last = i === STAGES.length - 1

  // Where each plan stands in the composition: side by side on a wide screen (the ground floor first in reading order), stacked on a phone (the upper floor on top).
  const geo = useMemo(() => {
    const W = PLAN.w, H = PLAN.h
    if (wide) {
      const first: Pt = lang === 'ar' ? [0, 0] : [W + GAP_X, 0]
      const ground: Pt = lang === 'ar' ? [W + GAP_X, 0] : [0, 0]
      return { w: 2 * W + GAP_X, h: H, off: { ground, first } as Record<Floor, Pt> }
    }
    return { w: W, h: 2 * H + GAP_Y, off: { first: [0, 0], ground: [0, H + GAP_Y] } as Record<Floor, Pt> }
  }, [wide, lang])
  const toG = useCallback((floor: Floor, pts: Pt[]): Pt[] => pts.map((p) => [p[0] + geo.off[floor][0], p[1] + geo.off[floor][1]]), [geo])

  // The stairs between the floors: a curve from the ground floor's stairs up to the first floor's.
  const link = useMemo(() => {
    const a = toG('ground', [FLOOR_ROUTES.ground.nodes.stairs])[0]
    const b = toG('first', [FLOOR_ROUTES.first.nodes.stairs])[0]
    const c1: Pt = wide ? [a[0] + (b[0] - a[0]) * 0.32, a[1] - 190] : [a[0] + 330, a[1] + (b[1] - a[1]) * 0.3]
    const c2: Pt = wide ? [b[0] - (b[0] - a[0]) * 0.32, b[1] - 190] : [b[0] + 330, b[1] - (b[1] - a[1]) * 0.3]
    const pts = Array.from({ length: 41 }, (_, k) => bez(a, c1, c2, b, k / 40))
    return { pts, d: 'M ' + pts.map((p) => p.map((n) => Math.round(n)).join(' ')).join(' L '), a, b }
  }, [toG, wide])

  const place = useCallback((p: Pt | null) => {
    const g = token.current
    if (!g) return
    g.style.opacity = p ? '1' : '0'
    if (p) g.style.transform = `translate(${p[0]}px, ${p[1]}px)`
  }, [])
  const draw = useCallback((pts: Pt[] | null) => { trail.current?.setAttribute('points', pts ? pts.map((p) => p.join(',')).join(' ') : '') }, [])
  const cancel = useCallback(() => { run.current += 1 }, [])

  /** Walks the token along a route in composition coordinates (a rAF loop: the next stage can interrupt it at any frame). */
  const walk = useCallback(async (pts: Pt[], id: number, forced?: number) => {
    const len = length(pts)
    if (reduce || len < 2) { place(pts[pts.length - 1]); draw(null); return }
    const ms = forced ?? Math.max(700, Math.min(2600, (len / SPEED) * 1000))
    await new Promise<void>((resolve) => {
      const t0 = performance.now()
      const tick = (now: number) => {
        if (run.current !== id) return resolve()
        const f = Math.min(1, (now - t0) / ms)
        const p = pointAt(pts, ease(f))
        place(p)
        const done = pts.filter((_, k) => length(pts.slice(0, k + 1)) <= ease(f) * len)   // the way behind the walker glows until it arrives
        draw([...done, p])
        if (f < 1) requestAnimationFrame(tick); else resolve()
      }
      requestAnimationFrame(tick)
    })
  }, [draw, place, reduce])

  const light = useCallback((f: Lit) => { setLit(f); if (f !== 'both') setSweep((s) => ({ ...s, [f]: s[f] + 1 })) }, [])

  // Go to a stage: walk the corridors, climb the stairs when the floor changes, then show the room.
  useEffect(() => {
    const id = ++run.current
    const s = STAGES[i]
    void (async () => {
      setArrived(false)
      if (s.view === 'campus') {
        place(null); draw(null); at.current = null; setLinkLive(false)
        setLit('both')
        if (!reduce) await wait(450)
        if (run.current === id) setArrived(true)
        return
      }
      const floor = s.view as Floor
      const nodes = FLOOR_ROUTES[floor].nodes
      if (!at.current) {
        // a first visit starts outside the building and walks in through the door
        light(floor)
        at.current = { floor, node: ENTRY[floor] }
        const door = toG(floor, [nodes[ENTRY[floor]]])[0]
        const outside: Pt = [door[0], geo.off[floor][1] + PLAN.h - 24]
        place(reduce ? door : outside)
        if (!reduce) { await wait(450); await walk([outside, door], id) }
      } else if (at.current.floor !== floor) {
        const from = at.current
        await walk(toG(from.floor, route(from.floor, from.node, 'stairs')), id)
        if (run.current !== id) return
        light(floor); setLinkLive(true)
        await walk(from.floor === 'ground' ? link.pts : [...link.pts].reverse(), id, BRIDGE_MS)
        setLinkLive(false)
        if (run.current !== id) return
        at.current = { floor, node: 'stairs' }
        draw(null)
        if (!reduce) await wait(260)
      } else {
        light(floor)
      }
      if (run.current !== id) return
      const from = at.current!
      await walk(toG(floor, route(floor, from.node, s.node!)), id)
      if (run.current !== id) return
      at.current = { floor, node: s.node! }
      draw(null)
      if (run.current === id) { place(toG(floor, [nodes[s.node!]])[0]); setArrived(true) }
    })()
    return cancel
  }, [i, draw, place, reduce, walk, cancel, light, toG, link, geo])

  // It plays only while it is on screen, the tab is visible and nobody is pointing at it.
  useEffect(() => {
    const el = root.current
    if (!el || !('IntersectionObserver' in window)) { setOnScreen(true); return }
    const io = new IntersectionObserver(([e]) => setOnScreen(e.isIntersecting), { threshold: 0.3 })
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

  // On a narrow screen the rail scrolls sideways: the stage being told is kept in view (the rail only, never the page).
  useEffect(() => {
    const list = rail.current
    const now = list?.querySelector<HTMLElement>('[aria-current="step"]')?.closest('li')
    if (list && now && list.scrollWidth > list.clientWidth) list.scrollTo({ left: now.offsetLeft - (list.clientWidth - now.offsetWidth) / 2, behavior: 'smooth' })
  }, [i])

  // The composition leans a little towards the pointer (wide screens, when motion is welcome).
  const tilt = (e: React.PointerEvent<HTMLDivElement>) => {
    const el = stage3d.current
    if (!el || reduce || !wide || e.pointerType === 'touch') return
    const r = el.getBoundingClientRect()
    const x = (e.clientX - r.left) / r.width - 0.5
    const y = (e.clientY - r.top) / r.height - 0.5
    el.style.setProperty('--ry', `${(x * 5).toFixed(2)}deg`)
    el.style.setProperty('--rx', `${(-y * 4).toFixed(2)}deg`)
  }
  const untilt = () => { stage3d.current?.style.setProperty('--ry', '0deg'); stage3d.current?.style.setProperty('--rx', '0deg') }

  const go = (n: number) => { setPlaying(false); setI((n + STAGES.length) % STAGES.length) }
  const stat = stats.data?.data[stage.stat]
  const fmtStat = (n: number) => (stage.stat === 'satisfaction' ? fmt.percent(n) : fmt.number(Math.round(n)))
  const svgPath = (pts: Pt[]) => 'M ' + pts.map((p) => p.join(' ')).join(' L ')
  const pin = stage.pin && stage.view !== 'campus' ? toG(stage.view as Floor, [stage.pin])[0] : null
  const Icon = ICONS[stage.key]
  const pos = (x: number, y: number, w: number, h: number) => ({ left: `${(x / geo.w) * 100}%`, top: `${(y / geo.h) * 100}%`, width: `${(w / geo.w) * 100}%`, height: `${(h / geo.h) * 100}%` })

  return (
    <section ref={root} aria-labelledby="campus-title" className="relative isolate overflow-hidden bg-navy-950 py-24 text-white">
      {/* the centre at night, very quietly, behind everything */}
      <img src="/campus/campus-night.jpg" alt="" aria-hidden loading="lazy" decoding="async" draggable={false} className={`absolute inset-0 -z-10 size-full select-none object-cover opacity-[0.16] ${reduce ? '' : 'campus-drift'}`} />
      <div className="absolute inset-0 -z-10 bg-[radial-gradient(ellipse_at_50%_0%,rgba(201,162,75,.18),transparent_60%),linear-gradient(to_bottom,var(--color-navy-950),transparent_30%,var(--color-navy-950))]" aria-hidden />
      <div className="pattern-bg absolute inset-0 -z-10 opacity-[0.05]" aria-hidden />

      <div className="container-x relative">
        <header className="mx-auto max-w-3xl text-center">
          <span className="inline-flex items-center gap-2 rounded-full border border-gold-400/40 bg-gold-400/10 px-4 py-1.5 text-xs font-bold tracking-wide text-gold-300"><Sparkles className="size-3.5" aria-hidden />{t('campus.eyebrow')}</span>
          <h2 id="campus-title" className="mt-5 font-display text-3xl font-bold leading-tight text-white sm:text-5xl">{t('campus.title')}</h2>
          <p className="mx-auto mt-4 max-w-2xl leading-8 text-slate-300">{t('campus.text')}</p>
        </header>

        {/* The journey: eight stages on one line, the finished ones in gold */}
        <ol ref={rail} className="relative mx-auto mt-12 flex max-w-5xl snap-x gap-1 overflow-x-auto pb-3 [scrollbar-width:none]" aria-label={String(t('campus.stepsLabel'))}>
          <span className="absolute inset-x-8 top-[1.35rem] h-px bg-white/15" aria-hidden />
          <span className="absolute start-8 top-[1.35rem] h-px bg-gradient-to-r from-gold-300 to-gold-500 transition-[width] duration-700 ease-out rtl:bg-gradient-to-l" style={{ width: `calc((100% - 4rem) * ${i / (STAGES.length - 1)})` }} aria-hidden />
          {STAGES.map((s, n) => {
            const I = ICONS[s.key]
            const state = n === i ? 'now' : n < i ? 'done' : 'next'
            return (
              <li key={s.key} className="relative min-w-[5.2rem] flex-1 snap-center">
                <button type="button" onClick={() => go(n)} aria-current={n === i ? 'step' : undefined} title={String(t(`campus.stages.${s.key}.title`))} className="group flex w-full flex-col items-center gap-2 px-1 text-center focus-visible:outline-none">
                  <span className={`relative grid size-11 place-items-center rounded-full border transition-all duration-500 group-focus-visible:ring-2 group-focus-visible:ring-gold-300 ${state === 'now' ? 'scale-110 border-gold-300 bg-gold-500 text-navy-950 shadow-[0_0_0_6px_rgba(201,162,75,.18),0_10px_30px_-6px_rgba(201,162,75,.7)]' : state === 'done' ? 'border-gold-500/70 bg-navy-900 text-gold-300' : 'border-white/20 bg-navy-900 text-slate-400 group-hover:border-gold-400/60 group-hover:text-gold-200'}`}>
                    <I className="size-5" aria-hidden />
                    {state === 'now' && !reduce && <span className="absolute inset-0 animate-ping rounded-full bg-gold-400/30" aria-hidden />}
                  </span>
                  <span className={`line-clamp-2 text-[11px] font-semibold leading-tight transition-colors duration-300 ${state === 'now' ? 'text-gold-200' : state === 'done' ? 'text-slate-300' : 'text-slate-500'}`}>{t(`campus.stages.${s.key}.title`)}</span>
                </button>
              </li>
            )
          })}
        </ol>

        {/* Both floors, on stage together */}
        <div onPointerMove={tilt} onPointerLeave={() => { untilt(); setHovering(false) }} onPointerEnter={() => setHovering(true)} onFocus={() => setHovering(true)} onBlur={() => setHovering(false)}
          className="mt-8 [perspective:1800px]" dir="ltr">
          <div ref={stage3d} className="relative w-full transition-transform duration-300 ease-out [transform-style:preserve-3d]" style={{ aspectRatio: `${geo.w} / ${geo.h}`, transform: 'rotateX(var(--rx,0deg)) rotateY(var(--ry,0deg))' }}>
            {(['ground', 'first'] as Floor[]).map((f, k) => {
              const on = lit === 'both' || lit === f
              const o = geo.off[f]
              return (
                <div key={f} style={pos(o[0], o[1], PLAN.w, PLAN.h)} aria-hidden={false}
                  className={`absolute overflow-hidden rounded-[1.6rem] border transition-all duration-700 ease-out ${f === 'ground' ? 'bg-gradient-to-br from-white to-[#efe9e1]' : 'bg-navy-950'} ${on ? 'border-gold-400/70 opacity-100 shadow-[0_40px_90px_-30px_rgba(201,162,75,.55)]' : 'border-white/10 opacity-[.62] saturate-[.8]'} ${!reduce && onScreen ? (k ? 'cj-float-b' : 'cj-float-a') : ''}`}>
                  <CampusPlan floor={f} lang={lang} />
                  {/* a band of light crosses a floor each time the trainee arrives on it */}
                  {!reduce && sweep[f] > 0 && <span key={sweep[f]} className="cj-sweep pointer-events-none absolute inset-0" aria-hidden />}
                  <div className="pointer-events-none absolute left-3 top-3 flex items-center gap-2 rounded-full border border-white/15 bg-navy-950/80 py-1 pe-3.5 ps-1.5 text-white shadow-glass backdrop-blur" dir="auto">
                    <span className={`grid size-7 place-items-center rounded-full font-display text-xs font-bold transition-colors duration-500 ${on ? 'bg-gold-500 text-navy-950' : 'bg-white/10 text-slate-300'}`}>{f === 'ground' ? '00' : '01'}</span>
                    <span className="text-xs font-bold">{t(`campus.floors.${f}`)}</span>
                    {on && <span className="relative flex size-2" aria-hidden><span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400/70" /><span className="relative size-2 rounded-full bg-emerald-400" /></span>}
                  </div>
                </div>
              )
            })}

            {/* the stairs, the walkers and the trainee, over both floors */}
            <svg viewBox={`0 0 ${geo.w} ${geo.h}`} className="pointer-events-none absolute inset-0 size-full overflow-visible" aria-hidden>
              <defs>
                <radialGradient id="cj-glow"><stop offset="0" stopColor="var(--color-gold-400)" stopOpacity=".95" /><stop offset="1" stopColor="var(--color-gold-400)" stopOpacity="0" /></radialGradient>
                <filter id="cj-blur" x="-20%" y="-20%" width="140%" height="140%"><feGaussianBlur stdDeviation="9" /></filter>
              </defs>
              {/* the way between the floors: a faint dashed curve that lights up while the trainee climbs it */}
              <path d={link.d} fill="none" stroke="var(--color-gold-400)" strokeWidth="5" strokeLinecap="round" strokeDasharray="2 16" opacity={linkLive ? 0 : 0.5} style={{ transition: 'opacity 300ms' }} />
              <path d={link.d} fill="none" stroke="var(--color-gold-300)" strokeWidth="14" strokeLinecap="round" filter="url(#cj-blur)" opacity={linkLive ? 0.85 : 0} style={{ transition: 'opacity 300ms' }} />
              <path d={link.d} fill="none" stroke="#fff" strokeWidth="5" strokeLinecap="round" strokeDasharray="22 18" opacity={linkLive ? 1 : 0} className={linkLive && !reduce ? 'cj-flow' : ''} style={{ transition: 'opacity 300ms' }} />
              {[link.a, link.b].map((p, k) => (
                <g key={k} transform={`translate(${p[0]} ${p[1]})`}>
                  <circle r="26" fill="none" stroke="var(--color-gold-400)" strokeWidth="3" opacity=".7" />
                  <circle r="9" fill="var(--color-gold-400)" />
                </g>
              ))}
              {!reduce && onScreen && (['ground', 'first'] as Floor[]).flatMap((f) => AMBIENT[f].map((key, k) => {
                const [a, b] = key.split('>')
                return (
                  <circle key={`${f}-${key}`} r="7" fill={k % 2 ? '#fff' : 'var(--color-navy-700)'} stroke="var(--color-gold-500)" strokeWidth="2.5" opacity=".9">
                    <animateMotion dur={`${16 + k * 5}s`} begin={`${-k * 4.3}s`} repeatCount="indefinite" path={svgPath(toG(f, route(f, a, b)))} keyPoints="0;1;0" keyTimes="0;.5;1" calcMode="linear" />
                  </circle>
                )
              }))}
              <polyline ref={trail} fill="none" stroke="var(--color-gold-400)" strokeWidth="6" strokeLinecap="round" strokeLinejoin="round" strokeDasharray="1 12" opacity=".95" />
              <g ref={token} style={{ opacity: 0, transition: 'opacity 250ms ease-out' }}>
                <circle r="54" fill="url(#cj-glow)" />
                <circle r="20" fill="var(--color-navy-900)" stroke="#fff" strokeWidth="5" />
                <circle r="7" fill="var(--color-gold-300)" />
                {!reduce && <circle r="22" fill="none" stroke="var(--color-gold-300)" strokeWidth="3"><animate attributeName="r" values="22;48" dur="1.8s" repeatCount="indefinite" /><animate attributeName="opacity" values=".8;0" dur="1.8s" repeatCount="indefinite" /></circle>}
              </g>
            </svg>

            {/* the room the walker reached */}
            {pin && (
              <div style={{ left: `${(pin[0] / geo.w) * 100}%`, top: `${(pin[1] / geo.h) * 100}%` }} className={`absolute z-10 -translate-x-1/2 -translate-y-full transition-[opacity,transform] duration-300 ease-out ${arrived ? 'opacity-100' : 'translate-y-[-90%] opacity-0'}`}>
                <div className="relative flex flex-col items-center" dir="auto">
                  <span className="flex items-center gap-1.5 whitespace-nowrap rounded-full bg-gold-500 px-2.5 py-1 text-[10px] font-extrabold text-navy-950 shadow-gold sm:px-4 sm:py-1.5 sm:text-xs"><Icon className="size-3.5" aria-hidden />{t(`campus.stages.${stage.key}.place`)}</span>
                  <span className="h-3 w-0.5 bg-gold-500" aria-hidden />
                  <span className="relative grid size-3 place-items-center" aria-hidden><span className="absolute size-3 animate-ping rounded-full bg-gold-400/70" /><span className="size-2.5 rounded-full bg-gold-400 ring-2 ring-navy-950" /></span>
                </div>
              </div>
            )}
            {last && arrived && (
              <div className="absolute inset-x-0 top-1/2 z-20 mx-auto w-max max-w-[88%] -translate-y-1/2 campus-pop" dir="auto">
                <div className="flex items-center gap-4 rounded-3xl border border-gold-300/60 bg-navy-950/85 px-6 py-4 text-start shadow-[0_30px_80px_-20px_rgba(201,162,75,.6)] backdrop-blur-md">
                  <span className="grid size-14 shrink-0 place-items-center rounded-2xl bg-gold-500 text-navy-950"><Award className="size-7" aria-hidden /></span>
                  <div><div className="font-display text-lg font-bold text-gold-200 sm:text-2xl">{t('campus.done')}</div><div className="mt-0.5 text-xs text-slate-300 sm:text-sm">{t('campus.doneText')}</div></div>
                </div>
              </div>
            )}
          </div>
        </div>

        {/* The story of the stage, its live figure and the controls */}
        <div className="mt-8 grid gap-4 lg:grid-cols-[minmax(0,15rem)_minmax(0,1fr)_auto] lg:items-stretch" aria-live={playing ? 'off' : 'polite'}>
          <div className="relative overflow-hidden rounded-3xl border border-gold-400/30 bg-gradient-to-br from-gold-500/15 to-transparent p-5 backdrop-blur" dir="auto">
            <div className="flex items-center gap-1.5 text-[11px] font-bold text-emerald-300"><span className="relative flex size-2"><span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400/70" /><span className="relative size-2 rounded-full bg-emerald-400" /></span>{t('campus.live')}</div>
            <div className="mt-2 font-display text-4xl font-bold leading-none text-gold-200"><CountUp key={stage.key} value={stat} format={fmtStat} /></div>
            <div className="mt-2 text-sm text-slate-300">{t(`campus.stats.${stage.stat}`)}</div>
          </div>

          <div className="rounded-3xl border border-white/10 bg-white/[0.04] p-5 backdrop-blur sm:p-6">
            <div className="flex items-center justify-between text-xs font-bold text-gold-300">
              <span>{t('campus.step', { n: i + 1, total: STAGES.length })}</span>
              <span className="inline-flex items-center gap-1.5 text-slate-400"><MapPin className="size-3.5" aria-hidden />{t(`campus.stages.${stage.key}.place`)}</span>
            </div>
            <div className="mt-2.5 h-1 overflow-hidden rounded-full bg-white/10" aria-hidden>
              <div key={`${i}-${running}`} className="h-full origin-left rounded-full bg-gradient-to-r from-gold-300 to-gold-500 rtl:origin-right" style={{ transform: `scaleX(${running && arrived ? 1 : arrived ? (i + 1) / STAGES.length : 0})`, transition: running && arrived ? `transform ${DWELL}ms linear` : 'transform 300ms ease-out' }} />
            </div>
            <h3 className="mt-4 font-display text-xl font-bold text-white sm:text-2xl">{t(`campus.stages.${stage.key}.title`)}</h3>
            <p className="mt-2 leading-8 text-slate-300">{t(`campus.stages.${stage.key}.text`)}</p>
            {stage.link && <Link to={stage.link} className="mt-3 inline-flex text-sm font-bold text-gold-300 underline-offset-4 hover:text-gold-200 hover:underline">{t('campus.open')}</Link>}
          </div>

          <div className="flex items-center justify-center gap-2 rounded-3xl border border-white/10 bg-white/[0.04] p-4 backdrop-blur lg:flex-col">
            <button type="button" onClick={() => setPlaying((p) => !p)} disabled={reduce} aria-label={String(t(playing ? 'campus.pause' : 'campus.play'))} className="grid size-12 place-items-center rounded-full bg-gold-500 text-navy-950 shadow-gold transition hover:bg-gold-400 active:scale-95 disabled:opacity-40">
              {playing && !reduce ? <Pause className="size-5" /> : <Play className="size-5" />}
            </button>
            <div className="flex gap-2 lg:flex-row">
              <button type="button" onClick={() => go(i - 1)} aria-label={String(t('campus.prev'))} className="grid size-10 place-items-center rounded-full border border-white/20 text-white transition hover:border-gold-400 hover:text-gold-200 active:scale-95"><ChevronRight className="size-5 ltr:rotate-180" /></button>
              <button type="button" onClick={() => go(i + 1)} aria-label={String(t('campus.next'))} className="grid size-10 place-items-center rounded-full border border-white/20 text-white transition hover:border-gold-400 hover:text-gold-200 active:scale-95"><ChevronLeft className="size-5 ltr:rotate-180" /></button>
            </div>
            {last && <button type="button" onClick={() => { go(0); setPlaying(!reduce) }} className="inline-flex items-center gap-1.5 rounded-full px-3 py-2 text-xs font-bold text-gold-300 hover:bg-white/10"><RotateCcw className="size-4" />{t('campus.replay')}</button>}
          </div>
        </div>
        <p className="mt-5 text-center text-[11px] text-slate-500" dir="auto">{t('campus.illustration')}</p>
      </div>
    </section>
  )
}
