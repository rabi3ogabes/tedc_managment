import { ArrowUpLeft, ClipboardCheck, QrCode, Radio } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Button } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { fmtDateTime, pickBi } from '../teaching/api'
import { attendancePercent, nextUp, showsQr, todayKey, type Duties } from './model'

/**
 * «برامجي» on the home page: how many groups the person was given, and the one session to act on now (the session
 * running, else the next) with its attendance and the attendance code. It draws nothing for somebody with no group, so
 * the home page of a person who neither supervises nor teaches stays as it was.
 */
export default function MyProgramsCard() {
  const { t, i18n } = useTranslation()
  const lang = i18n.language === 'en' ? 'en' : 'ar'
  const { can } = useAuth()
  const { data } = useGet<{ data: Duties }>('/me/duties', undefined, { staleTime: 30_000, refetchInterval: 60_000, retry: false })
  const duties = data?.data
  if (!duties?.groups.length) return null
  const up = nextUp(duties.groups)
  const s = up?.session
  const label = s ? (s.when === 'now' ? 'now' : s.when === 'today' ? 'today' : 'next') : null

  return (
    <section aria-labelledby="my-programmes" className="mb-6 overflow-hidden rounded-3xl bg-gradient-to-l from-navy-900 to-navy-700 text-white shadow-glass">
      <div className="flex flex-wrap items-center gap-x-8 gap-y-4 p-5 sm:p-6">
        <div className="min-w-0 flex-1">
          <h2 id="my-programmes" className="flex items-center gap-2 text-lg font-bold"><ClipboardCheck className="size-5 text-gold-300" />{t('duties.card.title')}</h2>
          <p className="mt-1 text-sm text-white/75">{t('duties.card.line', { n: duties.summary.total })} · {t(`duties.summary.${todayKey(duties.summary.today)}`, { n: duties.summary.today })}</p>
          {up && s && label && (
            <div className="mt-4">
              <div className="flex items-center gap-2 text-xs font-bold text-gold-300">{s.when === 'now' && <Radio className="size-3.5 animate-pulse motion-reduce:animate-none" />}{t(`duties.session.${label}`)}</div>
              <div className="mt-1 font-bold" dir="auto">{lang === 'en' ? up.group.program.title_en : up.group.program.title_ar}</div>
              <div className="text-xs text-white/70">{fmtDateTime(s.starts_at, lang)}{s.place ? ` · ${pickBi(s.place, lang)}` : ''}</div>
              {s.when === 'now' && s.expected > 0 && (
                <div className="mt-3 max-w-sm">
                  <div className="mb-1 flex items-baseline justify-between text-xs"><span className="text-white/80">{t('duties.attendance.title')}</span><span className="font-display text-base font-bold tabular-nums text-gold-200">{t('duties.attendance.of', { present: s.present, expected: s.expected })}</span></div>
                  <div className="h-2 overflow-hidden rounded-full bg-white/15"><div className="h-full rounded-full bg-gradient-to-l from-gold-300 to-gold-500" style={{ width: `${attendancePercent(s.present, s.expected)}%` }} /></div>
                </div>
              )}
            </div>
          )}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {s && showsQr(s) && can('attendance.manage') && <Button variant="gold" icon={<QrCode className="size-4" />} onClick={() => window.open(`/admin/sessions/${s.id}/qr`, '_blank', 'noopener')}>{t('duties.attendance.qr')}</Button>}
          <Link to="/admin/my-programs" className="inline-flex items-center gap-2 rounded-xl bg-white/10 px-4 py-2.5 text-sm font-bold text-white transition-colors hover:bg-white/20">{t('duties.card.open')}<ArrowUpLeft className="size-4 ltr:-scale-x-100" /></Link>
        </div>
      </div>
    </section>
  )
}
