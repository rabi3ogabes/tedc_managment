/* eslint-disable @typescript-eslint/no-explicit-any */
import clsx from 'clsx'
import { EyeOff } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui'
import { Skeleton } from '@/components/ui/Skeleton'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { dialogs } from '@/lib/dialogs'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import StarRating from './StarRating'

export type RatingSubject = 'lesson' | 'material' | 'kit' | 'library' | 'program'

type Summary = { average: number | null; count: number; distribution: Record<string, number>; mine: { stars: number; review: string | null; status?: 'published' | 'hidden' } | null; can_rate: boolean; reviews: { id: string; stars: number; review: string; author: string | null; created_at: string }[] }

/**
 * One rating block for any content (COL-03): the average and how the stars are spread, the newest reviews, your own
 * rating, and — for moderators — hiding a review with a reason.
 */
export default function RatingSummary({ type, id, canRate = true, className }: { type: RatingSubject; id: string; canRate?: boolean; className?: string }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const res = useGet<{ data: Summary }>(`/social/ratings/${type}/${id}`, undefined, { staleTime: 0, retry: false })
  const [stars, setStars] = useState<number | null>(null)
  const [review, setReview] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  if (res.isLoading) return <div className={clsx('space-y-3', className)}><Skeleton className="h-16 w-full" /><Skeleton className="h-24 w-full" /></div>
  if (res.isError || !res.data) return null
  const d = res.data.data
  const cur = stars ?? d.mine?.stars ?? 0
  const text = review ?? d.mine?.review ?? ''
  const top = Math.max(1, ...Object.values(d.distribution))
  const dirty = cur > 0 && (cur !== d.mine?.stars || (text || null) !== (d.mine?.review ?? null))

  const save = async () => {
    setBusy(true)
    try {
      await api.post('/social/ratings', { subject_type: type, subject_id: id, stars: cur, review: text.trim() || undefined })
      toast(String(t('soc.lesson.ratingSaved'))); setStars(null); setReview(null); res.refetch()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const hide = async (rid: string) => {
    const reason = await dialogs.prompt(String(t('soc.rating.hideReason')))
    if (reason === null) return
    if (!reason.trim()) { toast(String(t('soc.rating.reasonNeeded')), 'error'); return }
    try { await api.post(`/social/ratings/${rid}/moderate`, { status: 'hidden', reason }); toast(String(t('soc.rating.hidden'))); res.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }

  return (
    <section className={clsx('space-y-4', className)} aria-label={String(t('soc.rating.title'))}>
      <div className="grid gap-4 sm:grid-cols-[auto_1fr] sm:items-center">
        <div className="flex items-center gap-3 sm:flex-col sm:items-start sm:gap-1 sm:pe-6">
          <span className="font-display text-4xl font-bold leading-none text-navy-900">{d.average !== null ? fmt.number(d.average, 1) : '—'}</span>
          <div><StarRating value={d.average ?? 0} size={18} /><p className="text-xs text-slate-500">{d.count ? t('soc.lesson.ratings', { n: d.count }) : t('soc.lesson.noRatings')}</p></div>
        </div>
        <ul className="space-y-1" aria-label={String(t('soc.rating.spread'))}>
          {[5, 4, 3, 2, 1].map((n) => {
            const v = d.distribution[n] ?? 0
            return (
              <li key={n} className="flex items-center gap-2 text-xs text-slate-500">
                <span className="w-3 text-end" dir="ltr">{n}</span>
                <span className="relative h-2 flex-1 overflow-hidden rounded-full bg-navy-50" aria-hidden>
                  <span className="absolute inset-y-0 start-0 rounded-full bg-gold-500" style={{ width: `${(v / top) * 100}%` }} />
                </span>
                <span className="w-8 text-end tabular-nums">{fmt.number(v)}</span>
              </li>
            )
          })}
        </ul>
      </div>

      {canRate && d.can_rate && (
        <div className="space-y-2 border-t border-navy-50 pt-4">
          <p className="text-sm font-semibold text-navy-900">{t(d.mine ? 'soc.rating.yours' : 'soc.rating.rate')}</p>
          {d.mine?.status === 'hidden' && <p className="rounded-lg bg-gold-50 px-3 py-2 text-xs text-gold-800">{t('soc.rating.mineHidden')}</p>}
          <div className="flex flex-wrap items-start gap-3">
            <StarRating value={cur} onChange={setStars} size={26} />
            <textarea className="input min-h-11 min-w-52 flex-1" rows={text ? 2 : 1} value={text} onChange={(e) => setReview(e.target.value)} placeholder={String(t('soc.lesson.yourReview'))} aria-label={String(t('soc.lesson.yourReview'))} maxLength={1000} />
            <Button variant="gold" loading={busy} disabled={!dirty} onClick={save}>{t('soc.common.save')}</Button>
          </div>
        </div>
      )}

      {d.reviews.length > 0 && (
        <div className="border-t border-navy-50 pt-4">
          <h4 className="mb-2 text-sm font-semibold text-navy-900">{t('soc.rating.newest')}</h4>
          <ul className="space-y-3">
            {d.reviews.slice(0, 5).map((r) => (
              <li key={r.id} className="rounded-xl bg-navy-50/40 p-3 text-sm">
                <div className="flex flex-wrap items-center gap-2">
                  <StarRating value={r.stars} size={14} />
                  <span className="text-xs font-semibold text-navy-800">{r.author ?? t('soc.post.anonymous')}</span>
                  <span className="text-xs text-slate-400">{fmt.date(r.created_at)}</span>
                  {can('ratings.moderate') && <button type="button" onClick={() => void hide(r.id)} className="ms-auto inline-flex items-center gap-1 text-xs font-semibold text-slate-500 hover:text-danger"><EyeOff className="size-3.5" />{t('soc.reviews.hide')}</button>}
                </div>
                <p className="mt-1 whitespace-pre-line text-ink">{r.review}</p>
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  )
}
