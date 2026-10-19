import { useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { CalendarClock, ChevronLeft, ChevronRight, DoorOpen, Landmark, Presentation, UserCog, Users, X } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation, useNavigate } from 'react-router-dom'
import { Button } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmtDateTime, pickBi, type Bi } from '@/pages/admin/teaching/api'

type Notice = {
  id: string; program: { id: string; code: string; title_ar: string; title_en: string }; from: string | null; to: string | null; has_times: boolean
  group: Bi; rooms: Bi[]; mode: string; supervisor: Bi | null; sessions: number; ownership: Bi
}

const LATER = 'tedc.teachPopup.later'
const HOMES = ['/admin', '/admin/programs', '/admin/teaching', '/portal']

/**
 * "You are the trainer of …" on the trainer's home: a popup (a slider when there is more than one) saying when, which group, which
 * room and under whom, with the line that the training kit belongs to the training centre. "Don't show again" removes a notice for
 * good; "Later" hides them until the next sign-in.
 */
export default function AssignmentPopup() {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  const { pathname } = useLocation()
  const nav = useNavigate()
  const qc = useQueryClient()
  const en = i18n.language === 'en'
  const isTrainer = !!user && (!!user.trainer_id || user.roles.some((r) => r.slug === 'trainer'))
  const home = HOMES.includes(pathname.replace(/\/$/, '') || '/')
  const [later, setLater] = useState(() => { try { return sessionStorage.getItem(LATER) === '1' } catch { return false } })
  const { data } = useGet<{ data: Notice[] }>(isTrainer && home && !later ? '/me/teaching/notices' : null, undefined, { staleTime: 0 })
  const [i, setI] = useState(0)
  const [gone, setGone] = useState<string[]>([])
  const drag = useRef<number | null>(null)
  const items = (data?.data ?? []).filter((n) => !gone.includes(n.id))
  const idx = Math.min(i, Math.max(0, items.length - 1))
  const n = items[idx]
  const lang = en ? 'en' : 'ar'

  useEffect(() => {
    if (!n) return
    const rtl = document.documentElement.dir === 'rtl'
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') hideLater()
      else if (e.key === 'ArrowLeft') setI((x) => Math.max(0, Math.min(items.length - 1, x + (rtl ? 1 : -1))))
      else if (e.key === 'ArrowRight') setI((x) => Math.max(0, Math.min(items.length - 1, x + (rtl ? -1 : 1))))
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [n, items.length])

  if (!n) return null
  const hideLater = () => { try { sessionStorage.setItem(LATER, '1') } catch { /* storage unavailable */ } setLater(true) }
  const dismiss = async () => {
    setGone((g) => [...g, n.id])
    await api.post(`/me/teaching/notices/${n.id}/dismiss`).catch(() => undefined)
    void qc.invalidateQueries({ queryKey: ['/me/teaching/notices'] })
  }
  const dismissAll = async () => { setGone(items.map((x) => x.id)); await api.post('/me/teaching/notices/dismiss-all').catch(() => undefined) }
  const open = () => { hideLater(); nav(`/admin/teaching?program=${n.program.id}`) }
  const move = (d: number) => setI(Math.max(0, Math.min(items.length - 1, idx + d)))
  const rooms = n.mode === 'online' ? t('teach.online') : n.rooms.map((r) => pickBi(r, lang)).join('، ') || t('teach.roomTbd')

  return (
    <div className="fixed inset-0 z-[75] grid place-items-center bg-navy-950/60 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label={t('teach.popup.title')}>
      <div className="relative w-full max-w-xl overflow-hidden rounded-3xl bg-white shadow-2xl" onPointerDown={(e) => { drag.current = e.clientX }} onPointerUp={(e) => { if (drag.current !== null && Math.abs(e.clientX - drag.current) > 60) move((e.clientX - drag.current) * (document.documentElement.dir === 'rtl' ? -1 : 1) < 0 ? 1 : -1); drag.current = null }}>
        <div className="relative bg-gradient-to-l from-navy-900 to-navy-800 px-6 pb-5 pt-6 text-white">
          <div className="pointer-events-none absolute -end-12 -top-12 size-44 rounded-full bg-gold-500/20 blur-2xl" />
          <button type="button" onClick={hideLater} aria-label={t('teach.popup.later')} className="absolute end-3 top-3 grid size-8 place-items-center rounded-full bg-white/10 hover:bg-white/20"><X className="size-4" /></button>
          <span className="grid size-12 place-items-center rounded-2xl bg-gold-500 text-navy-950"><Presentation className="size-6" /></span>
          <h2 className="mt-3 text-xl font-bold">{t('teach.popup.title')}</h2>
          <p className="mt-1 text-lg font-semibold text-gold-200" dir="auto">{en ? n.program.title_en : n.program.title_ar} <span className="font-mono text-xs text-white/60">{n.program.code}</span></p>
        </div>

        <div key={n.id} className="animate-[fadeIn_.25s_ease] space-y-3 px-6 py-5">
          <Line icon={CalendarClock} label={t('teach.when')}>{n.from ? <>{fmtDateTime(n.from, lang, n.has_times)}{n.to ? <> <span className="mx-1 text-gold-600">→</span> {fmtDateTime(n.to, lang, n.has_times)}</> : null}</> : '—'}</Line>
          <Line icon={Users} label={t('teach.group')}>{pickBi(n.group, lang)}</Line>
          <Line icon={DoorOpen} label={t('teach.room')}>{rooms}</Line>
          <Line icon={UserCog} label={t('teach.supervisor')}>{pickBi(n.supervisor, lang) || '—'}</Line>
          <div className="flex items-start gap-3 rounded-2xl border border-gold-300/70 bg-gold-50/70 px-4 py-3 text-sm font-semibold text-navy-900"><Landmark className="mt-0.5 size-5 shrink-0 text-gold-700" />{pickBi(n.ownership, lang)}</div>
        </div>

        <div className="flex flex-wrap items-center gap-2 border-t border-navy-100 bg-ivory px-6 py-4">
          <Button onClick={open}>{t('teach.popup.open')}</Button>
          <Button variant="outline" onClick={dismiss}>{t('teach.popup.dontShow')}</Button>
          <Button variant="ghost" onClick={hideLater}>{t('teach.popup.later')}</Button>
          {items.length > 1 && <button type="button" onClick={dismissAll} className="ms-auto text-xs font-semibold text-slate-500 underline-offset-2 hover:text-navy-900 hover:underline">{t('teach.popup.dontShowAll')}</button>}
        </div>

        {items.length > 1 && (
          <>
            <button type="button" onClick={() => move(-1)} disabled={idx === 0} aria-label={t('teach.previous')} className="absolute start-2 top-[38%] grid size-9 place-items-center rounded-full bg-white/90 text-navy-900 shadow ring-1 ring-navy-100 hover:bg-white disabled:opacity-30"><ChevronRight className="size-5 ltr:rotate-180" /></button>
            <button type="button" onClick={() => move(1)} disabled={idx === items.length - 1} aria-label={t('teach.next')} className="absolute end-2 top-[38%] grid size-9 place-items-center rounded-full bg-white/90 text-navy-900 shadow ring-1 ring-navy-100 hover:bg-white disabled:opacity-30"><ChevronLeft className="size-5 ltr:rotate-180" /></button>
            <div className="flex items-center justify-center gap-1.5 bg-ivory pb-3" role="tablist">
              {items.map((x, k) => <button key={x.id} type="button" role="tab" aria-selected={k === idx} aria-label={`${k + 1}/${items.length}`} onClick={() => setI(k)} className={clsx('h-2 rounded-full transition-all', k === idx ? 'w-6 bg-gold-500' : 'w-2 bg-navy-200 hover:bg-navy-300')} />)}
              <span className="ms-2 text-[11px] tabular-nums text-slate-500">{idx + 1} / {items.length}</span>
            </div>
          </>
        )}
      </div>
    </div>
  )
}

function Line({ icon: Icon, label, children }: { icon: typeof Users; label: string; children: React.ReactNode }) {
  return <div className="flex items-start gap-3"><span className="grid size-9 shrink-0 place-items-center rounded-xl bg-gold-100 text-gold-700"><Icon className="size-4" /></span><div className="min-w-0"><div className="text-[11px] font-semibold text-slate-500">{label}</div><div className="text-sm font-bold text-navy-900" dir="auto">{children}</div></div></div>
}
