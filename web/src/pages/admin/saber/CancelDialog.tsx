import { Ban, FileText, Paperclip, RotateCcw } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Field, Modal, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import ProgramSearch from './ProgramSearch'
import type { CancelledList, Group, Notice, Overview, Program } from './types'

type Tab = 'program' | 'group' | 'cancelled'

/**
 * «إلغاء البرامج» (B2): cancel a programme or one group with a reason, who asked and a supporting document — the rooms and trainers of its
 * dates are freed and the registered people told — and the list of what is cancelled, where the super administrator reactivates.
 */
export default function CancelDialog({ overview, initial, onClose, onChanged }: {
  overview: Overview; initial: { program: Program; group: Group } | null; onClose: () => void; onChanged: (notices?: Notice[]) => void
}) {
  const { t } = useTranslation()
  const [tab, setTab] = useState<Tab>(initial ? 'group' : 'program')
  const [program, setProgram] = useState<Program | null>(initial?.program ?? null)
  const [groupId, setGroupId] = useState(initial?.group.id ?? '')
  const [reason, setReason] = useState('')
  const [requestedBy, setRequestedBy] = useState('')
  const [file, setFile] = useState<File | null>(null)
  const [busy, setBusy] = useState(false)
  const cancelled = useGet<{ data: CancelledList }>(tab === 'cancelled' ? '/admin/saber/cancelled' : null, undefined, { staleTime: 0 })
  const canCancel = !!overview.can['saber.program.cancel']
  const canReactivate = !!overview.can['saber.program.reactivate']
  const group = program?.groups.find((g) => g.id === groupId) ?? null
  const ready = reason.trim().length >= 3 && requestedBy.trim().length >= 2 && (tab === 'program' ? !!program : !!group)

  const submit = async () => {
    if (!ready || !program) return
    const name = tab === 'program' ? program.title : `${program.title} — ${group?.title || t('saber.group.n', { n: group?.number })}`
    if (!(await dialogs.confirm(t('saber.cancel.confirm', { name })))) return
    const body = new FormData()
    body.append('reason', reason.trim()); body.append('requested_by', requestedBy.trim())
    if (file) body.append('attachment', file)
    setBusy(true)
    try {
      await api.post(tab === 'program' ? `/admin/saber/programs/${program.id}/cancel` : `/admin/saber/groups/${groupId}/cancel`, body)
      toast(t(tab === 'program' ? 'saber.cancel.doneProgram' : 'saber.cancel.doneGroup'))
      onChanged(); onClose()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }

  const reactivate = async (type: 'program' | 'group', id: string) => {
    try {
      const { data } = await api.post(`/admin/saber/${type === 'program' ? 'programs' : 'groups'}/${id}/reactivate`)
      toast(t('saber.cancel.reactivated')); onChanged(data.notices); cancelled.refetch()
    } catch (e) { toast(errorMessage(e), 'error') }
  }

  const attachment = async (type: 'program' | 'group', id: string) => {
    try { window.open((await api.get(`/admin/saber/cancellations/${type}/${id}/attachment`)).data.data.url, '_blank', 'noopener') } catch (e) { toast(errorMessage(e), 'error') }
  }

  const tabs = [
    ...(canCancel ? [{ id: 'program' as Tab, label: t('saber.cancel.tabs.program') }, { id: 'group' as Tab, label: t('saber.cancel.tabs.group') }] : []),
    { id: 'cancelled' as Tab, label: t('saber.cancel.tabs.cancelled') },
  ]

  return (
    <Modal open onClose={onClose} title={<span className="flex items-center gap-2"><Ban className="size-5 text-rose-600" />{t('saber.cancel.title')}</span>} wide>
      <p className="-mt-2 mb-4 text-sm text-slate-500">{t('saber.cancel.hint')}</p>
      <Tabs tabs={tabs} value={canCancel ? tab : 'cancelled'} onChange={setTab} />

      {tab !== 'cancelled' && canCancel ? (
        <div className="mt-4 grid gap-4 md:grid-cols-2">
          <div className="space-y-3">
            <Field label={t('saber.cancel.pick')}>
              <ProgramSearch value={program} placeholder={t('saber.cancel.search')} filter={(p) => !p.cancelled} onPick={(p) => { setProgram(p); setGroupId(p.groups.find((g) => !g.cancelled)?.id ?? '') }} />
            </Field>
            {tab === 'group' && (
              <Field label={t('saber.cancel.pickGroup')}>
                <select className="h-10 w-full rounded-xl border border-navy-100 bg-white px-2 text-sm" value={groupId} disabled={!program} onChange={(e) => setGroupId(e.target.value)}>
                  <option value="">{t('saber.cancel.pickGroup')}</option>
                  {program?.groups.filter((g) => !g.cancelled).map((g) => <option key={g.id} value={g.id}>{g.title || t('saber.group.n', { n: g.number })}</option>)}
                </select>
              </Field>
            )}
          </div>
          <div className="space-y-3">
            <Field label={<>{t('saber.cancel.reason')} <span className="text-rose-600">*</span></>}>
              <textarea rows={3} value={reason} onChange={(e) => setReason(e.target.value)} className="w-full rounded-xl border border-navy-100 p-3 text-sm focus:border-gold-400 focus:outline-none" />
            </Field>
            <Field label={<>{t('saber.cancel.requestedBy')} <span className="text-rose-600">*</span></>}>
              <input value={requestedBy} onChange={(e) => setRequestedBy(e.target.value)} placeholder={t('saber.cancel.requestedByHint')} className="h-10 w-full rounded-xl border border-navy-100 px-3 text-sm focus:border-gold-400 focus:outline-none" />
            </Field>
            <Field label={t('saber.cancel.attachment')}>
              <label className="flex cursor-pointer items-center gap-2 rounded-xl border border-dashed border-navy-200 px-3 py-2.5 text-sm text-slate-500 hover:border-gold-400">
                <Paperclip className="size-4" /><span className="truncate">{file?.name ?? 'PDF · DOCX · PNG · JPG'}</span>
                <input type="file" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" className="hidden" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
              </label>
            </Field>
            <div className="flex justify-end pt-1">
              <Button variant="danger" loading={busy} disabled={!ready} onClick={submit}>{t(tab === 'program' ? 'saber.cancel.confirmProgram' : 'saber.cancel.confirmGroup')}</Button>
            </div>
          </div>
        </div>
      ) : (
        <div className="mt-4 space-y-4">
          {!cancelled.data ? <div className="h-24 animate-pulse rounded-xl bg-ivory" /> : cancelled.data.data.programs.length + cancelled.data.data.groups.length === 0 ? (
            <p className="rounded-xl border border-dashed border-navy-100 py-8 text-center text-sm text-slate-400">{t('saber.cancel.none')}</p>
          ) : (
            <>
              {([['programs', cancelled.data.data.programs], ['groups', cancelled.data.data.groups]] as const).map(([kind, list]) => list.length > 0 && (
                <section key={kind}>
                  <h4 className="mb-2 text-xs font-bold text-slate-500">{t(`saber.cancel.${kind}`)}</h4>
                  <ul className="space-y-2">
                    {list.map((row) => {
                      const type = kind === 'programs' ? 'program' : 'group'
                      const title = 'program' in row ? `${row.program.title} — ${row.title || t('saber.group.n', { n: row.number })}` : row.title
                      return (
                        <li key={row.id} className="flex flex-wrap items-center gap-3 rounded-xl border border-rose-100 bg-rose-50/40 px-3 py-2.5">
                          <div className="min-w-0 flex-1">
                            <div className="truncate text-sm font-bold text-navy-900">{title}</div>
                            <div className="mt-0.5 text-[11px] text-slate-500">
                              {[row.reason, row.requested_by && t('saber.cancel.requested', { name: row.requested_by }), row.cancelled_by && t('saber.cancel.by', { name: row.cancelled_by }), row.cancelled_at && fmt.date(row.cancelled_at)].filter(Boolean).join(' · ')}
                            </div>
                          </div>
                          {row.has_attachment && <Button size="sm" variant="ghost" icon={<FileText className="size-4" />} onClick={() => attachment(type, row.id)}>{t('saber.cancel.file')}</Button>}
                          {canReactivate
                            ? <Button size="sm" variant="outline" icon={<RotateCcw className="size-4" />} onClick={() => reactivate(type, row.id)}>{t('saber.cancel.reactivate')}</Button>
                            : <span className="text-[11px] font-semibold text-slate-400">{t('saber.cancel.superOnly')}</span>}
                        </li>
                      )
                    })}
                  </ul>
                </section>
              ))}
            </>
          )}
        </div>
      )}
    </Modal>
  )
}
