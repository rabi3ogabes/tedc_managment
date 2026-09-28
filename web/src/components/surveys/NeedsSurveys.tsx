import clsx from 'clsx'
import { CalendarClock, CheckCircle2, ClipboardList, Clock, Loader2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router-dom'
import { Card, Empty } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import type { Answers, Question, SurveySettings } from '@/lib/surveys'
import SurveyForm, { SurveyDialog } from './SurveyForm'

type Assigned = {
  id: string; title: string; description?: string | null; accent?: string | null; anonymous: boolean; open: boolean
  questions_count: number; estimated_minutes: number; closes_at?: string | null; responded_at?: string | null
}
type Detail = Assigned & { questions: Question[]; settings: SurveySettings; answers: Answers | null }

/** Training-needs surveys addressed to the signed-in employee; each opens in a popup (deep link: ?needs=<id>). */
export function NeedsSurveys() {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()
  const openId = params.get('needs')
  const list = useGet<{ data: Assigned[] }>('/me/needs-surveys')
  const detail = useGet<{ data: Detail }>(openId ? `/me/needs-surveys/${openId}` : null, undefined, { staleTime: 0, retry: false })
  const open = (id: string | null) => setParams(id ? { needs: id } : {}, { replace: !id })

  const submit = async (answers: Answers, duration: number) => {
    try {
      const { data } = await api.post(`/me/needs-surveys/${openId}`, { answers, duration_seconds: duration })
      list.refetch()
      return data.message as string
    } catch (e) { throw new Error(errorMessage(e)) }
  }

  const items = list.data?.data ?? []
  return (
    <>
      {list.isLoading ? <Loader2 className="size-6 animate-spin text-gold-600" /> : !items.length ? <Card><Empty icon={<ClipboardList className="size-6" />} /></Card> : (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {items.map((s) => {
            const accent = s.accent ?? '#8A1538'
            const done = !!s.responded_at
            return (
              <button key={s.id} onClick={() => open(s.id)} className="group card overflow-hidden text-start transition duration-300 hover:-translate-y-1 hover:shadow-glass">
                <div className="relative h-24 p-5" style={{ background: `linear-gradient(135deg, ${accent}, color-mix(in srgb, ${accent} 50%, #12040a))` }}>
                  <div className="pattern-bg absolute inset-0 opacity-30" />
                  <span className={clsx('relative inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold', done ? 'bg-emerald-400/90 text-emerald-950' : s.open ? 'bg-gold-300 text-navy-950' : 'bg-white/20 text-white')}>
                    {done ? <CheckCircle2 className="size-3.5" /> : <Clock className="size-3.5" />}
                    {done ? t('surveys.form.done') : s.open ? t('surveys.form.pending') : t('surveys.form.closed')}
                  </span>
                </div>
                <div className="p-5">
                  <h3 className="font-bold leading-7 text-navy-900">{s.title}</h3>
                  {s.description && <p className="mt-1 line-clamp-2 text-sm leading-6 text-slate-500">{s.description}</p>}
                  <div className="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-400">
                    <span>{t('surveys.form.questions', { n: s.questions_count })}</span>
                    <span>{t('surveys.form.minutes', { n: s.estimated_minutes })}</span>
                    {s.closes_at && <span><CalendarClock className="me-1 inline size-3.5" />{t('surveys.form.closesOn', { d: fmt.date(s.closes_at) })}</span>}
                  </div>
                  <span className="mt-4 inline-flex rounded-xl px-4 py-2 text-sm font-bold text-white transition group-hover:brightness-110" style={{ background: accent }}>{done ? t('surveys.form.view') : t('surveys.form.answer')}</span>
                </div>
              </button>
            )
          })}
        </div>
      )}

      <SurveyDialog open={!!openId} onClose={() => open(null)} label={detail.data?.data.title}>
        {detail.error ? (
          <div className="grid h-80 place-items-center p-8 text-center text-slate-500">{errorMessage(detail.error)}</div>
        ) : detail.isLoading || !detail.data ? (
          <div className="grid h-80 place-items-center"><Loader2 className="size-8 animate-spin text-gold-600" /></div>
        ) : (
          <SurveyForm
            key={detail.data.data.id}
            survey={detail.data.data}
            initialAnswers={detail.data.data.answers}
            closed={!detail.data.data.open}
            onSubmit={submit}
            onClose={() => open(null)}
          />
        )}
      </SurveyDialog>
    </>
  )
}

/** Compact banner for the portal home when surveys await an answer. */
export function PendingSurveysBanner() {
  const { t } = useTranslation()
  const list = useGet<{ data: Assigned[] }>('/me/needs-surveys')
  const pending = (list.data?.data ?? []).filter((s) => s.open && !s.responded_at)
  if (!pending.length) return null
  return (
    <Link to={`/portal/surveys?needs=${pending[0].id}`} className="mb-6 flex items-center gap-4 rounded-2xl bg-gradient-to-l from-gold-500 to-gold-600 p-4 text-white shadow-glass transition hover:brightness-105">
      <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-white/20"><ClipboardList className="size-5" /></span>
      <span className="min-w-0 flex-1"><span className="block font-bold">{t('surveys.portal.pendingBanner', { n: pending.length })}</span><span className="block truncate text-sm text-white/80">{pending[0].title}</span></span>
      <span className="rounded-xl bg-white px-4 py-2 text-sm font-bold text-gold-700">{t('surveys.form.answer')}</span>
    </Link>
  )
}
