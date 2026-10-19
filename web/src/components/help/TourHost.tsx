import clsx from 'clsx'
import { Award, BarChart3, Bell, BookOpen, Building2, CalendarCheck, Check, ChevronLeft, ChevronRight, ClipboardCheck, GraduationCap, LayoutDashboard, LifeBuoy, ListChecks, Minimize2, MonitorPlay, PackageOpen, Presentation, Route, School, Search, ShieldCheck, Sparkles, Target, UserCog, Users, Wallet, X } from 'lucide-react'
import { useEffect, useMemo, useState, type ComponentType } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui'
import { api } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { SETTINGS_SECTIONS } from '@/pages/admin/settings/registry'
import { useLang } from './helpApi'
import { TOUR_FEATURES } from './tourFeatures'

type Icon = ComponentType<{ className?: string }>
type ServerStep = { title_ar: string; title_en: string; body_ar: string; body_en: string; target?: string | null; icon?: string; points_ar?: string[]; points_en?: string[]; route?: string | null }
type Tour = { key: string; kind: 'first-login' | 'whats-new'; steps: ServerStep[] }
export type TourGroup = { id?: string; title: string; items: { to: string; label: string; icon: Icon }[] }
type Feature = { to: string; label: string; icon: Icon; desc: string }
type Page = { kind: 'story'; step: ServerStep } | { kind: 'chapter'; id: string; title: string; items: Feature[] } | { kind: 'end' }

const ICONS: Record<string, Icon> = {
  sparkles: Sparkles, search: Search, 'life-buoy': LifeBuoy, bell: Bell, 'user-cog': UserCog, 'book-open': BookOpen, 'calendar-check': CalendarCheck, award: Award, presentation: Presentation,
  'monitor-play': MonitorPlay, 'clipboard-check': ClipboardCheck, users: Users, school: School, target: Target, route: Route, 'package-open': PackageOpen, 'shield-check': ShieldCheck,
  'bar-chart': BarChart3, building: Building2, wallet: Wallet, 'layout-dashboard': LayoutDashboard, 'graduation-cap': GraduationCap,
}
// Older targets name a place instead of a page.
const ALIAS: Record<string, string> = { dashboard: 'nav:/admin', 'my-training': 'nav:/portal/training' }

/**
 * The guided tour — one walk through everything the platform offers the person's role: the shared basics, the story of the role from
 * the first action to the last, then one chapter per section of the menu they can actually open (every page, with what it is for and a
 * button to open it, so a feature they may not use is never shown and none they may use is missed). Shown once per person, and again
 * after a release for what is new; it can be minimised to look at a page and resumed, and replayed from the help centre.
 */
export default function TourHost({ groups = [], portal = false }: { groups?: TourGroup[]; portal?: boolean }) {
  const { t, i18n } = useTranslation()
  const { pick } = useLang()
  const { can } = useAuth()
  const nav = useNavigate()
  const en = i18n.language === 'en'
  const [tour, setTour] = useState<Tour | null>(null)
  const [i, setI] = useState(0)
  const [min, setMin] = useState(false)
  const [hl, setHl] = useState<string | null>(null)

  const load = () => { api.get('/me/tours').then((r) => { setTour(r.data.data ?? null); setI(0); setMin(false) }).catch(() => undefined) }
  useEffect(() => {
    load()
    window.addEventListener('tedc:tours-reset', load)
    return () => window.removeEventListener('tedc:tours-reset', load)
  }, [])

  const pages = useMemo<Page[]>(() => {
    if (!tour) return []
    const story: Page[] = tour.steps.map((step) => ({ kind: 'story', step }))
    if (tour.kind !== 'first-login') return story
    const feat = (to: string, label: string, icon: Icon): Feature => ({ to, label, icon, desc: (TOUR_FEATURES[to]?.[en ? 1 : 0] ?? '').replace(/^[^:]{1,40}:\s*/, '') })
    const chapters: Page[] = groups.filter((g) => g.items.length).map((g, n) => ({ kind: 'chapter', id: g.id ?? `g${n}`, title: g.title, items: g.items.map((x) => feat(x.to, x.label, x.icon)) }))
    const settings = portal ? [] : SETTINGS_SECTIONS.filter((s) => s.permission.some((p) => can(p)))
    if (settings.length) chapters.push({ kind: 'chapter', id: 'settings-sections', title: t('tourx.settings'), items: settings.map((s) => ({ to: `/admin/settings?tab=${s.id}`, label: t(`mgmt.settings.sections.${s.id}.title`), icon: s.icon, desc: t(`mgmt.settings.sections.${s.id}.desc`, { defaultValue: '' }) })) })
    return [...story, ...chapters, { kind: 'end' }]
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [tour, groups, en, portal])

  const page = pages[i]
  // The menu entry (or button) the step talks about lights up.
  const target = page?.kind === 'story' ? (page.step.target ? ALIAS[page.step.target] ?? page.step.target : null) : page?.kind === 'chapter' && hl ? `nav:${hl}` : null
  useEffect(() => {
    if (!target || min) return
    const el = document.querySelector<HTMLElement>(`[data-tour="${CSS.escape(target)}"]`)
    if (!el) return
    el.classList.add('ring-4', 'ring-gold-500', 'rounded-xl')
    el.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
    return () => { el.classList.remove('ring-4', 'ring-gold-500', 'rounded-xl') }
  }, [target, i, min])
  useEffect(() => { setHl(null) }, [i])

  if (!tour || !page) return null
  const close = (state: 'done' | 'dismissed') => { api.post('/me/tours', { key: tour.key, state }).catch(() => undefined); setTour(null) }
  const last = i === pages.length - 1
  const total = pages.length
  const heading = t(tour.kind === 'first-login' ? 'hlp.tour.firstLogin' : 'hlp.tour.whatsNew')
  const open = (to: string) => { setMin(true); nav(to) }
  const label = (p: Page, n: number) => (p.kind === 'story' ? pick(p.step, 'title') : p.kind === 'chapter' ? p.title : t('tourx.allSet', { defaultValue: String(n) }))
  const pageCount = pages.reduce((n, p) => n + (p.kind === 'chapter' ? p.items.length : 0), 0)

  if (min) {
    return (
      <div className="fixed bottom-4 end-4 z-[60] flex items-center gap-2 rounded-2xl bg-navy-900 py-2 ps-4 pe-2 text-white shadow-2xl ring-1 ring-gold-500/40">
        <Sparkles className="size-4 text-gold-300" />
        <button type="button" onClick={() => setMin(false)} className="text-sm font-bold">{t('tourx.resume')} · {i + 1}/{total}</button>
        <button type="button" onClick={() => close('dismissed')} aria-label={t('hlp.tour.skip')} className="grid size-8 place-items-center rounded-xl hover:bg-white/10"><X className="size-4" /></button>
      </div>
    )
  }

  return (
    <div className="fixed inset-0 z-[60] grid place-items-center p-3 sm:p-6" role="dialog" aria-modal="true" aria-label={heading}>
      <div className="absolute inset-0 bg-navy-950/35" onClick={() => setMin(true)} />
      <div className="relative flex h-[min(92vh,740px)] w-full max-w-4xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl">
        <div className="flex items-center gap-3 bg-gradient-to-l from-navy-900 to-navy-800 px-5 py-4 text-white">
          <span className="grid size-10 place-items-center rounded-xl bg-gold-500 text-navy-950"><Sparkles className="size-5" /></span>
          <div className="min-w-0 flex-1"><div className="text-xs font-semibold text-gold-300">{heading}</div><div className="truncate font-bold">{t('tourx.title')}</div></div>
          <span className="hidden text-xs text-white/60 sm:inline">{t('hlp.tour.step', { n: i + 1, total })}</span>
          <button type="button" onClick={() => setMin(true)} aria-label={t('tourx.minimize')} title={String(t('tourx.minimize'))} className="grid size-9 place-items-center rounded-xl bg-white/10 hover:bg-white/20"><Minimize2 className="size-4" /></button>
          <button type="button" onClick={() => close('dismissed')} aria-label={t('hlp.tour.skip')} className="grid size-9 place-items-center rounded-xl bg-white/10 hover:bg-white/20"><X className="size-4" /></button>
        </div>
        <div className="h-1 bg-navy-100"><div className="h-full bg-gold-500 transition-all" style={{ width: `${((i + 1) / total) * 100}%` }} /></div>

        <div className="grid min-h-0 flex-1 md:grid-cols-[230px_1fr]">
          <nav className="hidden overflow-y-auto border-e border-navy-100 bg-ivory p-3 md:block" aria-label={t('tourx.chapters')}>
            <ol className="space-y-1">
              {pages.map((p, n) => (
                <li key={n}>
                  <button type="button" onClick={() => setI(n)} aria-current={n === i} className={clsx('flex w-full items-center gap-2 rounded-xl px-2.5 py-2 text-start text-[13px] transition', n === i ? 'bg-navy-900 font-bold text-white' : 'text-navy-800 hover:bg-white')}>
                    <span className={clsx('grid size-5 shrink-0 place-items-center rounded-full text-[10px] font-bold', n < i ? 'bg-emerald-500 text-white' : n === i ? 'bg-gold-500 text-navy-950' : 'bg-navy-100 text-navy-700')}>{n < i ? <Check className="size-3" /> : n + 1}</span>
                    <span className="min-w-0 flex-1 truncate">{label(p, n + 1)}</span>
                    {p.kind === 'chapter' && <span className="text-[10px] opacity-60">{p.items.length}</span>}
                  </button>
                </li>
              ))}
            </ol>
          </nav>

          <div className="min-h-0 overflow-y-auto p-5 sm:p-7">
            {page.kind === 'story' && <Story step={page.step} pick={pick} open={open} t={t} />}
            {page.kind === 'chapter' && (
              <div>
                <div className="flex items-center gap-2 text-xs font-bold text-gold-700"><ListChecks className="size-4" />{t('tourx.chapter')}</div>
                <h2 className="mt-1 text-2xl font-bold text-navy-900">{page.title}</h2>
                <p className="mt-1 text-sm text-slate-500">{t('tourx.chapterHint', { n: page.items.length })}</p>
                <ul className="mt-5 grid gap-3 sm:grid-cols-2">
                  {page.items.map((f) => (
                    <li key={f.to} onMouseEnter={() => setHl(f.to)} onFocus={() => setHl(f.to)} onMouseLeave={() => setHl(null)} className="group flex flex-col rounded-2xl border border-navy-100 bg-white p-4 transition hover:border-gold-400 hover:shadow-md">
                      <div className="flex items-start gap-3">
                        <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-gold-100 text-gold-700"><f.icon className="size-5" /></span>
                        <div className="min-w-0"><div className="font-bold text-navy-900" dir="auto">{f.label}</div>{f.desc && <p className="mt-1 text-xs leading-6 text-slate-600" dir="auto">{f.desc}</p>}</div>
                      </div>
                      <button type="button" onClick={() => open(f.to)} className="mt-3 self-end rounded-lg px-2.5 py-1 text-xs font-bold text-navy-800 ring-1 ring-navy-100 transition hover:bg-navy-900 hover:text-white">{t('tourx.open')}</button>
                    </li>
                  ))}
                </ul>
              </div>
            )}
            {page.kind === 'end' && (
              <div className="grid place-items-center py-6 text-center">
                <span className="grid size-16 place-items-center rounded-2xl bg-emerald-100 text-emerald-700"><Check className="size-8" /></span>
                <h2 className="mt-4 text-2xl font-bold text-navy-900">{t('tourx.allSet')}</h2>
                <p className="mt-2 max-w-md text-sm leading-7 text-slate-600">{t('tourx.allSetBody', { n: pageCount, chapters: pages.filter((p) => p.kind === 'chapter').length })}</p>
                <div className="mt-5 flex flex-wrap justify-center gap-2">
                  <Link to={portal ? '/portal/help' : '/admin/help'} onClick={() => close('done')} className="rounded-xl bg-navy-900 px-4 py-2 text-sm font-bold text-white">{t('tourx.helpCentre')}</Link>
                  <Button variant="outline" onClick={() => setI(0)}>{t('tourx.again')}</Button>
                </div>
              </div>
            )}
          </div>
        </div>

        <div className="flex items-center justify-between gap-2 border-t border-navy-100 bg-white px-5 py-3">
          <button type="button" onClick={() => close('dismissed')} className="text-xs font-semibold text-slate-500 hover:text-navy-900 hover:underline">{t('tourx.dontShow')}</button>
          <div className="flex gap-2">
            {i > 0 && <Button size="sm" variant="outline" icon={<ChevronRight className="size-4 ltr:rotate-180" />} onClick={() => setI(i - 1)}>{t('hlp.tour.prev')}</Button>}
            {last
              ? <Button size="sm" variant="gold" onClick={() => close('done')}>{t('hlp.tour.done')}</Button>
              : <Button size="sm" variant="gold" onClick={() => setI(i + 1)}>{t('hlp.tour.next')}<ChevronLeft className="size-4 ltr:rotate-180" /></Button>}
          </div>
        </div>
      </div>
    </div>
  )
}

function Story({ step, pick, open, t }: { step: ServerStep; pick: (o: ServerStep, k: string) => string; open: (to: string) => void; t: (k: string) => string }) {
  const { i18n } = useTranslation()
  const Ico = ICONS[step.icon ?? 'sparkles'] ?? Sparkles
  const points = (i18n.language === 'en' ? step.points_en : step.points_ar) ?? []
  return (
    <div>
      <span className="grid size-14 place-items-center rounded-2xl bg-gradient-to-br from-navy-900 to-navy-700 text-gold-300 shadow-lg"><Ico className="size-7" /></span>
      <h2 className="mt-4 text-2xl font-bold text-navy-900">{pick(step, 'title')}</h2>
      <p className="mt-3 max-w-2xl text-[15px] leading-8 text-slate-700">{pick(step, 'body')}</p>
      {points.length > 0 && (
        <ul className="mt-4 max-w-2xl space-y-2">
          {points.map((p) => <li key={p} className="flex items-start gap-2.5 text-sm leading-7 text-navy-900"><span className="mt-1.5 grid size-4 shrink-0 place-items-center rounded-full bg-gold-500 text-navy-950"><Check className="size-3" /></span>{p}</li>)}
        </ul>
      )}
      {step.route && <button type="button" onClick={() => open(step.route as string)} className="mt-5 rounded-xl bg-navy-900 px-4 py-2 text-sm font-bold text-white hover:bg-navy-800">{t('tourx.open')}</button>}
    </div>
  )
}
