import clsx from 'clsx'
import { ArrowLeft, ArrowRight, Check, ExternalLink, FilePenLine, MessageSquareQuote, Search, ShieldCheck, X } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Avatar, Badge, Button, Card, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'

type Req = {
  id: string; field: string; label: string; kind: 'wrong' | 'missing' | 'update'; status: 'pending' | 'approved' | 'rejected' | 'cancelled'; can_apply: boolean
  current_value: string | null; requested_value: string; note: string | null; applied: boolean; review_note: string | null; by: string | null; created_at: string; reviewed_at: string | null
  user: { id: string; name: string; email: string; employee_id: string | null; employee_no: string | null; school: string | null; job_title: string | null }
}
type Page = { counts: { pending: number; resolved: number }; data: { data: Req[]; last_page?: number } | Req[] }

const KIND_TONE = { wrong: 'red', missing: 'amber', update: 'blue' } as const

/** Review queue: users cannot edit their account; they send a request when something is wrong or missing. */
export default function ProfileRequests() {
  const { t, i18n } = useTranslation()
  const [tab, setTab] = useState<'pending' | 'resolved'>('pending')
  const [q, setQ] = useState('')
  const { data, isLoading, refetch } = useGet<Page>('/admin/profile-requests', { status: tab, q: q || undefined }, { staleTime: 0 })
  const rows: Req[] = Array.isArray(data?.data) ? data.data : (data?.data as { data: Req[] } | undefined)?.data ?? []
  const Arrow = i18n.dir() === 'rtl' ? ArrowLeft : ArrowRight

  return (
    <div>
      <PageHeader
        title={<span className="flex items-center gap-3"><span className="grid size-11 place-items-center rounded-2xl bg-navy-900 text-gold-300"><FilePenLine className="size-5" /></span>{t('mgmt.profileRequests.title')}</span>}
        subtitle={t('mgmt.profileRequests.subtitle')}
      />

      <div className="mb-5 flex flex-wrap items-center gap-3">
        <div role="tablist" className="inline-flex rounded-xl border border-navy-100 bg-white p-1 text-sm font-bold">
          {(['pending', 'resolved'] as const).map((id) => (
            <button key={id} type="button" role="tab" aria-selected={tab === id} onClick={() => setTab(id)} className={clsx('inline-flex items-center gap-2 rounded-lg px-4 py-2 transition', tab === id ? 'bg-navy-900 text-white' : 'text-slate-500')}>
              {t(`mgmt.profileRequests.tabs.${id}`)}<span className={clsx('rounded-full px-1.5 text-[11px]', tab === id ? 'bg-white/20' : 'bg-navy-100')}>{data?.counts[id] ?? 0}</span>
            </button>
          ))}
        </div>
        <div className="relative min-w-56 flex-1 sm:max-w-sm"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input !ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('mgmt.live.search')} aria-label={t('mgmt.live.search')} /></div>
      </div>

      {isLoading ? <Spinner /> : rows.length === 0 ? (
        <Card><div className="py-12 text-center"><ShieldCheck className="mx-auto size-10 text-emerald-500" /><p className="mt-3 font-bold text-navy-900">{t(tab === 'pending' ? 'mgmt.profileRequests.emptyPending' : 'mgmt.profileRequests.emptyResolved')}</p></div></Card>
      ) : (
        <ul className="space-y-4">{rows.map((r) => <RequestCard key={r.id} r={r} Arrow={Arrow} onDone={() => void refetch()} />)}</ul>
      )}
    </div>
  )
}

function RequestCard({ r, Arrow, onDone }: { r: Req; Arrow: typeof ArrowRight; onDone: () => void }) {
  const { t } = useTranslation()
  const [apply, setApply] = useState(r.can_apply)
  const [rejecting, setRejecting] = useState(false)
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const pending = r.status === 'pending'

  const act = async (kind: 'approve' | 'reject') => {
    setBusy(kind); setError(null)
    try { await api.post(`/admin/profile-requests/${r.id}/${kind}`, kind === 'approve' ? { apply, note: note || undefined } : { note: note || undefined }); onDone() } catch (e) { setError(errorMessage(e)); setBusy(null) }
  }

  return (
    <li className={clsx('overflow-hidden rounded-2xl border bg-white shadow-sm', pending ? 'border-gold-300' : 'border-navy-100')}>
      <div className="flex flex-wrap items-center gap-3 border-b border-navy-100 bg-ivory/60 px-5 py-3">
        <Avatar name={r.user.name} size={40} />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2"><span className="font-bold text-navy-900">{r.user.name}</span>
            {r.user.employee_id && <Link to={`/admin/employees/${r.user.employee_id}`} className="inline-flex items-center gap-1 text-xs font-semibold text-link hover:underline">{t('mgmt.profileRequests.openProfile')}<ExternalLink className="size-3" /></Link>}</div>
          <div className="truncate text-xs text-slate-500">{[r.user.job_title, r.user.school, r.user.employee_no].filter(Boolean).join(' · ')} · <span dir="ltr">{r.user.email}</span></div>
        </div>
        <Badge color={KIND_TONE[r.kind]}>{t(`mgmt.profileRequests.kinds.${r.kind}`)}</Badge>
        <span className="text-xs text-slate-400">{fmt.dateTime(r.created_at)}</span>
      </div>

      <div className="px-5 py-4">
        <div className="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">{r.label}</div>
        <div className="flex flex-wrap items-stretch gap-3">
          <div className="min-w-40 flex-1 rounded-xl border border-red-100 bg-red-50/60 p-3"><div className="text-[11px] font-semibold text-red-700">{t('mgmt.profileRequests.current')}</div><div className={clsx('mt-0.5 font-semibold', r.current_value ? 'text-red-900 line-through decoration-red-300' : 'italic text-slate-400')}>{r.current_value ?? t('mgmt.profileRequests.notRecorded')}</div></div>
          <span className="grid place-items-center text-slate-300"><Arrow className="size-5" /></span>
          <div className="min-w-40 flex-1 rounded-xl border border-emerald-200 bg-emerald-50/70 p-3"><div className="text-[11px] font-semibold text-emerald-700">{t('mgmt.profileRequests.requested')}</div><div className="mt-0.5 font-bold text-emerald-900">{r.requested_value}</div></div>
        </div>
        {r.note && <p className="mt-3 flex items-start gap-2 rounded-xl bg-navy-100/40 p-3 text-sm text-navy-900"><MessageSquareQuote className="mt-0.5 size-4 shrink-0 text-slate-400" />{r.note}</p>}

        {pending ? (
          <div className="mt-4 space-y-3">
            {r.can_apply ? (
              <label className="flex cursor-pointer items-center gap-2 text-sm font-semibold text-navy-900"><input type="checkbox" className="size-4 accent-gold-600" checked={apply} onChange={(e) => setApply(e.target.checked)} />{t('mgmt.profileRequests.applyNow')}</label>
            ) : <p className="text-xs text-slate-500">{t('mgmt.profileRequests.manualHint')}</p>}
            {(rejecting || note) && <input className="input" value={note} maxLength={500} onChange={(e) => setNote(e.target.value)} placeholder={t('mgmt.profileRequests.notePlaceholder')} aria-label={t('mgmt.profileRequests.notePlaceholder')} />}
            {error && <p className="text-sm text-danger">{error}</p>}
            <div className="flex flex-wrap gap-2">
              <Button variant="gold" icon={<Check className="size-4" />} loading={busy === 'approve'} onClick={() => void act('approve')}>{apply && r.can_apply ? t('mgmt.profileRequests.approveApply') : t('mgmt.profileRequests.approve')}</Button>
              {rejecting ? <Button variant="outline" icon={<X className="size-4" />} loading={busy === 'reject'} onClick={() => void act('reject')}>{t('mgmt.profileRequests.confirmReject')}</Button> : <Button variant="outline" icon={<X className="size-4" />} onClick={() => setRejecting(true)}>{t('mgmt.profileRequests.reject')}</Button>}
            </div>
          </div>
        ) : (
          <div className="mt-3 flex flex-wrap items-center gap-2 text-xs text-slate-500">
            <Badge color={r.status === 'approved' ? 'green' : r.status === 'rejected' ? 'red' : 'gray'}>{t(`mgmt.profileRequests.statuses.${r.status}`)}</Badge>
            {r.applied && <Badge color="gold">{t('mgmt.profileRequests.applied')}</Badge>}
            {r.by && <span>{r.by}</span>}{r.reviewed_at && <span>· {fmt.dateTime(r.reviewed_at)}</span>}{r.review_note && <span className="text-slate-700">— {r.review_note}</span>}
          </div>
        )}
      </div>
    </li>
  )
}
