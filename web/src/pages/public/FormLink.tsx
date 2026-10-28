import { CalendarClock, CheckCircle2, ClipboardCheck, Hourglass, Lock, NotebookPen, SearchX, UserX } from 'lucide-react'
import type { ComponentType } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate, useParams } from 'react-router-dom'
import { Button } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'

type Resolved = { state: string; program: { id: string; code: string; title: string }; registration_id?: string; assignment_id?: string; opens_at?: string | null; ends_on?: string | null }

const ICONS: Record<string, ComponentType<{ className?: string }>> = {
  submitted: CheckCircle2, scheduled: CalendarClock, waiting: Hourglass, closed: Lock, off: Lock, pending: Hourglass, not_registered: UserX, not_trainer: UserX,
}

/**
 * `/f/t/:code` and `/f/r/:code` — a programme's form link, usually scanned from the QR code in the room (docs/saber/07-programme-forms.md).
 * After sign-in it opens the person's own trainee evaluation or trainer self-reflection; otherwise it says plainly why not yet.
 */
export default function FormLink() {
  const { t } = useTranslation()
  const { kind = 't', code = '' } = useParams()
  const reflection = kind === 'r'
  const valid = kind === 't' || kind === 'r'
  const { data, isError } = useGet<{ data: Resolved }>(valid ? `/me/form-links/${reflection ? 'reflection' : 'trainee'}/${encodeURIComponent(code)}` : null, undefined, { staleTime: 0, retry: false })
  const r = data?.data

  if (!valid) return <Navigate replace to="/" />
  if (r?.state === 'open') {
    try { sessionStorage.setItem('tedc.form-link', String(Date.now())) } catch { /* storage blocked: the tour simply shows */ }
    return <Navigate replace to={reflection ? `/portal/evaluations/${r.assignment_id}` : `/portal/training?evaluate=${r.registration_id}`} />
  }
  const Icon = isError ? SearchX : r ? ICONS[r.state] ?? Lock : reflection ? NotebookPen : ClipboardCheck
  const done = r?.state === 'submitted'
  const message = isError ? t('saber.forms.link.notFound')
    : r ? t(`saber.forms.link.${reflection ? 'reflection' : 'trainee'}.${r.state}`, { program: r.program.title, date: r.opens_at ? fmt.date(r.opens_at, { weekday: 'long', day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }) : '', end: r.ends_on ? ` (${fmt.date(r.ends_on, { weekday: 'long', day: 'numeric', month: 'long' })})` : '' })
      : ''

  return (
    <main className="mx-auto flex min-h-[70vh] max-w-lg items-center px-4 pb-16 pt-28">
      <section className="w-full rounded-3xl border border-navy-100 bg-white p-8 text-center shadow-[0_20px_60px_-30px_rgba(15,23,42,0.35)]">
        <span className={`mx-auto grid size-16 place-items-center rounded-3xl ring-1 ring-inset ${done ? 'bg-emerald-50 text-emerald-600 ring-emerald-200' : 'bg-gradient-to-br from-gold-50 to-ivory text-gold-700 ring-gold-200'}`}>
          {!r && !isError ? <span className="size-6 animate-spin rounded-full border-2 border-gold-500 border-t-transparent" /> : <Icon className="size-8" />}
        </span>
        <p className="mt-5 text-xs font-bold uppercase tracking-wide text-gold-700">{t(`saber.forms.${reflection ? 'reflection' : 'trainee'}.title`)}</p>
        {r && <h1 className="mt-1 text-xl font-extrabold text-navy-900">{r.program.title}</h1>}
        <p className="mx-auto mt-3 max-w-sm text-sm leading-relaxed text-slate-600">{!r && !isError ? t('saber.forms.link.loading') : message}</p>
        {(r || isError) && (
          <div className="mt-6 flex flex-wrap justify-center gap-2">
            <Button variant="gold" to={reflection ? '/portal/evaluations' : '/portal/training'}>{t(reflection ? 'saber.forms.link.toEvaluations' : 'saber.forms.link.toTraining')}</Button>
            <Button variant="ghost" to="/portal">{t('saber.forms.link.toPortal')}</Button>
          </div>
        )}
      </section>
    </main>
  )
}
