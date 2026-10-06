import { Sparkles, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui'
import { api } from '@/lib/api'
import { useLang } from './helpApi'

type Tour = { key: string; kind: 'first-login' | 'whats-new'; steps: { title_ar: string; title_en: string; body_ar: string; body_en: string; target?: string | null }[] }

/** The guided tour: shown once per person for their role and again after a release; dismissible and remembered. */
export default function TourHost() {
  const { t } = useTranslation()
  const { pick } = useLang()
  const [tour, setTour] = useState<Tour | null>(null)
  const [i, setI] = useState(0)

  const load = () => { api.get('/me/tours').then((r) => { setTour(r.data.data ?? null); setI(0) }).catch(() => undefined) }
  useEffect(() => {
    load()
    window.addEventListener('tedc:tours-reset', load)
    return () => window.removeEventListener('tedc:tours-reset', load)
  }, [])

  const target = tour?.steps[i]?.target
  useEffect(() => {
    if (!target) return
    const el = document.querySelector<HTMLElement>(`[data-tour="${target}"]`)
    el?.classList.add('ring-4', 'ring-gold-500', 'rounded-xl')
    return () => { el?.classList.remove('ring-4', 'ring-gold-500', 'rounded-xl') }
  }, [target, i])

  if (!tour) return null
  const close = (state: 'done' | 'dismissed') => { api.post('/me/tours', { key: tour.key, state }).catch(() => undefined); setTour(null) }
  const step = tour.steps[i]
  const last = i === tour.steps.length - 1
  return (
    <div className="fixed inset-0 z-[60] grid place-items-end p-4 sm:place-items-center" role="dialog" aria-modal="true" aria-label={String(t(tour.kind === 'first-login' ? 'hlp.tour.firstLogin' : 'hlp.tour.whatsNew'))}>
      <div className="absolute inset-0 bg-navy-950/30" onClick={() => close('dismissed')} />
      <div className="relative w-full max-w-md space-y-4 rounded-2xl bg-white p-6 shadow-2xl">
        <button type="button" onClick={() => close('dismissed')} aria-label={t('hlp.tour.skip')} className="absolute end-3 top-3 rounded-lg p-1.5 hover:bg-navy-100/60"><X className="size-5" /></button>
        <div className="flex items-center gap-2 text-gold-600"><Sparkles className="size-5" /><span className="text-xs font-bold">{t(tour.kind === 'first-login' ? 'hlp.tour.firstLogin' : 'hlp.tour.whatsNew')}</span></div>
        <h2 className="text-xl font-bold text-navy-900">{pick(step, 'title')}</h2>
        <p className="leading-8 text-slate-700">{pick(step, 'body')}</p>
        <div className="flex items-center justify-between">
          <span className="text-xs text-slate-500">{t('hlp.tour.step', { n: i + 1, total: tour.steps.length })}</span>
          <div className="flex gap-2">
            {i > 0 && <Button size="sm" variant="outline" onClick={() => setI(i - 1)}>{t('hlp.tour.prev')}</Button>}
            {!last && <Button size="sm" variant="ghost" onClick={() => close('dismissed')}>{t('hlp.tour.skip')}</Button>}
            {last ? <Button size="sm" variant="gold" onClick={() => close('done')}>{t('hlp.tour.done')}</Button> : <Button size="sm" variant="gold" onClick={() => setI(i + 1)}>{t('hlp.tour.next')}</Button>}
          </div>
        </div>
      </div>
    </div>
  )
}
