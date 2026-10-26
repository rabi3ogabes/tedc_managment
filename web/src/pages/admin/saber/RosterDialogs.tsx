import clsx from 'clsx'
import { BadgeCheck, Download, FileSpreadsheet, Link2, Pencil, Search, ShieldCheck, Trash2, Upload, UserPlus, Users, X } from 'lucide-react'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Field, Modal } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { toast } from '@/lib/toast'
import ProgramSearch from './ProgramSearch'
import type { Program } from './types'

type Link = { id: string; serial: string; survey_link: string | null; survey_edit_link: string | null; program: { id: string; title: string; code: string } | null }

/** D3 — «روابط الاستمارات»: upload the workbook (or the template), list the links, give each to a programme. */
export function SurveyLinksDialog({ onClose, onChanged }: { onClose: () => void; onChanged: () => void }) {
  const { t } = useTranslation()
  const { data, refetch } = useGet<{ data: Link[] }>('/admin/saber/data/survey-links', undefined, { staleTime: 0 })
  const [replace, setReplace] = useState(true)
  const [busy, setBusy] = useState(false)
  const [assigning, setAssigning] = useState<Link | null>(null)
  const input = useRef<HTMLInputElement>(null)

  const upload = async (f: File) => {
    setBusy(true)
    try {
      const body = new FormData(); body.append('file', f); body.append('replace', replace ? '1' : '0')
      toast(t('saber.data.links.uploaded', { n: (await api.post('/admin/saber/data/survey-links', body)).data.data.uploaded })); refetch()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const assign = async (link: Link, programId: string | null) => {
    try { await api.post(`/admin/saber/data/survey-links/${link.id}/assign`, { program_id: programId }); toast(t('saber.data.links.done')); refetch(); onChanged(); setAssigning(null) } catch (e) { toast(errorMessage(e), 'error') }
  }
  const clear = async () => {
    if (!(await dialogs.confirm(t('saber.data.links.clearConfirm')))) return
    try { await api.delete('/admin/saber/data/survey-links'); refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }

  return (
    <Modal open onClose={onClose} title={<span className="flex items-center gap-2"><Link2 className="size-5 text-gold-600" />{t('saber.data.links.title')}</span>} wide>
      <p className="-mt-2 mb-4 text-sm text-slate-500">{t('saber.data.links.desc')}</p>
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <Button icon={<Upload className="size-4" />} loading={busy} onClick={() => input.current?.click()}>{t('saber.data.links.upload')}</Button>
        <input ref={input} type="file" accept=".xlsx,.xls" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) upload(f); e.target.value = '' }} />
        <label className="flex items-center gap-1.5 text-xs font-semibold text-slate-600"><input type="checkbox" checked={replace} onChange={(e) => setReplace(e.target.checked)} />{t('saber.data.links.replace')}</label>
        <span className="ms-auto flex gap-1.5">
          <Button size="sm" variant="ghost" icon={<Download className="size-4" />} onClick={() => downloadFile('/admin/saber/data/survey-links/template', 'survey-links-template.xlsx')}>{t('saber.data.links.template')}</Button>
          {!!data?.data.length && <Button size="sm" variant="ghost" icon={<Trash2 className="size-4" />} onClick={clear}>{t('saber.data.links.clear')}</Button>}
        </span>
      </div>
      <div className="mb-2 text-xs font-semibold text-slate-500">{t('saber.data.links.count', { n: data?.data.length ?? 0 })}</div>
      <div className="max-h-96 overflow-auto rounded-xl border border-navy-100">
        {!data ? <div className="h-24 animate-pulse bg-ivory" /> : data.data.length === 0 ? <p className="py-8 text-center text-sm text-slate-400">{t('saber.data.links.none')}</p> : (
          <table className="w-full text-xs">
            <thead className="sticky top-0 bg-ivory text-slate-500"><tr><th className="w-14 px-2 py-2 text-start">{t('saber.data.links.serial')}</th><th className="px-2 text-start">{t('saber.data.links.link')}</th><th className="px-2 text-start">{t('saber.data.links.program')}</th><th /></tr></thead>
            <tbody className="divide-y divide-navy-50">
              {data.data.map((l) => (
                <tr key={l.id}>
                  <td className="px-2 py-2 font-bold tabular-nums text-navy-900">{l.serial}</td>
                  <td className="max-w-64 px-2">
                    {l.survey_link ? <a href={l.survey_link} target="_blank" rel="noopener noreferrer" className="block truncate text-navy-700 underline decoration-gold-400 underline-offset-2" dir="ltr">{l.survey_link}</a> : '—'}
                    {l.survey_edit_link && <a href={l.survey_edit_link} target="_blank" rel="noopener noreferrer" className="block truncate text-[10px] text-slate-400" dir="ltr">{t('saber.data.links.edit')}: {l.survey_edit_link}</a>}
                  </td>
                  <td className="px-2">{l.program ? <span className="inline-flex items-center gap-1 font-semibold text-emerald-700"><BadgeCheck className="size-3.5" />{l.program.title}</span> : <span className="text-slate-400">—</span>}</td>
                  <td className="whitespace-nowrap px-2 text-end">
                    <Button size="sm" variant="outline" onClick={() => setAssigning(l)}>{t('saber.data.links.assign')}</Button>
                    {l.program && <button type="button" onClick={() => assign(l, null)} aria-label={t('saber.data.links.unassign')} title={t('saber.data.links.unassign')} className="ms-1 inline-grid size-7 place-items-center rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-600"><X className="size-3.5" /></button>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </div>
      {assigning && (
        <Modal open onClose={() => setAssigning(null)} title={`${t('saber.data.links.assign')} · ${assigning.serial}`}>
          <ProgramSearch value={null} placeholder={t('saber.cancel.search')} onPick={(p: Program) => assign(assigning, p.id)} />
        </Modal>
      )}
    </Modal>
  )
}

type TrainerRow = { id: string; name: string; national_id: string | null; has_national_id: boolean; email: string | null; phone: string | null; type: string | null; status: string; validated: boolean; employee_linked: boolean }
type TrainerList = { data: TrainerRow[]; meta: { counts: Record<string, number>; total: number; pending: number; types: Record<string, string>; can_validate: boolean } }
type ImportRow = { row: number; name: string; national_id: string | null; national_id_raw: string | null; email: string | null; phone: string | null; type: string; action: 'create' | 'update'; trainer_id: string | null }

/** D4 — «إدارة المدربين»: list, add / edit, approve, Excel template / import / export, fill from staff records. */
export function TrainersManagerDialog({ onClose, onChanged }: { onClose: () => void; onChanged: () => void }) {
  const { t, i18n } = useTranslation()
  const [q, setQ] = useState('')
  const [type, setType] = useState('')
  const [pending, setPending] = useState(false)
  const { data, refetch } = useGet<TrainerList>('/admin/saber/data/trainers', { q: q.trim() || undefined, type: type || undefined, pending: pending ? 1 : undefined }, { staleTime: 0, placeholderData: (p) => p })
  const [form, setForm] = useState<{ id?: string; name: string; national_id: string; email: string; phone: string; type: string } | null>(null)
  const [preview, setPreview] = useState<{ rows: ImportRow[]; errors: { row: number; message: string }[] } | null>(null)
  const [busy, setBusy] = useState(false)
  const input = useRef<HTMLInputElement>(null)
  const types = data?.meta.types ?? {}
  const typeLabel = (k: string | null) => (k && types[k]) || (i18n.language === 'en' ? k ?? '—' : k ?? '—')
  const done = () => { refetch(); onChanged() }

  const save = async () => {
    if (!form) return
    setBusy(true)
    try {
      const body = { name: form.name.trim(), national_id: form.national_id.trim() || null, email: form.email.trim() || null, phone: form.phone.trim() || null, type: form.type || null }
      if (form.id) await api.put(`/admin/saber/data/trainers/${form.id}`, body); else await api.post('/admin/saber/data/trainers', body)
      toast(t('saber.data.trainers.saved')); setForm(null); done()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const remove = async (r: TrainerRow) => {
    if (!(await dialogs.confirm(t('saber.data.trainers.removeConfirm', { name: r.name })))) return
    try { const res = await api.delete(`/admin/saber/data/trainers/${r.id}`); toast(res.data?.data?.deactivated ? t('saber.data.trainers.deactivated') : t('saber.data.trainers.removed')); done() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const validate = async (r: TrainerRow) => { try { await api.post(`/admin/saber/data/trainers/${r.id}/validate`); done() } catch (e) { toast(errorMessage(e), 'error') } }
  const readFile = async (f: File) => {
    setBusy(true)
    try { const b = new FormData(); b.append('file', f); setPreview((await api.post('/admin/saber/data/trainers/import/preview', b)).data.data) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const applyImport = async () => {
    if (!preview) return
    setBusy(true)
    try { const r = (await api.post('/admin/saber/data/trainers/import/apply', { rows: preview.rows })).data.data; toast(t('saber.data.trainers.imported', { created: r.created, updated: r.updated })); setPreview(null); done() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const fill = async () => {
    setBusy(true)
    try { const r = (await api.post('/admin/saber/data/trainers/fill-from-staff')).data.data; toast(t('saber.data.trainers.filled', { matched: r.matched, filled: r.filled })); done() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const input2 = 'h-10 w-full rounded-xl border border-navy-100 bg-white px-3 text-sm focus:border-gold-400 focus:outline-none'

  return (
    <Modal open onClose={onClose} title={<span className="flex items-center gap-2"><Users className="size-5 text-gold-600" />{t('saber.data.trainers.title')}</span>} wide>
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <Button size="sm" icon={<UserPlus className="size-4" />} onClick={() => setForm({ name: '', national_id: '', email: '', phone: '', type: 'center' })}>{t('saber.data.trainers.add')}</Button>
        <Button size="sm" variant="outline" icon={<FileSpreadsheet className="size-4" />} loading={busy && !form} onClick={() => input.current?.click()}>{t('saber.data.trainers.import')}</Button>
        <input ref={input} type="file" accept=".xlsx,.xls" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) readFile(f); e.target.value = '' }} />
        <Button size="sm" variant="outline" icon={<ShieldCheck className="size-4" />} onClick={fill}>{t('saber.data.trainers.fill')}</Button>
        <span className="ms-auto flex gap-1">
          <Button size="sm" variant="ghost" icon={<Download className="size-4" />} onClick={() => downloadFile('/admin/saber/data/trainers/export', `trainers-${new Date().toISOString().slice(0, 10)}.xlsx`)}>{t('saber.data.trainers.export')} ({data?.meta.total ?? 0})</Button>
          <Button size="sm" variant="ghost" icon={<Download className="size-4" />} onClick={() => downloadFile('/admin/saber/data/trainers/export?template=1', 'trainers-template.xlsx')}>{t('saber.data.trainers.template')}</Button>
        </span>
      </div>
      <p className="mb-3 text-[11px] text-slate-400">{t('saber.data.trainers.importHint')}</p>

      {preview ? (
        <div className="space-y-3">
          <div className="max-h-80 overflow-auto rounded-xl border border-navy-100">
            <table className="w-full text-xs">
              <thead className="sticky top-0 bg-ivory text-slate-500"><tr>{[t('saber.data.trainers.name'), t('saber.data.trainers.nationalId'), t('saber.data.trainers.email'), t('saber.data.trainers.phone'), t('saber.data.trainers.type'), ''].map((h, i) => <th key={i} className="px-2 py-2 text-start font-bold">{h}</th>)}</tr></thead>
              <tbody className="divide-y divide-navy-50">
                {preview.rows.map((r) => (
                  <tr key={r.row}>
                    <td className="px-2 py-1.5 font-semibold text-navy-900">{r.name}</td><td className="px-2 tabular-nums" dir="ltr">{r.national_id ?? '—'}</td><td className="px-2" dir="ltr">{r.email ?? '—'}</td><td className="px-2 tabular-nums" dir="ltr">{r.phone ?? '—'}</td><td className="px-2">{typeLabel(r.type)}</td>
                    <td className="px-2"><span className={clsx('rounded-full px-2 py-0.5 text-[10px] font-bold', r.action === 'create' ? 'bg-sky-50 text-sky-700' : 'bg-slate-100 text-slate-600')}>{t(`saber.data.trainers.action.${r.action}`)}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {preview.errors.length > 0 && <details className="rounded-xl border border-amber-200 bg-amber-50/50 p-3 text-xs" open><summary className="cursor-pointer font-bold text-amber-800">{t('saber.data.trainers.errors', { n: preview.errors.length })}</summary><ul className="mt-2 space-y-0.5 text-amber-900">{preview.errors.map((e, i) => <li key={i}>{e.row}: {e.message}</li>)}</ul></details>}
          <div className="flex justify-end gap-2"><Button variant="ghost" onClick={() => setPreview(null)}>{t('saber.data.back')}</Button><Button variant="gold" loading={busy} onClick={applyImport}>{t('saber.data.apply')} ({preview.rows.length})</Button></div>
        </div>
      ) : (
        <>
          <div className="mb-3 flex flex-wrap items-center gap-2">
            <label className="flex h-10 min-w-56 flex-1 items-center gap-2 rounded-xl border border-navy-100 px-3 focus-within:border-gold-400"><Search className="size-4 text-slate-400" /><input value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('saber.data.trainers.search')} className="w-full bg-transparent text-sm outline-none" /></label>
            <select value={type} onChange={(e) => setType(e.target.value)} className="h-10 rounded-xl border border-navy-100 bg-white px-2 text-sm">
              <option value="">{t('saber.data.trainers.allTypes')} ({data?.meta.total ?? 0})</option>
              {Object.entries(types).map(([k, label]) => <option key={k} value={k}>{label} ({data?.meta.counts[k] ?? 0})</option>)}
            </select>
            <label className="flex items-center gap-1.5 text-xs font-semibold text-slate-600"><input type="checkbox" checked={pending} onChange={(e) => setPending(e.target.checked)} />{t('saber.data.trainers.pendingOnly')}</label>
          </div>
          {!!data?.meta.pending && <p className="mb-2 rounded-xl bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">{t('saber.data.trainers.pendingNote', { n: data.meta.pending })}</p>}
          <div className="max-h-[22rem] overflow-auto rounded-xl border border-navy-100">
            {!data ? <div className="h-24 animate-pulse bg-ivory" /> : data.data.length === 0 ? <p className="py-8 text-center text-sm text-slate-400">{t('saber.data.trainers.none')}</p> : (
              <table className="w-full text-xs">
                <thead className="sticky top-0 bg-ivory text-slate-500"><tr>{[t('saber.data.trainers.name'), t('saber.data.trainers.nationalId'), t('saber.data.trainers.email'), t('saber.data.trainers.phone'), t('saber.data.trainers.type'), ''].map((h, i) => <th key={i} className="px-2 py-2 text-start font-bold">{h}</th>)}</tr></thead>
                <tbody className="divide-y divide-navy-50">
                  {data.data.map((r) => (
                    <tr key={r.id} className={clsx(!r.validated && 'bg-amber-50/40')}>
                      <td className="px-2 py-2"><span className="font-semibold text-navy-900">{r.name}</span>{r.employee_linked && <span className="ms-1 text-[10px] text-emerald-600" title={t('saber.data.trainers.linked')}>●</span>}</td>
                      <td className="px-2 tabular-nums" dir="ltr">{r.national_id ?? '—'}</td><td className="max-w-40 truncate px-2" dir="ltr">{r.email ?? '—'}</td><td className="px-2 tabular-nums" dir="ltr">{r.phone ?? '—'}</td><td className="px-2">{typeLabel(r.type)}</td>
                      <td className="whitespace-nowrap px-2 text-end">
                        {data.meta.can_validate
                          ? <button type="button" onClick={() => validate(r)} className={clsx('rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset', r.validated ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-amber-50 text-amber-700 ring-amber-600/20 hover:ring-gold-400')} title={r.validated ? t('saber.data.trainers.unapprove') : t('saber.data.trainers.approve')}>{r.validated ? t('saber.data.trainers.approved') : t('saber.data.trainers.approve')}</button>
                          : !r.validated && <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold text-amber-700">{t('saber.data.trainers.pending')}</span>}
                        <button type="button" onClick={() => setForm({ id: r.id, name: r.name, national_id: '', email: r.email ?? '', phone: r.phone ?? '', type: r.type ?? 'center' })} aria-label={t('saber.data.trainers.edit')} className="ms-1 inline-grid size-7 place-items-center rounded-lg text-slate-400 hover:bg-ivory hover:text-navy-900"><Pencil className="size-3.5" /></button>
                        <button type="button" onClick={() => remove(r)} aria-label={t('saber.data.trainers.removed')} className="inline-grid size-7 place-items-center rounded-lg text-slate-400 hover:bg-rose-50 hover:text-rose-600"><Trash2 className="size-3.5" /></button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </>
      )}

      {form && (
        <Modal open onClose={() => setForm(null)} title={form.id ? t('saber.data.trainers.edit') : t('saber.data.trainers.add')}>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={<>{t('saber.data.trainers.name')} <span className="text-rose-600">*</span></>} className="sm:col-span-2"><input className={input2} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
            <Field label={t('saber.data.trainers.nationalId')} hint={form.id ? '••••' : undefined}><input dir="ltr" inputMode="numeric" className={input2} value={form.national_id} onChange={(e) => setForm({ ...form, national_id: e.target.value.replace(/\D/g, '') })} /></Field>
            <Field label={t('saber.data.trainers.type')}><select className={input2} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>{Object.entries(types).map(([k, l]) => <option key={k} value={k}>{l}</option>)}</select></Field>
            <Field label={t('saber.data.trainers.email')}><input dir="ltr" type="email" className={input2} value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></Field>
            <Field label={t('saber.data.trainers.phone')}><input dir="ltr" inputMode="tel" className={input2} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></Field>
          </div>
          <div className="mt-5 flex justify-end gap-2"><Button variant="ghost" onClick={() => setForm(null)}>{t('common.cancel', { defaultValue: 'إلغاء' })}</Button><Button loading={busy} disabled={!form.name.trim()} onClick={save}>{t('common.save', { defaultValue: 'حفظ' })}</Button></div>
        </Modal>
      )}
    </Modal>
  )
}
