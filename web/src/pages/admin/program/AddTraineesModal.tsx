import clsx from 'clsx'
import { AlertCircle, CheckCircle2, Hash, School as SchoolIcon, Search, UserPlus, X } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Modal, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import { HoursChip } from './lastYearHours'

type Person = { id: string; employee_no: string; name: string | null; school: string | null; job_title: string | null; hours_last_year: number; hours_year: number }
type Checked = Partial<Person> & { employee_no: string; found: boolean; can_add: boolean; reason: string; message?: string; failures?: string[] }
type Candidates = { data: Person[]; meta: { school: string | null; staff: number; in_audience: number; outside_audience: number; already_registered: number; year: number } }
type School = { id: string; name_ar: string; name_en: string | null; code?: string; employees_count?: number }
type Result = { employee_id: string; ok: boolean; message?: string; status?: string }

/**
 * Adding trainees to a group: type or paste employee numbers, or pick a school and tick from its staff. Only people in the program's target
 * audience can be added — the school list shows only them (and says how many were left out), the number check names the reason for anyone
 * else, and the server refuses them again. Every person shows the hours they trained last year.
 */
export default function AddTraineesModal({ group, onClose, onDone }: { group: { id: string; title: string; code: string } | null; onClose: () => void; onDone: () => void }) {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const [mode, setMode] = useState<'number' | 'school'>('school')
  const [picked, setPicked] = useState<Record<string, Person>>({})
  const [results, setResults] = useState<Result[] | null>(null)
  const [busy, setBusy] = useState(false)

  // ── by employee number
  const [raw, setRaw] = useState('')
  const [checked, setChecked] = useState<Checked[] | null>(null)
  const [checking, setChecking] = useState(false)
  const numbers = useMemo(() => [...new Set(raw.split(/[\s,;،\n]+/).map((x) => x.trim()).filter(Boolean))], [raw])
  useEffect(() => {
    if (!group || !numbers.length) { setChecked(null); return }
    let live = true
    setChecking(true)
    const id = window.setTimeout(() => {
      api.post(`/admin/groups/${group.id}/trainees/check`, { employee_numbers: numbers.slice(0, 200) }).then((r) => { if (live) setChecked(r.data.data as Checked[]) }).catch((e) => toast(errorMessage(e), 'error')).finally(() => { if (live) setChecking(false) })
    }, 450)
    return () => { live = false; window.clearTimeout(id) }
  }, [group, numbers])
  const addable = (checked ?? []).filter((c): c is Checked & Person => c.can_add && !!c.id)

  // ── by school
  const [schoolQ, setSchoolQ] = useState('')
  const [schoolId, setSchoolId] = useState('')
  const [q, setQ] = useState('')
  const schools = useGet<{ data: School[] }>(group ? '/admin/schools' : null, { q: schoolQ, per_page: 30 }, { staleTime: 30_000 })
  const cand = useGet<Candidates>(group && schoolId ? `/admin/groups/${group?.id}/trainee-candidates` : null, { school_id: schoolId, q: q || undefined }, { staleTime: 0 })
  const people = cand.data?.data ?? []

  useEffect(() => { if (group) { setMode('school'); setPicked({}); setResults(null); setRaw(''); setChecked(null); setSchoolId(''); setSchoolQ(''); setQ('') } }, [group])
  if (!group) return null

  const toggle = (p: Person) => setPicked((s) => { const n = { ...s }; if (n[p.id]) delete n[p.id]; else n[p.id] = p; return n })
  const chosen = Object.values(picked)
  const allOn = people.length > 0 && people.every((p) => picked[p.id])
  const selectAll = () => setPicked((s) => { const n = { ...s }; if (allOn) people.forEach((p) => delete n[p.id]); else people.forEach((p) => { n[p.id] = p }); return n })
  const addChecked = () => setPicked((s) => { const n = { ...s }; addable.forEach((a) => { n[a.id] = a }); return n })

  const submit = async () => {
    setBusy(true)
    try {
      const { data } = await api.post(`/admin/groups/${group.id}/trainees`, { employee_ids: chosen.map((c) => c.id) })
      setResults(data.data as Result[])
      onDone()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }

  const tab = (id: 'number' | 'school', icon: React.ReactNode, label: string) => (
    <button type="button" role="tab" aria-selected={mode === id} onClick={() => setMode(id)} className={clsx('inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-bold transition', mode === id ? 'bg-navy-900 text-white shadow-sm' : 'bg-ivory text-navy-800 hover:bg-white hover:ring-1 hover:ring-navy-100')}>{icon}{label}</button>
  )

  return (
    <Modal open onClose={onClose} title={`${t('admin.programs.traineesAdd.title')} — ${group.title}`} wide>
      {results ? (
        <div className="space-y-3">
          <div className="grid gap-2">
            {results.map((r) => {
              const p = picked[r.employee_id]
              return (
                <div key={r.employee_id} className={clsx('flex items-start gap-2 rounded-xl border p-3 text-sm', r.ok ? 'border-emerald-200 bg-emerald-50/60' : 'border-red-200 bg-red-50/60')}>
                  {r.ok ? <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-emerald-600" /> : <AlertCircle className="mt-0.5 size-4 shrink-0 text-danger" />}
                  <div><div className="font-semibold text-navy-900">{p?.name} <span className="font-mono text-xs text-slate-400">{p?.employee_no}</span></div>{r.ok ? <div className="text-xs text-emerald-700">{t('admin.programs.traineesAdd.added')}</div> : <div className="text-xs text-danger">{r.message}</div>}</div>
                </div>
              )
            })}
          </div>
          <div className="flex justify-end"><Button onClick={onClose}>{t('common.close')}</Button></div>
        </div>
      ) : (
        <div className="space-y-4">
          <p className="rounded-xl bg-gold-50/70 px-3 py-2 text-xs leading-6 text-slate-700">{t('admin.programs.traineesAdd.rule')}</p>
          <div role="tablist" className="flex flex-wrap gap-2">
            {tab('school', <SchoolIcon className="size-4" />, t('admin.programs.traineesAdd.bySchool'))}
            {tab('number', <Hash className="size-4" />, t('admin.programs.traineesAdd.byNumber'))}
          </div>

          {mode === 'number' && (
            <div className="space-y-3">
              <textarea className="input min-h-24 font-mono" dir="ltr" value={raw} onChange={(e) => setRaw(e.target.value)} placeholder={t('admin.programs.traineesAdd.numbersPh') as string} aria-label={t('admin.programs.traineesAdd.byNumber') as string} />
              {checking && <Spinner />}
              {checked && (
                <ul className="max-h-64 space-y-1.5 overflow-y-auto">
                  {checked.map((c) => (
                    <li key={c.employee_no} className={clsx('flex flex-wrap items-center gap-2 rounded-xl border px-3 py-2 text-sm', c.can_add ? 'border-emerald-200 bg-emerald-50/50' : 'border-red-200 bg-red-50/50')}>
                      {c.can_add ? <CheckCircle2 className="size-4 shrink-0 text-emerald-600" /> : <AlertCircle className="size-4 shrink-0 text-danger" />}
                      <span className="font-mono text-xs" dir="ltr">{c.employee_no}</span>
                      {c.found ? <><span className="font-semibold text-navy-900">{c.name}</span><span className="text-xs text-slate-500">{c.job_title} · {c.school}</span><HoursChip hours={c.hours_last_year} year={c.hours_year} className="ms-auto" /></> : null}
                      {!c.can_add && <span className="w-full ps-6 text-xs text-danger">{c.message}</span>}
                    </li>
                  ))}
                </ul>
              )}
              <Button variant="outline" size="sm" disabled={!addable.length} onClick={addChecked} icon={<UserPlus className="size-4" />}>{t('admin.programs.traineesAdd.addToList', { n: addable.length })}</Button>
            </div>
          )}

          {mode === 'school' && (
            <div className="space-y-3">
              <div className="grid gap-2 sm:grid-cols-2">
                <div className="space-y-1.5">
                  <div className="relative"><Search className="pointer-events-none absolute start-3 top-3 size-4 text-slate-400" /><input className="input ps-9" value={schoolQ} onChange={(e) => setSchoolQ(e.target.value)} placeholder={t('admin.programs.traineesAdd.findSchool') as string} aria-label={t('admin.programs.traineesAdd.findSchool') as string} /></div>
                  <select className="input" size={5} value={schoolId} onChange={(e) => { setSchoolId(e.target.value); setQ('') }} aria-label={t('admin.programs.traineesAdd.school') as string}>
                    {(schools.data?.data ?? []).map((s) => <option key={s.id} value={s.id}>{(en ? s.name_en : s.name_ar) || s.name_ar}{s.employees_count !== undefined ? ` · ${s.employees_count}` : ''}</option>)}
                  </select>
                </div>
                <div className="space-y-1.5">
                  <div className="relative"><Search className="pointer-events-none absolute start-3 top-3 size-4 text-slate-400" /><input className="input ps-9" disabled={!schoolId} value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('admin.programs.traineesAdd.findPerson') as string} /></div>
                  {cand.data && (
                    <div className="rounded-xl bg-ivory p-3 text-xs leading-6 text-slate-600">
                      <div className="font-bold text-navy-900">{cand.data.meta.school}</div>
                      <div>{t('admin.programs.traineesAdd.audienceOf', { n: cand.data.meta.in_audience, total: cand.data.meta.staff })}</div>
                      {cand.data.meta.outside_audience > 0 && <div className="text-slate-500">{t('admin.programs.traineesAdd.outside', { n: cand.data.meta.outside_audience })}</div>}
                      {cand.data.meta.already_registered > 0 && <div className="text-slate-500">{t('admin.programs.traineesAdd.already', { n: cand.data.meta.already_registered })}</div>}
                    </div>
                  )}
                </div>
              </div>
              {schoolId && (cand.isLoading ? <Spinner /> : (
                <div className="overflow-hidden rounded-xl border border-navy-100">
                  <div className="flex items-center justify-between bg-ivory px-3 py-2 text-xs font-bold text-slate-600">
                    <label className="flex items-center gap-2"><input type="checkbox" className="accent-gold-600" checked={allOn} onChange={selectAll} disabled={!people.length} />{t('admin.programs.traineesAdd.selectAll')}</label>
                    <span>{t('admin.programs.traineesAdd.shown', { n: people.length })}</span>
                  </div>
                  <ul className="max-h-72 divide-y divide-slate-100 overflow-y-auto">
                    {people.length === 0 && <li className="p-4 text-center text-sm text-slate-500">{t('admin.programs.traineesAdd.none')}</li>}
                    {people.map((p) => (
                      <li key={p.id}>
                        <label className="flex cursor-pointer flex-wrap items-center gap-3 px-3 py-2.5 hover:bg-ivory/70">
                          <input type="checkbox" className="accent-gold-600" checked={!!picked[p.id]} onChange={() => toggle(p)} />
                          <span className="min-w-0 flex-1"><span className="block font-semibold text-navy-900">{p.name}</span><span className="block text-xs text-slate-500"><span className="font-mono" dir="ltr">{p.employee_no}</span> · {p.job_title}</span></span>
                          <HoursChip hours={p.hours_last_year} year={p.hours_year} />
                        </label>
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </div>
          )}

          {chosen.length > 0 && (
            <div className="rounded-xl border border-gold-300/70 bg-gold-50/40 p-3">
              <div className="mb-2 text-xs font-bold text-navy-900">{t('admin.programs.traineesAdd.selected', { n: chosen.length })}</div>
              <div className="flex max-h-28 flex-wrap gap-1.5 overflow-y-auto">
                {chosen.map((p) => <button key={p.id} type="button" onClick={() => toggle(p)} className="inline-flex items-center gap-1.5 rounded-full bg-navy-900 py-1 ps-3 pe-2 text-xs font-semibold text-white">{p.name}<HoursChip hours={p.hours_last_year} className="!bg-white/15 !text-white !ring-0" /><X className="size-3 opacity-70" /></button>)}
              </div>
            </div>
          )}
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button>
            <Button variant="gold" icon={<UserPlus className="size-4" />} disabled={!chosen.length} loading={busy} onClick={() => void submit()}>{t('admin.programs.traineesAdd.submit', { n: chosen.length })}</Button>
          </div>
        </div>
      )}
    </Modal>
  )
}
