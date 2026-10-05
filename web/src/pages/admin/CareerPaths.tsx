/* eslint-disable @typescript-eslint/no-explicit-any */
import { Plus, Trash2 } from 'lucide-react'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner, Table, Tabs, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

const input = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'
const FIELDS = ['experience_years', 'experience_moe_years', 'experience_current_title_years', 'grade_level', 'appraisal_avg_rating', 'appraisal_min_rating', 'qualification', 'licence_level', 'path_level', 'pd_hours', 'has_licence', 'completed_program']
const OPS = ['gte', 'gt', 'lte', 'lt', 'eq', 'neq', 'in', 'completed']
type Tab = 'paths' | 'compliance' | 'licences'

/** Career and licence paths with a level designer, the compliance funnel and matrix, and the licence register. */
export default function CareerPaths() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [tab, setTab] = useState<Tab>('paths')
  return (
    <>
      <PageHeader title={t('career.nav')} />
      <Tabs<Tab> value={tab} onChange={setTab} tabs={[{ id: 'paths', label: t('career.tabs.paths') }, { id: 'compliance', label: t('career.tabs.compliance') }, ...(can('licences.manage') ? [{ id: 'licences' as const, label: t('career.tabs.licences') }] : [])]} />
      <div className="mt-4">{tab === 'paths' ? <Paths /> : tab === 'compliance' ? <Compliance /> : <Licences />}</div>
    </>
  )
}

function Paths() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const { can } = useAuth()
  const list = useGet<{ data: any[] }>('/admin/career-paths', undefined, { staleTime: 0 })
  const [edit, setEdit] = useState<any | null>(null)
  const run = async () => { try { const { data } = await api.post('/admin/career-paths/evaluate'); toast(t('career.path.evaluated', { n: data.data.evaluated })) } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <div className="space-y-3">
      {can('paths.manage') && <div className="flex justify-end gap-2"><Button size="sm" variant="outline" onClick={() => void run()}>{t('career.path.evaluate')}</Button><Button size="sm" icon={<Plus className="size-4" />} onClick={() => setEdit({ type: 'licence', title_ar: '', title_en: '', levels: [] })}>{t('career.path.new')}</Button></div>}
      {list.isLoading ? <Spinner /> : !list.data?.data.length ? <Card><Empty text={t('career.path.empty')} /></Card> : list.data.data.map((p) => (
        <Card key={p.id} className="flex flex-wrap items-center gap-3"><b className="text-navy-900">{ar ? p.title_ar : p.title_en}</b><Badge>{t(`career.types.${p.type}`)}</Badge><span className="text-xs text-slate-500">{p.levels.length} {t('career.path.levels')}</span>{can('paths.manage') && <Button className="ms-auto" size="sm" variant="outline" onClick={() => setEdit(p)}>{t('common.edit')}</Button>}</Card>))}
      {edit && <PathEditor path={edit} onClose={(c) => { setEdit(null); if (c) void list.refetch() }} />}
    </div>
  )
}

function PathEditor({ path, onClose }: { path: any; onClose: (changed?: boolean) => void }) {
  const { t } = useTranslation()
  const programs = useGet<{ data: any[] }>('/admin/programs', { per_page: 200 })
  const [d, setD] = useState<any>({ ...path, levels: (path.levels ?? []).map((l: any) => ({ ...l, conditions: l.conditions ?? [], required_programs: l.required_programs ?? [] })) })
  const setL = (i: number, patch: any) => setD({ ...d, levels: d.levels.map((l: any, j: number) => (j === i ? { ...l, ...patch } : l)) })
  const parse = (v: string) => (v === '' ? null : /^-?\d+(\.\d+)?$/.test(v) ? Number(v) : v === 'true' ? true : v === 'false' ? false : v.includes(',') ? v.split(',').map((x) => x.trim()) : v)
  const save = async () => {
    try {
      const body = { type: d.type, title_ar: d.title_ar, title_en: d.title_en }
      const { data } = d.id ? await api.put(`/admin/career-paths/${d.id}`, body) : await api.post('/admin/career-paths', body)
      const id = d.id ?? data.data.id
      if (d.levels.length) await api.put(`/admin/career-paths/${id}/levels`, { levels: d.levels.map((l: any, i: number) => ({ level_no: i + 1, title_ar: l.title_ar, title_en: l.title_en, min_pd_hours: Number(l.min_pd_hours) || 0, validity_months: l.validity_months ? Number(l.validity_months) : null, conditions: l.conditions.map((c: any) => ({ field: c.field, operator: c.operator, value: typeof c.value === 'string' ? parse(c.value) : c.value })), required_programs: l.required_programs.map((g: any) => ({ program_ids: g.program_ids, min: Number(g.min) || g.program_ids.length })) })) })
      toast(t('career.path.saved')); onClose(true)
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <Modal wide open onClose={() => onClose()} title={t('career.path.new')}>
      <div className="grid gap-3 sm:grid-cols-3">
        <Field label={t('career.path.type')}><select className={input} disabled={!!d.id} value={d.type} onChange={(e) => setD({ ...d, type: e.target.value })}>{['licence', 'promotion', 'specialisation'].map((x) => <option key={x} value={x}>{t(`career.types.${x}`)}</option>)}</select></Field>
        <Field label={t('career.path.titleAr')}><input className={input} value={d.title_ar} onChange={(e) => setD({ ...d, title_ar: e.target.value })} /></Field>
        <Field label={t('career.path.titleEn')}><input dir="ltr" className={input} value={d.title_en} onChange={(e) => setD({ ...d, title_en: e.target.value })} /></Field>
      </div>
      <div className="mt-4 space-y-4">{d.levels.map((l: any, i: number) => (
        <div key={i} className="space-y-3 rounded-2xl border border-navy-100 p-3">
          <div className="grid gap-2 sm:grid-cols-[1fr_1fr_8rem_8rem_auto]"><input className={input} placeholder={`${t('career.path.level', { n: i + 1 })} (ع)`} value={l.title_ar} onChange={(e) => setL(i, { title_ar: e.target.value })} /><input dir="ltr" className={input} placeholder="EN" value={l.title_en} onChange={(e) => setL(i, { title_en: e.target.value })} /><input className={input} type="number" placeholder={t('career.path.pdHours')} value={l.min_pd_hours ?? ''} onChange={(e) => setL(i, { min_pd_hours: e.target.value })} />{d.type === 'licence' && <input className={input} type="number" placeholder={t('career.path.validity')} value={l.validity_months ?? ''} onChange={(e) => setL(i, { validity_months: e.target.value })} />}<button type="button" aria-label="remove" className="text-danger" onClick={() => setD({ ...d, levels: d.levels.filter((_: any, j: number) => j !== i) })}><Trash2 className="size-4" /></button></div>
          <div className="space-y-2"><div className="text-xs font-semibold">{t('career.path.conditions')}</div>{l.conditions.map((c: any, k: number) => (
            <div key={k} className="flex gap-2"><select className={input} value={c.field} onChange={(e) => setL(i, { conditions: l.conditions.map((x: any, y: number) => (y === k ? { ...x, field: e.target.value } : x)) })}>{FIELDS.map((f) => <option key={f} value={f}>{f}</option>)}</select><select className={`${input} max-w-24`} value={c.operator} onChange={(e) => setL(i, { conditions: l.conditions.map((x: any, y: number) => (y === k ? { ...x, operator: e.target.value } : x)) })}>{OPS.map((o) => <option key={o} value={o}>{t(`career.ops.${o}`)}</option>)}</select><input className={input} dir="ltr" value={Array.isArray(c.value) ? c.value.join(',') : c.value ?? ''} onChange={(e) => setL(i, { conditions: l.conditions.map((x: any, y: number) => (y === k ? { ...x, value: e.target.value } : x)) })} /><button type="button" aria-label="remove" className="text-danger" onClick={() => setL(i, { conditions: l.conditions.filter((_: any, y: number) => y !== k) })}><Trash2 className="size-4" /></button></div>))}
            <Button size="sm" variant="outline" onClick={() => setL(i, { conditions: [...l.conditions, { field: 'experience_years', operator: 'gte', value: '' }] })}>{t('career.path.addCondition')}</Button></div>
          <div className="space-y-2"><div className="text-xs font-semibold">{t('career.path.programs')}</div>{l.required_programs.map((g: any, k: number) => (
            <div key={k} className="flex flex-wrap gap-2"><select multiple className={`${input} h-24 flex-1`} value={g.program_ids} onChange={(e) => setL(i, { required_programs: l.required_programs.map((x: any, y: number) => (y === k ? { ...x, program_ids: Array.from(e.target.selectedOptions).map((o) => o.value) } : x)) })}>{(programs.data?.data ?? []).map((p: any) => <option key={p.id} value={p.id}>{p.code} — {p.title}</option>)}</select><label className="text-xs">{t('career.path.anyOf')}<input className={`${input} w-20`} type="number" min="1" value={g.min ?? ''} onChange={(e) => setL(i, { required_programs: l.required_programs.map((x: any, y: number) => (y === k ? { ...x, min: e.target.value } : x)) })} /></label><button type="button" aria-label="remove" className="text-danger" onClick={() => setL(i, { required_programs: l.required_programs.filter((_: any, y: number) => y !== k) })}><Trash2 className="size-4" /></button></div>))}
            <Button size="sm" variant="outline" onClick={() => setL(i, { required_programs: [...l.required_programs, { program_ids: [], min: 1 }] })}>{t('career.path.addGroup')}</Button></div>
        </div>))}
        <Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => setD({ ...d, levels: [...d.levels, { title_ar: '', title_en: '', conditions: [], required_programs: [] }] })}>{t('career.path.addLevel')}</Button></div>
      <div className="mt-4"><Button variant="gold" onClick={() => void save()}>{t('common.save')}</Button></div>
    </Modal>
  )
}

function Compliance() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const { can } = useAuth()
  const paths = useGet<{ data: any[] }>('/admin/career-paths')
  const [pathId, setPathId] = useState('')
  const id = pathId || paths.data?.data[0]?.id
  const [status, setStatus] = useState('')
  const res = useGet<{ data: any }>(id ? `/admin/career-paths/${id}/compliance` : null, status ? { status } : undefined, { staleTime: 0 })
  const d = res.data?.data
  const achieve = async (employee_id: string) => { try { await api.post(`/admin/career-paths/${id}/achieve`, { employee_id }); toast(t('career.path.achieved')); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2"><select className="rounded-xl border border-navy-100 px-3 py-2 text-sm" value={id ?? ''} onChange={(e) => setPathId(e.target.value)}>{(paths.data?.data ?? []).map((p) => <option key={p.id} value={p.id}>{ar ? p.title_ar : p.title_en}</option>)}</select>
        <select className="rounded-xl border border-navy-100 px-3 py-2 text-sm" value={status} onChange={(e) => setStatus(e.target.value)}><option value="">—</option>{['not_started', 'in_progress', 'eligible', 'achieved', 'expired'].map((s) => <option key={s} value={s}>{t(`career.status.${s}`)}</option>)}</select>
        <div className="ms-auto flex gap-2">{['xlsx', 'pdf', 'docx'].map((f) => <Button key={f} size="sm" variant="outline" onClick={() => void downloadFile(`/admin/career-paths/${id}/compliance?format=${f}&lang=${i18n.language}`, `compliance.${f}`)}>{f.toUpperCase()}</Button>)}</div></div>
      {!d ? <Spinner /> : (
        <>
          <div className="grid gap-3 sm:grid-cols-4">{d.funnel.map((f: any) => <Card key={f.level_no} className="text-center"><div className="text-2xl font-extrabold text-navy-900">{f.at_level}</div><div className="text-xs text-slate-500">{ar ? f.title_ar : f.title_en}</div>{f.eligible_for > 0 && <div className="mt-1 text-[11px] font-bold text-emerald-700">{t('career.path.eligibleFor')}: {f.eligible_for}</div>}</Card>)}</div>
          <Table head={[t('career.path.employees'), t('career.licence.level'), t('common.status'), t('career.path.missing'), t('career.licence.expires'), '']}>{d.matrix.map((m: any) => <tr key={m.employee_id}><Td><div className="font-semibold">{m.name}</div><div className="text-xs text-slate-400">{m.school}</div></Td><Td>{m.current_level}</Td><Td><Badge color={m.status === 'eligible' || m.status === 'achieved' ? 'green' : 'gold'}>{t(`career.status.${m.status}`)}</Badge></Td><Td className="text-xs">{m.unmet.join(' · ')}</Td><Td>{fmt.date(m.expires_at)}</Td><Td>{m.status === 'eligible' && can('paths.manage') && <Button size="sm" variant="gold" onClick={() => void achieve(m.employee_id)}>{t('career.path.achieve')}</Button>}</Td></tr>)}</Table>
        </>)}
    </div>
  )
}

function Licences() {
  const { t } = useTranslation()
  const [expiring, setExpiring] = useState(false)
  const res = useGet<{ data: any[] }>('/admin/licences', expiring ? { expiring: 1 } : undefined, { staleTime: 0 })
  const file = useRef<HTMLInputElement>(null)
  const imp = async (f: File) => { const fd = new FormData(); fd.append('file', f); try { const { data } = await api.post('/admin/licences/import', fd); toast(t('career.licence.imported', { created: data.data.created, updated: data.data.updated, errors: data.data.errors.length })); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-3"><label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={expiring} onChange={(e) => setExpiring(e.target.checked)} />{t('career.licence.expiring')}</label><span className="text-xs text-slate-400">{t('career.licence.importHint')}</span>
        <Button className="ms-auto" size="sm" variant="outline" onClick={() => file.current?.click()}>{t('career.licence.import')}</Button><input ref={file} type="file" accept=".csv,.txt" hidden onChange={(e) => { const f = e.target.files?.[0]; if (f) void imp(f); e.target.value = '' }} /></div>
      {res.isLoading ? <Spinner /> : !res.data?.data.length ? <Card><Empty text={t('career.licence.empty')} /></Card> : <Table head={[t('career.path.employees'), t('career.licence.number'), t('career.licence.level'), t('career.licence.expires'), t('common.status'), t('career.licence.source')]}>{res.data.data.map((l) => <tr key={l.id}><Td>{l.employee_name}</Td><Td><span dir="ltr">{l.licence_no}</span></Td><Td>{l.level_no}</Td><Td>{fmt.date(l.expires_at)}</Td><Td><Badge color={l.status === 'active' ? 'green' : 'red'}>{t(`career.licence.status.${l.status}`)}</Badge></Td><Td className="text-xs">{l.source}</Td></tr>)}</Table>}
    </div>
  )
}
