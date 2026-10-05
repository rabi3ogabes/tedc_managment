import { Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import SchoolPicker, { type SchoolRow } from '@/components/admin/SchoolPicker'
import { Badge, Button, Card, Empty, Field, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import type { Program } from '@/lib/types'
import type { Group } from './GroupsTab'

type Alloc = { entity_type: string; entity_id: string; seats: number; release_at: string; label?: string }
type Seats = { capacity: number; pools: { entity_type: string; entity_id: string | null; seats: number; taken: number; release_at: string | null; released: boolean }[]; open: { seats: number; taken: number }; allocations: { entity_type: string; entity_id: string | null; seats: number; release_at: string | null }[] }
type Cand = { registration_id: string; status: string; employee: string; school: string | null; priority_score: number | null; priority_explanation: { ar: string; en: string; points: number }[] | null; history: { completed_12_months: number; hours_this_year: number; annual_min_hours: number; same_category: number; attendance_avg: number }; warnings: string[] }

/** Program → Admission: seats per entity, ranked applicants with their prior benefit, bulk acceptance, and equivalent programs. */
export default function AdmissionTab({ program }: { program: Program }) {
  const { can } = useAuth()
  const groups = useGet<{ data: Group[] }>(`/admin/programs/${program.id}/groups`, undefined, { staleTime: 0 })
  const [groupId, setGroupId] = useState<string | null>(null)
  if (groups.isLoading || !groups.data) return <Spinner />
  const id = groupId ?? groups.data.data[0]?.id ?? null
  return (
    <div className="space-y-6">
      {groups.data.data.length > 1 && <select className="input max-w-sm" aria-label="group" value={id ?? ''} onChange={(e) => setGroupId(e.target.value)}>{groups.data.data.map((g) => <option key={g.id} value={g.id}>{g.code} — {g.title}</option>)}</select>}
      {id && <GroupAdmission key={id} groupId={id} manage={can('seats.manage') || can('groups.manage')} />}
      <Equivalences program={program} manage={can('programs.manage')} />
    </div>
  )
}

function GroupAdmission({ groupId, manage }: { groupId: string; manage: boolean }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const seats = useGet<{ data: Seats }>(`/admin/groups/${groupId}/seats`, undefined, { staleTime: 0 })
  const cands = useGet<{ data: Cand[]; seats_available: number }>(`/admin/groups/${groupId}/candidates`, undefined, { staleTime: 0 })
  const [draft, setDraft] = useState<Alloc[] | null>(null)
  const [sel, setSel] = useState<string[]>([])
  const [reason, setReason] = useState('')

  if (seats.isLoading || !seats.data) return <Spinner />
  const s = seats.data.data
  const rows: Alloc[] = draft ?? s.allocations.map((a) => ({ entity_type: a.entity_type, entity_id: a.entity_id ?? '', seats: a.seats, release_at: a.release_at?.slice(0, 16) ?? '' }))
  const allocated = rows.reduce((n, r) => n + r.seats, 0)
  const patch = (i: number, p: Partial<Alloc>) => setDraft(rows.map((r, j) => (j === i ? { ...r, ...p } : r)))
  const save = async () => {
    try { await api.put(`/admin/groups/${groupId}/seats`, { allocations: rows.map((r) => ({ entity_type: r.entity_type, entity_id: r.entity_id, seats: r.seats, release_at: r.release_at || null })) }); toast(t('admission.seats.saved')); setDraft(null); await seats.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  const accept = async () => {
    try { const { data } = await api.post(`/admin/groups/${groupId}/accept`, { ids: sel, override_reason: reason || undefined }); toast(t('admission.candidates.accepted', { n: data.data.approved, m: data.data.skipped.length })); setSel([]); await Promise.all([cands.refetch(), seats.refetch()]) } catch (e) { toast(errorMessage(e), 'error') }
  }

  return (
    <>
      <Card className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-3"><h3 className="font-bold text-navy-900">{t('admission.seats.title')}</h3><span className="text-sm text-slate-500">{t('admission.seats.capacity')}: {s.capacity} · {t('admission.seats.open')}: {Math.max(0, s.capacity - allocated)} ({s.open.taken} {t('admission.seats.taken')})</span></div>
        {rows.map((r, i) => {
          const pool = s.pools.find((p) => p.entity_type === r.entity_type && p.entity_id === r.entity_id)
          return (
            <div key={i} className="grid gap-2 sm:grid-cols-[9rem_1fr_7rem_12rem_auto] sm:items-end">
              <Field label={t('admission.seats.type')}><select className="input" disabled={!manage} value={r.entity_type} onChange={(e) => patch(i, { entity_type: e.target.value, entity_id: '' })}>{['school', 'school_group', 'department', 'job_group'].map((x) => <option key={x} value={x}>{t(`admission.seats.types.${x}`)}</option>)}</select></Field>
              <Field label={t('admission.seats.entity')}>{r.entity_type === 'school' ? <SchoolPicker value={r.entity_id ? [r.entity_id] : []} selectedRows={[] as SchoolRow[]} onChange={(ids) => patch(i, { entity_id: ids[0] ?? '' })} /> : <input className="input font-mono text-xs" dir="ltr" disabled={!manage} value={r.entity_id} onChange={(e) => patch(i, { entity_id: e.target.value })} />}</Field>
              <Field label={`${t('admission.seats.count')}${pool ? ` (${pool.taken})` : ''}`}><input type="number" min={0} className="input" disabled={!manage} value={r.seats} onChange={(e) => patch(i, { seats: Number(e.target.value) })} /></Field>
              <Field label={t('admission.seats.release')}><input type="datetime-local" className="input" disabled={!manage || pool?.released} value={r.release_at} onChange={(e) => patch(i, { release_at: e.target.value })} /></Field>
              {manage && <button type="button" aria-label="remove" className="mb-2 text-slate-400 hover:text-danger" onClick={() => setDraft(rows.filter((_, j) => j !== i))}><Trash2 className="size-4" /></button>}
            </div>
          )
        })}
        {manage && <div className="flex gap-2"><Button variant="outline" size="sm" icon={<Plus className="size-4" />} onClick={() => setDraft([...rows, { entity_type: 'school', entity_id: '', seats: 1, release_at: '' }])}>{t('admission.seats.add')}</Button>{draft && <Button variant="gold" size="sm" disabled={rows.some((r) => !r.entity_id)} onClick={() => void save()}>{t('admission.seats.save')}</Button>}</div>}
      </Card>

      <Card padded={false}>
        <div className="flex items-center justify-between border-b border-navy-50 px-5 py-3"><h3 className="font-bold text-navy-900">{t('admission.candidates.title')}</h3><span className="text-xs text-slate-500">{cands.data?.seats_available ?? 0} {t('groups.seats')}</span></div>
        {!cands.data || cands.data.data.length === 0 ? <Empty text={t('admission.candidates.none')} /> : (
          <div className="overflow-x-auto"><table className="w-full text-sm"><thead className="bg-ivory text-xs text-slate-500"><tr><th className="w-8" />{['', t('admission.priority'), t('admission.candidates.completed'), t('admission.candidates.hours'), t('admission.candidates.sameCat'), t('admission.candidates.attendance')].map((h, i) => <th key={i} className="px-3 py-3 text-start font-bold">{h}</th>)}</tr></thead>
            <tbody>{cands.data.data.map((c) => (
              <tr key={c.registration_id} className="border-t border-navy-50 align-top">
                <td className="px-3 py-3"><input type="checkbox" aria-label={c.employee} checked={sel.includes(c.registration_id)} onChange={(e) => setSel(e.target.checked ? [...sel, c.registration_id] : sel.filter((x) => x !== c.registration_id))} /></td>
                <td className="px-3 py-3"><div className="font-bold text-navy-900">{c.employee}</div><div className="text-xs text-slate-500">{c.school}</div>{c.warnings.includes('repeat') && <Badge color="gold">{t('admission.candidates.repeat')}</Badge>}</td>
                <td className="px-3 py-3"><div className="font-extrabold tabular-nums text-navy-900" title={(c.priority_explanation ?? []).map((x) => ar ? x.ar : x.en).join('\n')}>{fmt.number(c.priority_score, 1)}</div><div className="text-[11px] text-slate-400">{c.status}</div></td>
                <td className="px-3 py-3 tabular-nums">{c.history.completed_12_months}</td><td className="px-3 py-3 tabular-nums">{fmt.number(c.history.hours_this_year, 1)} / {fmt.number(c.history.annual_min_hours)}</td><td className="px-3 py-3 tabular-nums">{c.history.same_category}</td><td className="px-3 py-3 tabular-nums">{fmt.percent(c.history.attendance_avg, 0)}</td>
              </tr>))}</tbody></table></div>
        )}
        {sel.length > 0 && manage && <div className="flex flex-wrap items-center gap-3 border-t border-navy-50 p-4"><input className="input min-w-0 flex-1" placeholder={t('admission.overrideReason')} value={reason} onChange={(e) => setReason(e.target.value)} /><Button variant="gold" onClick={() => void accept()}>{t('admission.candidates.acceptSelected')} ({sel.length})</Button></div>}
      </Card>
    </>
  )
}

function Equivalences({ program, manage }: { program: Program; manage: boolean }) {
  const { t } = useTranslation()
  const data = useGet<{ data: { repeat_policy: string; equivalents: { program_id: string; code: string; title: string; bidirectional: boolean }[] } }>(`/admin/programs/${program.id}/equivalences`, undefined, { staleTime: 0 })
  const [code, setCode] = useState('')
  if (data.isLoading || !data.data) return <Spinner />
  const d = data.data.data
  const save = async (policy: string, list: { program_id: string; bidirectional: boolean }[]) => { try { await api.put(`/admin/programs/${program.id}/equivalences`, { repeat_policy: policy, equivalents: list }); toast(t('admission.withdraw.saved')); await data.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const add = async () => {
    try {
      const { data: found } = await api.get('/admin/programs', { params: { q: code, per_page: 1 } })
      const p = found.data?.[0]
      if (!p) { toast(t('admission.candidates.none'), 'error'); return }
      await save(d.repeat_policy, [...d.equivalents.map((e) => ({ program_id: e.program_id, bidirectional: e.bidirectional })), { program_id: p.id, bidirectional: true }]); setCode('')
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <Card className="space-y-4">
      <h3 className="font-bold text-navy-900">{t('admission.equiv.title')}</h3>
      <Field label={t('admission.equiv.policy')}><select className="input max-w-xs" disabled={!manage} value={d.repeat_policy} onChange={(e) => void save(e.target.value, d.equivalents.map((x) => ({ program_id: x.program_id, bidirectional: x.bidirectional })))}>{['block', 'warn', 'allow'].map((p) => <option key={p} value={p}>{t(`admission.equiv.policies.${p}`)}</option>)}</select></Field>
      <ul className="space-y-1.5">{d.equivalents.map((e) => <li key={e.program_id} className="flex items-center gap-3 rounded-xl bg-ivory px-3 py-2 text-sm"><span className="font-mono text-xs text-slate-400" dir="ltr">{e.code}</span><span className="flex-1 font-semibold text-navy-900">{e.title}</span>{manage && <button type="button" aria-label="remove" className="text-slate-400 hover:text-danger" onClick={() => void save(d.repeat_policy, d.equivalents.filter((x) => x.program_id !== e.program_id).map((x) => ({ program_id: x.program_id, bidirectional: x.bidirectional })))}><Trash2 className="size-4" /></button>}</li>)}</ul>
      {manage && <div className="flex gap-2"><input className="input max-w-xs" placeholder={t('admission.equiv.code')} value={code} onChange={(e) => setCode(e.target.value)} /><Button variant="outline" disabled={!code.trim()} onClick={() => void add()}>{t('admission.equiv.add')}</Button></div>}
    </Card>
  )
}
