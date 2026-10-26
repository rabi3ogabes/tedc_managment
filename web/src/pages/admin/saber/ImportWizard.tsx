import clsx from 'clsx'
import { AlertTriangle, BadgeCheck, CalendarRange, Check, FileSpreadsheet, Link2, ListOrdered, Rows3, Save, Sparkles, TableProperties } from 'lucide-react'
import { useMemo, useRef, useState, type ComponentType } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Modal } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { toast } from '@/lib/toast'

type Sheet = { name: string; rows: string[][]; columns: number; count: number; header_row: number }
type Kind = 'grid' | 'rows' | 'programs'
type Step = 'file' | 'layout' | 'map' | 'preview'
type Track = { key: string; label: string; program_id: string | null; proposal?: string | null; program: string; score: number; group_name: string; supervisor: string | null; days: Record<string, unknown>[]; group_id: string | null; target: string | null; verdict: 'match' | 'diff' | 'new' }
type Proposal = { key: string; name: string; labels: string[]; cells: number; groups: number; days: number; first: string | null; closest: string | null; closest_score: number }
type Period = { label: string; from: string; to: string; kind: 'period' | 'event'; type: 'vacation' | 'exam' | 'normal'; booked: number; exists: boolean }
type Plan = { type: Kind; cells?: number; tracks?: Track[]; unmatched?: { label: string; count: number; best: string | null }[]; rooms?: { name: string; exists: boolean; capacity?: number | null; tag?: string | null }[];
  proposals?: Proposal[]; periods?: Period[]; reservations?: { label: string; count: number; rooms: string[] }[]; supervisors?: { name: string; user: string | null }[]; trainers?: { name: string; match: string | null }[]
  rows?: { sheet: string; row: number; name: string; method: string | null; hours: number | null; days: number | null; groups: number; kit: string | null; date: string | null; action: 'create' | 'update' }[]; issues?: { sheet: string; row: number; message: string }[] }

const FIELDS: Record<'rows' | 'programs', { key: string; required?: boolean; names: string[] }[]> = {
  rows: [
    { key: 'program', required: true, names: ['اسم البرنامج', 'البرنامج', 'اسم البرنامج التدريبي', 'program'] }, { key: 'date', required: true, names: ['التاريخ', 'اليوم', 'تاريخ اليوم', 'date'] },
    { key: 'group', names: ['المجموعة', 'رقم المجموعة', 'اسم المجموعة', 'group'] }, { key: 'start', names: ['من', 'يبدأ', 'وقت البداية', 'البداية', 'start'] },
    { key: 'end', names: ['إلى', 'الى', 'ينتهي', 'وقت النهاية', 'النهاية', 'end'] }, { key: 'room', names: ['القاعة', 'المكان', 'room'] },
    { key: 'trainer', names: ['المدرب', 'اسم المدرب', 'trainer'] }, { key: 'supervisor', names: ['المشرف', 'supervisor'] },
  ],
  programs: [
    { key: 'name', required: true, names: ['اسم البرنامج', 'البرنامج', 'program'] }, { key: 'type', names: ['نوع البرنامج', 'النوع'] }, { key: 'method', names: ['آلية التدريب', 'الآلية', 'طريقة التنفيذ'] },
    { key: 'audience', names: ['الفئة المستهدفة', 'الفئة'] }, { key: 'hours', names: ['عدد الساعات', 'الساعات'] }, { key: 'days', names: ['عدد الأيام', 'الأيام'] },
    { key: 'objective', names: ['الهدف العام', 'الهدف'] }, { key: 'groups', names: ['الكتل التدريبية', 'عدد المجموعات', 'المجموعات'] }, { key: 'kit', names: ['توفر الحقيبة', 'الحقيبة'] },
    { key: 'date', names: ['موعد تنفيذ', 'موعد التنفيذ', 'التنفيذ', 'التاريخ'] }, { key: 'notes', names: ['ملاحظات', 'الملاحظات'] },
  ],
}
const norm = (s: string) => s.replace(/[ً-ْـ]/g, '').replace(/[أإآٱ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه').replace(/\s+/g, ' ').trim().toLowerCase()

/** Columns guessed from the header texts: an exact name first, then a header that contains one. */
function suggest(kind: 'rows' | 'programs', headers: string[]): Record<string, number | null> {
  const used = new Set<number>()
  const out: Record<string, number | null> = {}
  for (const f of FIELDS[kind]) {
    const names = f.names.map(norm)
    let at = headers.findIndex((h, i) => !used.has(i) && names.includes(norm(h)))
    if (at < 0) at = headers.findIndex((h, i) => !used.has(i) && norm(h) !== '' && names.some((n) => norm(h).includes(n)))
    out[f.key] = at >= 0 ? at + 1 : null
    if (at >= 0) used.add(at)
  }
  return out
}

/**
 * «استيراد ملف Excel» (docs/saber/05): any workbook — the administrator picks the sheet, the kind (rooms calendar, one day per row, programme
 * list), the header row and which column is what; previews what would change; then saves it into the programmes' SABER+ drafts and, if
 * wanted, sends them for review. Layouts can be saved and reused.
 */
export default function ImportWizard({ onClose, onDone, can = {} }: { onClose: () => void; onDone: () => void; can?: Record<string, boolean> }) {
  const { t } = useTranslation()
  const [step, setStep] = useState<Step>('file')
  const [file, setFile] = useState<File | null>(null)
  const [link, setLink] = useState('')
  const [sheets, setSheets] = useState<Sheet[]>([])
  const [sheetName, setSheetName] = useState('')
  const [kind, setKind] = useState<Kind>('rows')
  const [headerRow, setHeaderRow] = useState(1)
  const [dateCol, setDateCol] = useState(4)
  const [firstRoom, setFirstRoom] = useState(8)
  const [columns, setColumns] = useState<Record<string, number | null>>({})
  const [plan, setPlan] = useState<Plan | null>(null)
  const [chosen, setChosen] = useState<Set<string>>(new Set())
  const [create, setCreate] = useState<Set<string>>(new Set())
  const [periods, setPeriods] = useState<Record<string, Period['type'] | null>>({})
  const canCreate = !!can['saber.program.create']
  const canCalendar = !!can['calendar.manage']
  const [submit, setSubmit] = useState(true)
  const [busy, setBusy] = useState(false)
  const presets = useGet<{ data: Record<string, { type: Kind; header_row?: number; date_col?: number; first_room_col?: number; columns?: Record<string, number | null> }> }>('/admin/saber/data/import/presets', undefined, { staleTime: 60_000 })
  const input = useRef<HTMLInputElement>(null)
  const sheet = sheets.find((s) => s.name === sheetName) ?? sheets[0]
  const headers = useMemo(() => sheet?.rows[headerRow - 1] ?? [], [sheet, headerRow])
  const colCount = Math.max(sheet?.columns ?? 0, headers.length)

  const body = (extra: Record<string, unknown> = {}) => {
    const b = new FormData()
    if (file) b.append('file', file); else b.append('url', link.trim())
    Object.entries(extra).forEach(([k, v]) => v !== undefined && v !== null && b.append(k, typeof v === 'object' ? JSON.stringify(v) : String(v)))
    return b
  }
  const inspect = async (f?: File) => {
    if (f) setFile(f)
    setBusy(true)
    try {
      const b = new FormData(); if (f) b.append('file', f); else b.append('url', link.trim())
      const res: Sheet[] = (await api.post('/admin/saber/data/import/inspect', b)).data.data.sheets
      setSheets(res)
      const first = res.find((s) => s.name.includes('تقويم')) ?? res[0]
      setSheetName(first?.name ?? ''); setHeaderRow(first?.header_row ?? 1)
      const hdr = (first?.rows[(first?.header_row ?? 1) - 1] ?? []).map(norm)
      setKind(hdr.some((h) => h.includes(norm('عدد الساعات'))) && !hdr.some((h) => h === norm('التاريخ')) ? 'programs' : hdr.filter(Boolean).length > 8 && first?.name.includes('تقويم') ? 'grid' : 'rows')
      setStep('layout')
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const toMapping = () => { if (kind !== 'grid') setColumns(suggest(kind, headers)); setStep('map') }
  const preview = async () => {
    setBusy(true)
    try {
      const p: Plan = (await api.post('/admin/saber/data/import/preview', body({ type: kind, sheet: sheet?.name, header_row: headerRow, ...(kind === 'grid' ? { date_col: dateCol, first_room_col: firstRoom } : { columns }) }))).data.data
      setPlan(p); setChosen(new Set((p.tracks ?? []).filter((x) => x.verdict !== 'match').map((x) => x.key)))
      // Every proposed programme is ticked (it only becomes a draft); calendar periods not yet in the calendar are ticked when the person may add them.
      setCreate(new Set(canCreate ? (p.proposals ?? []).map((x) => x.key) : []))
      setPeriods(Object.fromEntries((p.periods ?? []).map((x) => [x.label + x.from, canCalendar && !x.exists ? x.type : null])))
      setStep('preview')
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const apply = async () => {
    if (!plan) return
    setBusy(true)
    try {
      const live = (x: Track) => chosen.has(x.key) && (x.program_id || (x.proposal && create.has(x.proposal)))
      const payload = kind === 'programs' ? { type: kind, rows: plan.rows, submit } : {
        type: kind, submit,
        tracks: (plan.tracks ?? []).filter(live).map((x) => ({ program_id: x.program_id, proposal: x.program_id ? null : x.proposal, group_id: x.group_id, group_name: x.group_name, supervisor: x.supervisor, days: x.days })),
        create: (plan.proposals ?? []).filter((x) => create.has(x.key)).map((x) => ({ key: x.key, name: x.name, days: x.days })),
        periods: (plan.periods ?? []).filter((x) => periods[x.label + x.from]).map((x) => ({ label: x.label, from: x.from, to: x.to, type: periods[x.label + x.from] })),
        rooms: (plan.rooms ?? []).filter((x) => !x.exists).map((x) => ({ name: x.name, capacity: x.capacity ?? null, tag: x.tag ?? null })),
      }
      const r = (await api.post('/admin/saber/data/import/apply', payload)).data.data
      const sent = submit ? t('saber.wizard.sent') : ''
      toast(kind === 'programs' ? t('saber.wizard.programsApplied', { created: r.created, updated: r.updated, groups: r.groups_added, sent }) : t('saber.wizard.applied', { drafts: r.drafts, sent }))
      if (r.programs_created || r.calendar_days) toast(t('saber.wizard.extras', { programs: r.programs_created ?? 0, days: r.calendar_days ?? 0 }), 'info')
      if (r.failures?.length) toast(`${r.failures.length} × ${r.failures[0].message}`, 'info')
      onDone(); onClose()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const savePreset = async () => {
    const name = await dialogs.prompt(t('saber.wizard.presetName'))
    if (!name) return
    try { await api.post('/admin/saber/data/import/presets', { name, layout: { type: kind, header_row: headerRow, date_col: dateCol, first_room_col: firstRoom, columns } }); presets.refetch(); toast(t('saber.wizard.presetSaved')) } catch (e) { toast(errorMessage(e), 'error') }
  }
  const applyPreset = (name: string) => {
    const p = presets.data?.data[name]
    if (!p) return
    setKind(p.type); if (p.header_row) setHeaderRow(p.header_row); if (p.date_col) setDateCol(p.date_col); if (p.first_room_col) setFirstRoom(p.first_room_col); if (p.columns) setColumns(p.columns)
    setStep('map')
  }
  const colOptions = (selected: number | null) => (
    <>
      <option value="">{t('saber.wizard.none')}</option>
      {Array.from({ length: colCount }, (_, i) => <option key={i} value={i + 1}>{headers[i] ? `${headers[i]}` : t('saber.wizard.column', { n: i + 1 })}{selected === i + 1 ? ' ✓' : ''}</option>)}
    </>
  )
  const sampleOf = (col: number | null) => (col ? sheet?.rows.slice(headerRow).map((r) => r[col - 1]).find((v) => v && v.trim()) : null)
  const missingRequired = kind !== 'grid' && FIELDS[kind].some((f) => f.required && !columns[f.key])
  const steps: Step[] = ['file', 'layout', 'map', 'preview']
  const kinds: [Kind, ComponentType<{ className?: string }>][] = [['grid', TableProperties], ['rows', Rows3], ['programs', ListOrdered]]

  return (
    <Modal open onClose={onClose} title={<span className="flex items-center gap-2"><FileSpreadsheet className="size-5 text-gold-600" />{t('saber.wizard.title')}</span>} wide>
      <ol className="mb-5 flex items-center gap-2 text-xs font-bold">
        {steps.map((s, i) => {
          const done = steps.indexOf(step) > i
          return (
            <li key={s} className="flex flex-1 items-center gap-2">
              <span className={clsx('grid size-6 shrink-0 place-items-center rounded-full', step === s ? 'bg-navy-900 text-white' : done ? 'bg-emerald-500 text-white' : 'bg-slate-100 text-slate-400')}>{done ? <Check className="size-3.5" /> : i + 1}</span>
              <span className={clsx('truncate', step === s ? 'text-navy-900' : 'text-slate-400')}>{t(`saber.wizard.steps.${s}`)}</span>
              {i < steps.length - 1 && <span className="h-px flex-1 bg-navy-100" />}
            </li>
          )
        })}
      </ol>

      {step === 'file' && (
        <div className="space-y-3">
          <button type="button" disabled={busy} onClick={() => input.current?.click()} onDragOver={(e) => e.preventDefault()} onDrop={(e) => { e.preventDefault(); const f = e.dataTransfer.files[0]; if (f) inspect(f) }}
            className="flex w-full flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-navy-100 bg-ivory/50 px-6 py-10 text-center transition hover:border-gold-300">
            <span className="grid size-12 place-items-center rounded-2xl bg-white text-gold-600 shadow-sm">{busy ? <span className="size-5 animate-spin rounded-full border-2 border-gold-500 border-t-transparent" /> : <FileSpreadsheet className="size-6" />}</span>
            <span className="text-sm font-bold text-navy-900">{t('saber.wizard.file')}</span><span className="text-xs text-slate-400">.xlsx · .xls · .csv</span>
          </button>
          <input ref={input} type="file" accept=".xlsx,.xls,.csv" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) inspect(f); e.target.value = '' }} />
          <div className="flex items-center gap-2">
            <label className="flex h-10 flex-1 items-center gap-2 rounded-xl border border-navy-100 px-3 focus-within:border-gold-400"><Link2 className="size-4 text-slate-400" /><input dir="ltr" value={link} onChange={(e) => setLink(e.target.value)} placeholder={t('saber.wizard.link')} className="w-full bg-transparent text-sm outline-none" /></label>
            <Button variant="outline" disabled={!link.trim().startsWith('https://') || busy} onClick={() => { setFile(null); inspect() }}>{t('saber.wizard.fetch')}</Button>
          </div>
        </div>
      )}

      {step === 'layout' && sheet && (
        <div className="space-y-4">
          <div className="flex flex-wrap items-end gap-3">
            <label className="text-xs font-bold text-slate-500">{t('saber.wizard.sheet')}
              <select value={sheet.name} onChange={(e) => { const s = sheets.find((x) => x.name === e.target.value); setSheetName(e.target.value); setHeaderRow(s?.header_row ?? 1) }} className="mt-1 block h-10 rounded-xl border border-navy-100 bg-white px-2 text-sm font-semibold text-navy-900">
                {sheets.map((s) => <option key={s.name} value={s.name}>{s.name} · {t('saber.wizard.rowsN', { n: s.count })}</option>)}
              </select>
            </label>
            {!!Object.keys(presets.data?.data ?? {}).length && (
              <label className="text-xs font-bold text-slate-500">{t('saber.wizard.usePreset')}
                <select defaultValue="" onChange={(e) => e.target.value && applyPreset(e.target.value)} className="mt-1 block h-10 rounded-xl border border-navy-100 bg-white px-2 text-sm"><option value="">—</option>{Object.keys(presets.data?.data ?? {}).map((n) => <option key={n} value={n}>{n}</option>)}</select>
              </label>
            )}
          </div>
          <div className="grid gap-2 sm:grid-cols-3">
            {kinds.map(([k, Icon]) => (
              <button key={k} type="button" aria-pressed={kind === k} onClick={() => setKind(k)} className={clsx('rounded-xl border p-3 text-start transition', kind === k ? 'border-gold-400 bg-gold-50/60 ring-2 ring-gold-200' : 'border-navy-100 hover:border-gold-300')}>
                <span className="flex items-center gap-2 text-sm font-bold text-navy-900"><Icon className="size-4 text-gold-600" />{t(`saber.wizard.types.${k}`)}</span>
                <span className="mt-1 block text-[11px] leading-relaxed text-slate-500">{t(`saber.wizard.typesHint.${k}`)}</span>
              </button>
            ))}
          </div>
          <div>
            <p className="mb-1.5 text-xs font-bold text-slate-500">{t('saber.wizard.header')} · <span className="font-semibold text-slate-400">{t('saber.wizard.headerHint')}</span></p>
            <div className="max-h-64 overflow-auto rounded-xl border border-navy-100">
              <table className="w-full text-[11px]">
                <tbody>
                  {sheet.rows.slice(0, 15).map((r, i) => (
                    <tr key={i} onClick={() => setHeaderRow(i + 1)} className={clsx('cursor-pointer border-b border-navy-50', headerRow === i + 1 ? 'bg-gold-100/70 font-bold text-navy-900' : 'hover:bg-ivory')}>
                      <td className="w-8 bg-ivory/70 px-2 py-1 text-center tabular-nums text-slate-400">{i + 1}</td>
                      {Array.from({ length: Math.min(colCount, 14) }, (_, c) => <td key={c} className="max-w-36 truncate px-2 py-1">{r[c]}</td>)}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </div>
          <div className="flex justify-between"><Button variant="ghost" onClick={() => setStep('file')}>{t('saber.wizard.back')}</Button><Button onClick={toMapping}>{t('saber.wizard.next')}</Button></div>
        </div>
      )}

      {step === 'map' && sheet && (
        <div className="space-y-4">
          {kind === 'grid' ? (
            <div className="grid gap-3 sm:grid-cols-3">
              <label className="text-xs font-bold text-slate-500">{t('saber.wizard.header')}<input type="number" min={1} value={headerRow} onChange={(e) => setHeaderRow(Number(e.target.value) || 1)} className="mt-1 block h-10 w-full rounded-xl border border-navy-100 px-3 text-sm" /></label>
              <label className="text-xs font-bold text-slate-500">{t('saber.wizard.dateCol')}<select value={dateCol} onChange={(e) => setDateCol(Number(e.target.value))} className="mt-1 block h-10 w-full rounded-xl border border-navy-100 bg-white px-2 text-sm">{colOptions(dateCol)}</select></label>
              <label className="text-xs font-bold text-slate-500">{t('saber.wizard.firstRoom')}<select value={firstRoom} onChange={(e) => setFirstRoom(Number(e.target.value))} className="mt-1 block h-10 w-full rounded-xl border border-navy-100 bg-white px-2 text-sm">{colOptions(firstRoom)}</select></label>
            </div>
          ) : (
            <div className="grid gap-2 sm:grid-cols-2">
              {FIELDS[kind].map((f) => (
                <div key={f.key} className={clsx('rounded-xl border p-2.5', f.required && !columns[f.key] ? 'border-rose-200 bg-rose-50/40' : columns[f.key] ? 'border-emerald-100 bg-emerald-50/30' : 'border-navy-100')}>
                  <div className="mb-1 flex items-center justify-between text-xs font-bold text-navy-900">{t(`saber.wizard.fields.${f.key}`)}{f.required && <span className="text-[10px] text-rose-600">{t('saber.wizard.required')}</span>}</div>
                  <select value={columns[f.key] ?? ''} onChange={(e) => setColumns((c) => ({ ...c, [f.key]: e.target.value ? Number(e.target.value) : null }))} className="h-9 w-full rounded-lg border border-navy-100 bg-white px-2 text-sm">{colOptions(columns[f.key] ?? null)}</select>
                  {sampleOf(columns[f.key] ?? null) && <div className="mt-1 truncate text-[10px] text-slate-400">{t('saber.wizard.sample', { v: sampleOf(columns[f.key] ?? null) })}</div>}
                </div>
              ))}
            </div>
          )}
          <div className="flex flex-wrap justify-between gap-2">
            <Button variant="ghost" onClick={() => setStep('layout')}>{t('saber.wizard.back')}</Button>
            <span className="flex gap-2">
              <Button variant="outline" icon={<Save className="size-4" />} onClick={savePreset}>{t('saber.wizard.savePreset')}</Button>
              <Button loading={busy} disabled={missingRequired} onClick={preview}>{t('saber.wizard.preview')}</Button>
            </span>
          </div>
        </div>
      )}

      {step === 'preview' && plan && (
        <div className="space-y-3">
          {plan.type === 'programs' ? (
            <>
              <div className="max-h-80 overflow-auto rounded-xl border border-navy-100">
                <table className="w-full min-w-[620px] text-xs">
                  <thead className="sticky top-0 bg-ivory text-slate-500"><tr>{['name', 'method', 'hours', 'days', 'groups', 'kit', 'date'].map((k) => <th key={k} className="px-2 py-2 text-start font-bold">{t(`saber.wizard.fields.${k}`)}</th>)}<th /></tr></thead>
                  <tbody className="divide-y divide-navy-50">
                    {(plan.rows ?? []).map((r) => (
                      <tr key={`${r.sheet}-${r.row}`}>
                        <td className="max-w-56 truncate px-2 py-1.5 font-semibold text-navy-900">{r.name}</td><td className="px-2">{r.method ?? '—'}</td><td className="px-2 tabular-nums">{r.hours ?? '—'}</td><td className="px-2 tabular-nums">{r.days ?? '—'}</td>
                        <td className="px-2 tabular-nums">{r.groups}</td><td className="px-2">{r.kit ?? '—'}</td><td className="px-2 tabular-nums">{r.date ?? '—'}</td>
                        <td className="px-2"><span className={clsx('rounded-full px-2 py-0.5 text-[10px] font-bold', r.action === 'create' ? 'bg-sky-50 text-sky-700' : 'bg-slate-100 text-slate-600')}>{t(`saber.data.programs.action.${r.action}`)}</span></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {!!plan.issues?.length && <details className="rounded-xl border border-amber-200 bg-amber-50/50 p-3 text-xs"><summary className="cursor-pointer font-bold text-amber-800">{t('saber.data.programs.issues', { n: plan.issues.length })}</summary><ul className="mt-2 space-y-0.5 text-amber-900">{plan.issues.slice(0, 80).map((i, k) => <li key={k}>{i.sheet}{i.row ? ` · ${i.row}` : ''}: {i.message}</li>)}</ul></details>}
            </>
          ) : (
            <>
              <div className="flex flex-wrap gap-1.5 text-[11px]">
                <span className="rounded-full bg-slate-100 px-2 py-0.5 font-bold text-slate-600"><CalendarRange className="me-1 inline size-3" />{plan.cells}</span>
                <span className="rounded-full bg-emerald-50 px-2 py-0.5 font-bold text-emerald-700">{t('saber.data.calendar.tracks')}: {plan.tracks?.length ?? 0}</span>
                {(plan.rooms ?? []).filter((r) => !r.exists).map((r) => <span key={r.name} className="rounded-full bg-sky-50 px-2 py-0.5 font-semibold text-sky-700">{t('saber.data.calendar.newRoom')}: {r.name}</span>)}
                {(plan.supervisors ?? []).filter((s) => !s.user).map((s) => <span key={s.name} className="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-700">{s.name} — {t('saber.data.calendar.supMissing')}</span>)}
                {(plan.trainers ?? []).filter((x) => !x.match).map((x) => <span key={x.name} className="rounded-full bg-amber-50 px-2 py-0.5 font-semibold text-amber-700">{t('saber.wizard.missingTrainers')}: {x.name}</span>)}
              </div>
              {!!plan.proposals?.length && (
                <details open className="rounded-xl border border-sky-200 bg-sky-50/40 p-3">
                  <summary className="cursor-pointer text-sm font-bold text-sky-900">{t('saber.wizard.proposals', { n: plan.proposals.length })} · <span className="font-semibold text-sky-700">{t('saber.wizard.proposalsSelected', { n: create.size })}</span></summary>
                  <p className="mt-1 text-[11px] text-sky-800">{canCreate ? t('saber.wizard.proposalsHint') : t('saber.wizard.proposalsNoRight')}</p>
                  <div className="mt-2 flex gap-3 text-[11px] font-bold">
                    <button type="button" disabled={!canCreate} className="text-navy-700 hover:text-gold-700 disabled:opacity-40" onClick={() => setCreate(new Set(plan.proposals!.map((x) => x.key)))}>{t('saber.data.calendar.selectAll')}</button>
                    <button type="button" className="text-navy-700 hover:text-gold-700" onClick={() => setCreate(new Set())}>{t('saber.plan.clearAll')}</button>
                  </div>
                  <ul className="mt-2 max-h-56 divide-y divide-sky-100 overflow-y-auto rounded-lg bg-white">
                    {plan.proposals.map((x) => (
                      <li key={x.key}>
                        <label className={clsx('flex items-start gap-2.5 px-3 py-2 text-xs', canCreate ? 'cursor-pointer' : 'opacity-60')}>
                          <input type="checkbox" className="mt-0.5" disabled={!canCreate} checked={create.has(x.key)} onChange={() => setCreate((s0) => { const n = new Set(s0); if (n.has(x.key)) n.delete(x.key); else n.add(x.key); return n })} />
                          <span className="min-w-0 flex-1">
                            <span className="block truncate font-bold text-navy-900">{x.name}</span>
                            <span className="block text-[10px] text-slate-500">{t('saber.wizard.proposalLine', { groups: x.groups, days: x.days, cells: x.cells })}{x.closest && x.closest_score >= 0.6 ? ` · ${t('saber.wizard.closest', { name: x.closest })}` : ''}</span>
                          </span>
                        </label>
                      </li>
                    ))}
                  </ul>
                </details>
              )}
              {!!plan.periods?.length && (
                <details className="rounded-xl border border-gold-200 bg-gold-50/40 p-3">
                  <summary className="cursor-pointer text-sm font-bold text-navy-900">{t('saber.wizard.periods', { n: plan.periods.length })} · <span className="font-semibold text-gold-800">{t('saber.wizard.periodsSelected', { n: Object.values(periods).filter(Boolean).length })}</span></summary>
                  <p className="mt-1 text-[11px] text-slate-600">{canCalendar ? t('saber.wizard.periodsHint') : t('saber.wizard.periodsNoRight')}</p>
                  <ul className="mt-2 space-y-1.5">
                    {plan.periods.map((x) => {
                      const k = x.label + x.from
                      return (
                        <li key={k} className="flex flex-wrap items-center gap-2 rounded-lg bg-white px-3 py-2 text-xs">
                          <input type="checkbox" disabled={!canCalendar} checked={!!periods[k]} onChange={(e) => setPeriods((p0) => ({ ...p0, [k]: e.target.checked ? x.type : null }))} />
                          <span className="min-w-0 flex-1 truncate font-semibold text-navy-900">{x.label}</span>
                          <span className="tabular-nums text-slate-500" dir="ltr">{x.from}{x.to !== x.from ? ` → ${x.to}` : ''}</span>
                          <select disabled={!canCalendar || !periods[k]} value={periods[k] ?? x.type} onChange={(e) => setPeriods((p0) => ({ ...p0, [k]: e.target.value as Period['type'] }))} className="h-7 rounded-md border border-navy-100 bg-white px-1 text-[11px]">
                            {(['vacation', 'exam', 'normal'] as const).map((ty) => <option key={ty} value={ty}>{t(`saber.wizard.periodTypes.${ty}`)}</option>)}
                          </select>
                          {x.exists && <span className="rounded-full bg-slate-100 px-1.5 text-[10px] font-bold text-slate-500">{t('saber.wizard.inCalendar')}</span>}
                          {x.booked > 0 && <span className="rounded-full bg-amber-50 px-1.5 text-[10px] font-bold text-amber-700">{t('saber.wizard.bookedInside', { n: x.booked })}</span>}
                        </li>
                      )
                    })}
                  </ul>
                </details>
              )}
              {!!plan.reservations?.length && (
                <p className="rounded-xl border border-navy-100 bg-white px-3 py-2 text-[11px] text-slate-500">{t('saber.wizard.reservations', { n: plan.reservations.reduce((a, x) => a + x.count, 0), rooms: [...new Set(plan.reservations.flatMap((x) => x.rooms))].join('، ') })}</p>
              )}
              <div className="flex items-center justify-between text-xs font-semibold text-slate-500">
                <span>{t('saber.data.calendar.selected', { n: chosen.size })}</span>
                <button type="button" className="font-bold text-navy-700 hover:text-gold-700" onClick={() => setChosen(new Set((plan.tracks ?? []).map((x) => x.key)))}>{t('saber.data.calendar.selectAll')}</button>
              </div>
              <ul className="max-h-80 divide-y divide-navy-50 overflow-y-auto rounded-xl border border-navy-100">
                {(plan.tracks ?? []).filter((x) => x.program_id || (x.proposal && create.has(x.proposal))).map((x) => (
                  <li key={x.key}>
                    <label className="flex cursor-pointer items-start gap-3 px-3 py-2.5">
                      <input type="checkbox" className="mt-1" checked={chosen.has(x.key)} onChange={() => setChosen((s) => { const n = new Set(s); if (n.has(x.key)) n.delete(x.key); else n.add(x.key); return n })} />
                      <span className="min-w-0 flex-1">
                        <span className="flex flex-wrap items-center gap-1.5">
                          <span className="text-sm font-bold text-navy-900">{x.program}</span><span className="text-xs text-slate-500">· {x.group_name}</span>
                          <span className={clsx('rounded-full px-2 py-0.5 text-[10px] font-bold', x.verdict === 'match' ? 'bg-emerald-50 text-emerald-700' : x.verdict === 'new' ? 'bg-sky-50 text-sky-700' : 'bg-amber-50 text-amber-700')}>{t(`saber.data.calendar.verdict.${x.verdict}`)}</span>
                          <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-600">{t('saber.data.calendar.days', { n: x.days.length })}</span>
                        </span>
                        <span className="mt-0.5 block truncate text-[11px] text-slate-400">{t('saber.data.calendar.from', { label: x.label })}{x.program_id ? ` · ${t('saber.data.calendar.score', { n: Math.round(x.score * 100) })}` : ''}{x.target ? ` · ${t('saber.data.calendar.target', { name: x.target })}` : ''}</span>
                      </span>
                    </label>
                  </li>
                ))}
              </ul>
              {!!plan.unmatched?.length && <details className="rounded-xl border border-navy-100 p-3 text-xs"><summary className="cursor-pointer font-bold text-slate-600"><AlertTriangle className="me-1 inline size-3.5 text-amber-600" />{t('saber.data.calendar.unmatched', { n: plan.unmatched.length })}</summary><ul className="mt-2 space-y-1 text-slate-600">{plan.unmatched.map((u) => <li key={u.label} className="flex justify-between gap-2"><span className="truncate">{u.label}</span><span className="shrink-0 text-slate-400">{u.count}{u.best ? ` · ${t('saber.data.calendar.best', { name: u.best })}` : ''}</span></li>)}</ul></details>}
            </>
          )}
          <div className="flex flex-wrap items-center justify-between gap-3 border-t border-navy-50 pt-3">
            <label className="flex items-center gap-2 text-xs font-semibold text-slate-600"><input type="checkbox" checked={submit} onChange={(e) => setSubmit(e.target.checked)} /><BadgeCheck className="size-3.5 text-gold-600" />{t('saber.wizard.submit')}</label>
            <span className="flex gap-2">
              <Button variant="ghost" onClick={() => setStep('map')}>{t('saber.wizard.back')}</Button>
              <Button variant="gold" icon={<Sparkles className="size-4" />} loading={busy} disabled={plan.type !== 'programs' && chosen.size === 0} onClick={apply}>{t('saber.wizard.apply')}</Button>
            </span>
          </div>
        </div>
      )}
    </Modal>
  )
}
