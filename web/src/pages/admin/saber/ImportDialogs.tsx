import clsx from 'clsx'
import { AlertTriangle, BadgeCheck, CalendarRange, CheckCircle2, DoorOpen, FileSpreadsheet, Link2, ShieldCheck, Sparkles, Upload } from 'lucide-react'
import { useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Modal } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Track = {
  key: string; label: string; program_id: string; program: string; score: number; group_name: string; supervisor: string | null; index: number
  days: { date: string; room: string }[]; group_id: string | null; target: string | null; verdict: 'match' | 'diff' | 'new'; validated: boolean
}
type CalendarPlan = { cells: number; tracks: Track[]; unmatched: { label: string; count: number; best: string | null; score: number }[]; rooms: { name: string; capacity: number | null; exists: boolean }[]; supervisors: { name: string; user: string | null }[] }
type CalendarResult = { updated: number; created: number; rooms_created: number; approved: number; failures: { group: string; program: string; date: string | null; message: string }[]; supervisors_missing: string[] }

/** A file chooser styled as a drop zone; the parent reads the file. */
function FilePick({ onFile, busy, label }: { onFile: (f: File) => void; busy: boolean; label: string }) {
  const { t } = useTranslation()
  const input = useRef<HTMLInputElement>(null)
  const [over, setOver] = useState(false)
  return (
    <button type="button" onClick={() => input.current?.click()} disabled={busy}
      onDragOver={(e) => { e.preventDefault(); setOver(true) }} onDragLeave={() => setOver(false)} onDrop={(e) => { e.preventDefault(); setOver(false); const f = e.dataTransfer.files[0]; if (f) onFile(f) }}
      className={clsx('flex w-full flex-col items-center gap-2 rounded-2xl border-2 border-dashed px-6 py-8 text-center transition', over ? 'border-gold-400 bg-gold-50' : 'border-navy-100 bg-ivory/50 hover:border-gold-300')}>
      <span className="grid size-12 place-items-center rounded-2xl bg-white text-gold-600 shadow-sm">{busy ? <span className="size-5 animate-spin rounded-full border-2 border-gold-500 border-t-transparent" /> : <FileSpreadsheet className="size-6" />}</span>
      <span className="text-sm font-bold text-navy-900">{busy ? t('saber.data.reading') : label}</span>
      <span className="text-xs text-slate-400">.xlsx · .xls</span>
      <input ref={input} type="file" accept=".xlsx,.xls" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) onFile(f); e.target.value = '' }} />
    </button>
  )
}

function Stat({ icon, label, value, tone }: { icon: ReactNode; label: string; value: number; tone?: 'emerald' }) {
  return (
    <div className={clsx('rounded-xl border p-3', tone === 'emerald' ? 'border-emerald-100 bg-emerald-50/50' : 'border-navy-100 bg-white')}>
      <div className="flex items-center gap-1.5 text-[11px] font-bold text-slate-500">{icon}{label}</div>
      <div className="mt-1 text-xl font-bold tabular-nums text-navy-900">{fmt.number(value)}</div>
    </div>
  )
}

const VERDICT = { match: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20', diff: 'bg-amber-50 text-amber-700 ring-amber-600/20', new: 'bg-sky-50 text-sky-700 ring-sky-600/20' }

/** D1 — «استيراد تقويم القاعات التدريبية»: file → matched groups (with their target and verdict) → apply, approve, re-check. */
export function CalendarImportDialog({ canApprove, onClose, onDone }: { canApprove: boolean; onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation()
  const [file, setFile] = useState<File | null>(null)
  const [plan, setPlan] = useState<CalendarPlan | null>(null)
  const [chosen, setChosen] = useState<Set<string>>(new Set())
  const [approve, setApprove] = useState(canApprove)
  const [busy, setBusy] = useState(false)
  const [result, setResult] = useState<CalendarResult | null>(null)

  const read = async (f: File) => {
    setFile(f); setBusy(true); setResult(null)
    try {
      const body = new FormData(); body.append('file', f)
      const p: CalendarPlan = (await api.post('/admin/saber/data/calendar/preview', body)).data.data
      setPlan(p); setChosen(new Set(p.tracks.filter((x) => x.verdict !== 'match').map((x) => x.key)))
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const apply = async () => {
    if (!plan) return
    setBusy(true)
    try {
      const tracks = plan.tracks.filter((x) => chosen.has(x.key)).map((x) => ({ program_id: x.program_id, group_id: x.group_id, group_name: x.group_name, supervisor: x.supervisor, days: x.days.map((d) => ({ ...d, capacity: plan.rooms.find((r) => r.name === d.room)?.capacity ?? null })) }))
      const r: CalendarResult = (await api.post('/admin/saber/data/calendar/apply', { tracks, approve })).data.data
      setResult(r); toast(t('saber.data.calendar.result', { updated: r.updated, created: r.created, approved: r.approved, rooms: r.rooms_created })); onDone()
      if (file) await read(file)   // re-check against what is saved now
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const byProgram = plan ? Object.entries(plan.tracks.reduce<Record<string, Track[]>>((m, x) => ({ ...m, [x.program]: [...(m[x.program] ?? []), x] }), {})) : []

  return (
    <Modal open onClose={onClose} title={<span className="flex items-center gap-2"><CalendarRange className="size-5 text-gold-600" />{t('saber.data.calendar.title')}</span>} wide>
      <p className="-mt-2 mb-4 text-sm text-slate-500">{t('saber.data.calendar.desc')} {t('saber.data.calendar.hint')}</p>
      {!plan ? <FilePick onFile={read} busy={busy} label={t('saber.data.file')} /> : (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
            <Stat icon={<CalendarRange className="size-3.5" />} label={t('saber.data.calendar.cells')} value={plan.cells} />
            <Stat icon={<CheckCircle2 className="size-3.5" />} label={t('saber.data.calendar.tracks')} value={plan.tracks.length} tone="emerald" />
            <Stat icon={<DoorOpen className="size-3.5" />} label={t('saber.data.calendar.rooms')} value={plan.rooms.length} />
            <Stat icon={<ShieldCheck className="size-3.5" />} label={t('saber.data.calendar.sups')} value={plan.supervisors.length} />
          </div>
          {(plan.rooms.some((r) => !r.exists) || plan.supervisors.some((s) => !s.user)) && (
            <div className="flex flex-wrap gap-1.5 text-[11px]">
              {plan.rooms.filter((r) => !r.exists).map((r) => <span key={r.name} className="rounded-full bg-sky-50 px-2 py-0.5 font-semibold text-sky-700">{t('saber.data.calendar.newRoom')}: {r.name}</span>)}
              {plan.supervisors.filter((s) => !s.user).map((s) => <span key={s.name} className="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-700">{s.name} — {t('saber.data.calendar.supMissing')}</span>)}
            </div>
          )}
          <div className="flex items-center justify-between text-xs font-semibold text-slate-500">
            <span>{t('saber.data.calendar.selected', { n: chosen.size })}</span>
            <button type="button" className="font-bold text-navy-700 hover:text-gold-700" onClick={() => setChosen(new Set(plan.tracks.map((x) => x.key)))}>{t('saber.data.calendar.selectAll')}</button>
          </div>
          <div className="max-h-[22rem] space-y-3 overflow-y-auto pe-1">
            {byProgram.map(([program, tracks]) => (
              <section key={program} className="rounded-xl border border-navy-100">
                <h5 className="border-b border-navy-50 bg-ivory/60 px-3 py-2 text-sm font-bold text-navy-900">{program}</h5>
                <ul className="divide-y divide-navy-50">
                  {tracks.map((x) => (
                    <li key={x.key}>
                      <label className="flex cursor-pointer items-start gap-3 px-3 py-2.5">
                        <input type="checkbox" className="mt-1" checked={chosen.has(x.key)} onChange={() => setChosen((s) => { const n = new Set(s); if (n.has(x.key)) n.delete(x.key); else n.add(x.key); return n })} />
                        <span className="min-w-0 flex-1">
                          <span className="flex flex-wrap items-center gap-1.5">
                            <span className="text-sm font-bold text-navy-900">{x.group_name}</span>
                            <span className={clsx('rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset', VERDICT[x.verdict])}>{t(`saber.data.calendar.verdict.${x.verdict}`)}</span>
                            {x.validated && <BadgeCheck className="size-3.5 text-emerald-600" />}
                            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-600">{t('saber.data.calendar.days', { n: x.days.length })}</span>
                            {x.supervisor && <span className="text-[11px] text-slate-500">· {x.supervisor}</span>}
                          </span>
                          <span className="mt-0.5 block truncate text-[11px] text-slate-400">
                            {x.days[0]?.date} — {x.days[x.days.length - 1]?.date} · {t('saber.data.calendar.from', { label: x.label })}{x.target ? ` · ${t('saber.data.calendar.target', { name: x.target })}` : ''} · {t('saber.data.calendar.score', { n: Math.round(x.score * 100) })}
                          </span>
                        </span>
                      </label>
                    </li>
                  ))}
                </ul>
              </section>
            ))}
          </div>
          {result && result.failures.length > 0 && (
            <details className="rounded-xl border border-amber-200 bg-amber-50/50 p-3 text-xs" open>
              <summary className="cursor-pointer font-bold text-amber-800"><AlertTriangle className="me-1 inline size-3.5" />{t('saber.data.calendar.failures', { n: result.failures.length })}</summary>
              <ul className="mt-2 space-y-1 text-amber-900">{result.failures.slice(0, 40).map((f, i) => <li key={i}>{f.program} — {f.group}{f.date ? ` (${f.date})` : ''}: {f.message}</li>)}</ul>
            </details>
          )}
          {plan.unmatched.length > 0 && (
            <details className="rounded-xl border border-navy-100 p-3 text-xs">
              <summary className="cursor-pointer font-bold text-slate-600">{t('saber.data.calendar.unmatched', { n: plan.unmatched.length })}</summary>
              <ul className="mt-2 space-y-1 text-slate-600">{plan.unmatched.map((u) => <li key={u.label} className="flex justify-between gap-2"><span className="truncate">{u.label}</span><span className="shrink-0 text-slate-400">{u.count} · {u.best ? t('saber.data.calendar.best', { name: u.best }) : ''}</span></li>)}</ul>
            </details>
          )}
          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-navy-50 pt-3">
            <label className={clsx('flex items-center gap-2 text-xs font-semibold', canApprove ? 'text-slate-600' : 'text-slate-300')}>
              <input type="checkbox" disabled={!canApprove} checked={approve && canApprove} onChange={(e) => setApprove(e.target.checked)} />{t('saber.data.calendar.approve')}
            </label>
            <div className="flex gap-2">
              <Button variant="ghost" onClick={() => { setPlan(null); setFile(null); setResult(null) }}>{t('saber.data.back')}</Button>
              {file && <Button variant="outline" loading={busy} onClick={() => read(file)}>{t('saber.data.recheck')}</Button>}
              <Button variant="gold" icon={<Sparkles className="size-4" />} loading={busy} disabled={chosen.size === 0} onClick={apply}>{t('saber.data.apply')}</Button>
            </div>
          </div>
        </div>
      )}
    </Modal>
  )
}

type ProgramRow = { sheet: string; row: number; name: string; type: string | null; method: string | null; audience: string | null; hours: number | null; days: number | null; objective: string | null; groups: number; kit: string | null; date: string | null; notes: string | null; action: 'create' | 'update'; program_id: string | null }

/** D2 — «استيراد قائمة البرامج»: an Excel file or a OneDrive link → checked rows and notes → create or update. */
export function ProgramImportDialog({ onClose, onDone }: { onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation()
  const [link, setLink] = useState('')
  const [plan, setPlan] = useState<{ rows: ProgramRow[]; issues: { sheet: string; row: number; level: string; message: string }[] } | null>(null)
  const [busy, setBusy] = useState(false)

  const preview = async (body: FormData | { url: string }) => {
    setBusy(true)
    try { setPlan((await api.post('/admin/saber/data/programs/preview', body)).data.data) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const apply = async () => {
    if (!plan) return
    setBusy(true)
    try {
      const r = (await api.post('/admin/saber/data/programs/apply', { rows: plan.rows })).data.data
      toast(t('saber.data.programs.result', { created: r.created, updated: r.updated, groups: r.groups_added })); onDone(); onClose()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }

  return (
    <Modal open onClose={onClose} title={<span className="flex items-center gap-2"><Upload className="size-5 text-gold-600" />{t('saber.data.programs.title')}</span>} wide>
      <p className="-mt-2 mb-4 text-sm text-slate-500">{t('saber.data.programs.desc')}</p>
      {!plan ? (
        <div className="space-y-3">
          <FilePick busy={busy} label={t('saber.data.file')} onFile={(f) => { const b = new FormData(); b.append('file', f); preview(b) }} />
          <div className="flex items-center gap-2">
            <label className="flex h-10 flex-1 items-center gap-2 rounded-xl border border-navy-100 px-3 focus-within:border-gold-400">
              <Link2 className="size-4 text-slate-400" /><input dir="ltr" value={link} onChange={(e) => setLink(e.target.value)} placeholder={t('saber.data.programs.link')} className="w-full bg-transparent text-sm outline-none" />
            </label>
            <Button variant="outline" disabled={!link.trim().startsWith('https://') || busy} onClick={() => preview({ url: link.trim() })}>{t('saber.data.programs.fetch')}</Button>
          </div>
        </div>
      ) : (
        <div className="space-y-3">
          <div className="flex flex-wrap gap-2 text-xs font-bold">
            <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-emerald-700">{t('saber.data.programs.rows')}: {plan.rows.length}</span>
            <span className="rounded-full bg-sky-50 px-2.5 py-1 text-sky-700">{t('saber.data.programs.action.create')}: {plan.rows.filter((r) => r.action === 'create').length}</span>
            <span className="rounded-full bg-slate-100 px-2.5 py-1 text-slate-600">{t('saber.data.programs.action.update')}: {plan.rows.filter((r) => r.action === 'update').length}</span>
          </div>
          <div className="max-h-80 overflow-auto rounded-xl border border-navy-100">
            <table className="w-full min-w-[640px] text-xs">
              <thead className="sticky top-0 bg-ivory text-slate-500"><tr>{(['name', 'method', 'hours', 'days', 'groups', 'kit', 'date'] as const).map((k) => <th key={k} className="px-2 py-2 text-start font-bold">{t(`saber.data.programs.cols.${k}`)}</th>)}<th /></tr></thead>
              <tbody className="divide-y divide-navy-50">
                {plan.rows.map((r) => (
                  <tr key={`${r.sheet}-${r.row}`}>
                    <td className="max-w-56 truncate px-2 py-1.5 font-semibold text-navy-900">{r.name}</td><td className="px-2">{r.method ?? '—'}</td><td className="px-2 tabular-nums">{r.hours ?? '—'}</td>
                    <td className="px-2 tabular-nums">{r.days ?? '—'}</td><td className="px-2 tabular-nums">{r.groups}</td><td className="px-2">{r.kit ?? '—'}</td><td className="px-2 tabular-nums">{r.date ?? '—'}</td>
                    <td className="px-2"><span className={clsx('rounded-full px-2 py-0.5 text-[10px] font-bold', r.action === 'create' ? 'bg-sky-50 text-sky-700' : 'bg-slate-100 text-slate-600')}>{t(`saber.data.programs.action.${r.action}`)}</span></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          {plan.issues.length > 0 && (
            <details className="rounded-xl border border-amber-200 bg-amber-50/50 p-3 text-xs">
              <summary className="cursor-pointer font-bold text-amber-800">{t('saber.data.programs.issues', { n: plan.issues.length })}</summary>
              <ul className="mt-2 space-y-0.5 text-amber-900">{plan.issues.slice(0, 80).map((i, k) => <li key={k}>{i.sheet}{i.row ? ` · ${i.row}` : ''}: {i.message}</li>)}</ul>
            </details>
          )}
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setPlan(null)}>{t('saber.data.back')}</Button>
            <Button variant="gold" loading={busy} onClick={apply}>{t('saber.data.apply')}</Button>
          </div>
        </div>
      )}
    </Modal>
  )
}
