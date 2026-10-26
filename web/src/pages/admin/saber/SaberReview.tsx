import clsx from 'clsx'
import { AlertTriangle, CheckCircle2, ChevronLeft, Inbox, Layers, Undo2, XCircle } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, PageHeader, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import DiffView, { type Diff } from './DiffView'
import type { DraftInfo } from './types'

type Status = 'submitted' | 'draft' | 'published' | 'discarded'
type Row = DraftInfo & { counts: { groups: number; days: number; fields: number } | null }
type Failure = { group: string; date: string | null; message: string }

/**
 * «مراجعة سابر+» (Programmes page): what planners changed in SABER+, old next to new, and the reviewer's three decisions — publish (applied
 * to the live programme in one go, or nothing if a clash appeared meanwhile), return with a note, or discard.
 */
export default function SaberReview() {
  const { t } = useTranslation()
  const [status, setStatus] = useState<Status>('submitted')
  const [selected, setSelected] = useState<string | null>(null)
  const list = useGet<{ data: Row[]; meta: { counts: Record<string, number> } }>('/admin/saber/reviews', { status }, { staleTime: 0 })
  const rows = list.data?.data ?? []
  const current = rows.find((r) => r.id === selected) ?? null

  return (
    <div className="space-y-5 pb-8">
      <PageHeader title={<span className="flex items-center gap-2"><Layers className="size-6 text-gold-600" />{t('saber.review.title')}</span>} subtitle={t('saber.review.subtitle')}
        actions={<Button variant="outline" size="sm" to="/admin/programs" icon={<ChevronLeft className="size-4 ltr:rotate-180" />}>{t('admin.menu.programs')}</Button>} />
      <Tabs tabs={(['submitted', 'draft', 'published', 'discarded'] as const).map((s) => ({ id: s, label: `${t(`saber.review.tabs.${s}`)}${list.data?.meta.counts[s] ? ` (${list.data.meta.counts[s]})` : ''}` }))}
        value={status} onChange={(s) => { setStatus(s); setSelected(null) }} />
      <div className="grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
        <ul className="space-y-2">
          {!list.data ? Array.from({ length: 3 }, (_, i) => <li key={i} className="h-20 animate-pulse rounded-2xl bg-white" />) : rows.length === 0 ? (
            <li className="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-navy-100 bg-white py-12 text-sm text-slate-400"><Inbox className="size-6" />{t('saber.review.empty')}</li>
          ) : rows.map((r) => (
            <li key={r.id}>
              <button type="button" onClick={() => setSelected(r.id)} aria-pressed={selected === r.id}
                className={clsx('w-full rounded-2xl border bg-white p-3 text-start shadow-sm transition', selected === r.id ? 'border-gold-400 ring-2 ring-gold-200' : 'border-navy-100 hover:border-gold-300')}>
                <span className="block truncate font-bold text-navy-900">{r.program.title}</span>
                <span className="mt-0.5 block text-[11px] text-slate-500">
                  <span className="font-mono" dir="ltr">{r.program.code}</span> · {t(`saber.review.source.${r.source}`, { defaultValue: r.source })}
                  {r.program.status === 'draft' && <span className="ms-1 rounded-full bg-sky-50 px-1.5 font-bold text-sky-700">{t('saber.draft.newGroup')}</span>}
                </span>
                <span className="mt-1 flex flex-wrap gap-x-2 text-[11px] text-slate-400">
                  {r.counts && <span>{t('saber.draft.changes', { groups: r.counts.groups, days: r.counts.days })}</span>}
                  {r.author && <span>{t('saber.review.author', { name: r.submitted_by ?? r.author })}</span>}
                  <span>{fmt.dateTime(r.submitted_at ?? r.updated_at)}</span>
                </span>
              </button>
            </li>
          ))}
        </ul>
        {current ? <ReviewPane row={current} onDone={() => { setSelected(null); list.refetch() }} /> : (
          <div className="grid place-items-center rounded-2xl border border-dashed border-navy-100 bg-white p-12 text-sm text-slate-400">{t('saber.review.pick')}</div>
        )}
      </div>
    </div>
  )
}

function ReviewPane({ row, onDone }: { row: Row; onDone: () => void }) {
  const { t } = useTranslation()
  const { data } = useGet<{ data: Row & { diff: Diff | null } }>(`/admin/saber/reviews/${row.id}`, undefined, { staleTime: 0 })
  const [busy, setBusy] = useState<'publish' | 'return' | 'discard' | null>(null)
  const [failures, setFailures] = useState<Failure[] | null>(null)
  const open = row.status === 'submitted' || row.status === 'draft'

  const publish = async () => {
    const note = await dialogs.prompt(t('saber.review.publishNote'))
    if (note === null) return
    setBusy('publish'); setFailures(null)
    try {
      const r = (await api.post(`/admin/saber/reviews/${row.id}/publish`, { note: note || null })).data.data
      toast(t('saber.review.published', { groups: r.changed_groups, days: r.changed_days })); onDone()
    } catch (e) {
      const details = (e as { response?: { data?: { details?: { failures?: Failure[] } } } }).response?.data?.details?.failures
      if (details) setFailures(details); else toast(errorMessage(e), 'error')
    } finally { setBusy(null) }
  }
  const sendBack = async () => {
    const note = await dialogs.prompt(t('saber.review.returnNote'))
    if (!note) return
    setBusy('return')
    try { await api.post(`/admin/saber/reviews/${row.id}/return`, { note }); toast(t('saber.review.returned')); onDone() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(null) }
  }
  const discard = async () => {
    const note = await dialogs.prompt(t('saber.review.discardNote'))
    if (note === null) return
    setBusy('discard')
    try { await api.post(`/admin/saber/reviews/${row.id}/discard`, { note: note || null }); toast(t('saber.review.discarded')); onDone() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(null) }
  }

  return (
    <section className="rounded-2xl border border-navy-100 bg-white shadow-sm">
      <header className="flex flex-wrap items-center gap-3 border-b border-navy-50 px-5 py-4">
        <div className="min-w-0 flex-1">
          <h3 className="truncate text-lg font-bold text-navy-900">{row.program.title}</h3>
          <p className="text-xs text-slate-500">
            {t(`saber.review.source.${row.source}`, { defaultValue: row.source })} · {t('saber.review.author', { name: row.submitted_by ?? row.author ?? '—' })}
            {row.submitted_at && <> · {t('saber.review.when', { date: fmt.dateTime(row.submitted_at) })}</>}
            {row.reviewed_by && <> · {t('saber.review.reviewedBy', { name: row.reviewed_by })}</>}
          </p>
          {row.review_note && <p className="mt-1 text-xs font-semibold text-amber-700">{t('saber.draft.note', { note: row.review_note })}</p>}
        </div>
        {open && (
          <div className="flex flex-wrap gap-2">
            <Button variant="ghost" size="sm" icon={<XCircle className="size-4" />} loading={busy === 'discard'} onClick={discard}>{t('saber.review.discard')}</Button>
            <Button variant="outline" size="sm" icon={<Undo2 className="size-4" />} loading={busy === 'return'} onClick={sendBack}>{t('saber.review.return')}</Button>
            <Button variant="gold" size="sm" icon={<CheckCircle2 className="size-4" />} loading={busy === 'publish'} onClick={publish}>{t('saber.review.publish')}</Button>
          </div>
        )}
      </header>
      {failures && (
        <div className="m-4 rounded-xl border border-rose-200 bg-rose-50/70 p-3 text-sm text-rose-800">
          <p className="mb-1.5 flex items-center gap-1.5 font-bold"><AlertTriangle className="size-4" />{t('saber.review.failed')}</p>
          <ul className="space-y-0.5 text-xs">{failures.map((f, i) => <li key={i}>{f.group}{f.date ? ` (${f.date})` : ''}: {f.message}</li>)}</ul>
        </div>
      )}
      <div className="max-h-[68vh] overflow-y-auto p-5">
        {!data ? <div className="h-40 animate-pulse rounded-xl bg-ivory" /> : data.data.diff ? <DiffView diff={data.data.diff} /> : <p className="py-8 text-center text-sm text-slate-400">{t('saber.review.noChanges')}</p>}
      </div>
    </section>
  )
}
