/* eslint-disable @typescript-eslint/no-explicit-any */
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Field, Spinner, Table, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import type { Program } from '@/lib/types'

const KEYS = ['attendance', 'participation', 'tasks', 'assessments', 'course', 'evaluation', 'knowledge_transfer'] as const
const input = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'
const blank = (): any => ({ mode: 'all_required', pass_threshold: 60, criteria: [{ key: 'attendance', required: true, min: 80, weight: 0 }], assessment_ids: [], participation_rules: { lessons: true, session_marks: true }, allow_test_out: false, test_out_assessment_id: null, hours_mode: 'total', certificate_types: 'pass', attendance_certificate_min: 80, survey_required_for_download: true, task_approval: 'trainer', certificate_templates: {} })

/** The passing policy for one scope: criteria with weights that always add up to 100 %, certificate types, task approval, test-out and a live simulation. */
export function PolicyEditor({ scope, id, programId }: { scope: 'global' | 'program' | 'group'; id?: string; programId?: string }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const url = `/admin/passing-policies/${scope}${id ? `/${id}` : ''}`
  const cur = useGet<{ data: { policy: any | null; inherited_from: string | null; effective: any | null } }>(url, undefined, { staleTime: 0 })
  const assessments = useGet<{ data: any[] }>(programId ? `/admin/programs/${programId}/assessments` : null)
  const templates = useGet<{ data: any[] }>('/admin/certificate-templates')
  const [f, setF] = useState<any>(blank())
  const [sim, setSim] = useState<any>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => { const p = cur.data?.data.policy; setF(p ? { ...blank(), ...p, participation_rules: { ...blank().participation_rules, ...(p.participation_rules ?? {}) }, assessment_ids: p.assessment_ids ?? [], certificate_templates: p.certificate_templates ?? {} } : (cur.data?.data.effective ? { ...blank(), ...cur.data.data.effective } : blank())); setSim(null) }, [cur.data])

  if (cur.isLoading) return <Spinner />
  const crit: any[] = f.criteria
  const has = (k: string) => crit.some((c) => c.key === k)
  const sum = crit.reduce((a, c) => a + (Number(c.weight) || 0), 0)
  const setCrit = (k: string, patch: any) => setF({ ...f, criteria: crit.map((c) => (c.key === k ? { ...c, ...patch } : c)) })
  const toggle = (k: string, on: boolean) => setF({ ...f, criteria: on ? [...crit, { key: k, required: true, min: k === 'evaluation' || k === 'tasks' ? 100 : 60, weight: 0 }] : crit.filter((c) => c.key !== k) })
  const even = () => { const n = crit.length || 1; const base = Math.floor(100 / n); setF({ ...f, criteria: crit.map((c, i) => ({ ...c, weight: i === 0 ? 100 - base * (n - 1) : base })) }) }
  const body = () => ({ ...f, criteria: crit.map((c) => ({ key: c.key, required: !!c.required, min: Number(c.min), weight: Number(c.weight) || 0 })), pass_threshold: Number(f.pass_threshold), attendance_certificate_min: Number(f.attendance_certificate_min),
    assessment_ids: (f.assessment_ids ?? []).filter((x: any) => x.id).map((x: any) => ({ id: x.id, weight: Number(x.weight) || 1 })), test_out_assessment_id: f.allow_test_out ? f.test_out_assessment_id || null : null, certificate_templates: { attendance: f.certificate_templates?.attendance || null, pass: f.certificate_templates?.pass || null } })
  const save = async () => { setBusy(true); try { await api.put(url, body()); toast(t('passing.saved')); void cur.refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) } }
  const remove = async () => { try { await api.delete(url); toast(t('passing.removed')); void cur.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const simulate = async () => { if (!programId) return; try { const { data } = await api.post('/admin/passing-policies/preview', { ...body(), program_id: programId, group_id: scope === 'group' ? id : undefined }); setSim(data.data) } catch (e) { toast(errorMessage(e), 'error') } }
  const list = assessments.data?.data ?? []
  const tpls = (templates.data?.data ?? []).filter((x) => x.kind === 'trainee')

  return (
    <div className="space-y-5">
      {!cur.data?.data.policy && <div className="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">{cur.data?.data.inherited_from ? t('passing.inherited', { from: t(`passing.${cur.data.data.inherited_from}`) }) : t('passing.none')}</div>}
      <Card className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label={t('passing.mode')}><select className={input} value={f.mode} onChange={(e) => setF({ ...f, mode: e.target.value })}>{['all_required', 'weighted'].map((m) => <option key={m} value={m}>{t(`passing.modes.${m}`)}</option>)}</select></Field>
          {f.mode === 'weighted' && <Field label={t('passing.threshold')}><input className={input} type="number" min="0" max="100" value={f.pass_threshold} onChange={(e) => setF({ ...f, pass_threshold: e.target.value })} /></Field>}
          <Field label={t('passing.taskApproval')}><select className={input} value={f.task_approval} onChange={(e) => setF({ ...f, task_approval: e.target.value })}>{['trainer', 'trainer_then_supervisor', 'auto'].map((m) => <option key={m} value={m}>{t(`passing.approvals.${m}`)}</option>)}</select></Field>
        </div>
        <div className="space-y-2">
          {KEYS.map((k) => { const c = crit.find((x) => x.key === k); return (
            <div key={k} className={`grid items-center gap-3 rounded-xl border p-3 sm:grid-cols-[1fr_6rem_6rem_8rem] ${c ? 'border-gold-300 bg-gold-50/40' : 'border-navy-100'}`}>
              <label className="flex items-center gap-2 font-semibold text-navy-900"><input type="checkbox" checked={!!c} onChange={(e) => toggle(k, e.target.checked)} />{t(`passing.keys.${k}`)}</label>
              {c ? <>
                <label className="text-xs">{t('passing.minimum')}<input className={input} type="number" min="0" max="100" value={c.min} onChange={(e) => setCrit(k, { min: e.target.value })} /></label>
                {f.mode === 'weighted' ? <label className="text-xs">{t('passing.weight')}<input className={input} type="number" min="0" max="100" value={c.weight} onChange={(e) => setCrit(k, { weight: e.target.value })} /></label> : <span />}
                {f.mode === 'weighted' ? <label className="flex items-center gap-2 text-xs"><input type="checkbox" checked={!!c.required} onChange={(e) => setCrit(k, { required: e.target.checked })} />{t('passing.required')}</label> : <span />}
              </> : <><span /><span /><span /></>}
            </div>) })}
          {f.mode === 'weighted' && <div className="flex items-center justify-between text-sm"><span className={Math.abs(sum - 100) < 0.01 ? 'font-bold text-emerald-700' : 'font-bold text-danger'}>{Math.abs(sum - 100) < 0.01 ? t('passing.weightsOk') : t('passing.weights', { sum })}</span><Button size="sm" variant="outline" onClick={even}>=</Button></div>}
        </div>
        {has('participation') && <div className="flex flex-wrap gap-5 text-sm"><b>{t('passing.participationRules')}</b>
          <label className="flex items-center gap-2"><input type="checkbox" checked={f.participation_rules.lessons} onChange={(e) => setF({ ...f, participation_rules: { ...f.participation_rules, lessons: e.target.checked } })} />{t('passing.lessons')}</label>
          <label className="flex items-center gap-2"><input type="checkbox" checked={f.participation_rules.session_marks} onChange={(e) => setF({ ...f, participation_rules: { ...f.participation_rules, session_marks: e.target.checked } })} />{t('passing.sessionMarks')}</label></div>}
        {has('assessments') && programId && <div className="space-y-2"><b className="text-sm">{t('passing.assessmentWeights')}</b>{list.map((a) => { const row = (f.assessment_ids ?? []).find((x: any) => x.id === a.id); return (
          <div key={a.id} className="flex items-center gap-3 text-sm"><label className="flex flex-1 items-center gap-2"><input type="checkbox" checked={!!row} onChange={(e) => setF({ ...f, assessment_ids: e.target.checked ? [...(f.assessment_ids ?? []), { id: a.id, weight: 1 }] : (f.assessment_ids ?? []).filter((x: any) => x.id !== a.id) })} />{ar ? a.title_ar : a.title_en}</label>
            {row && <input className={`${input} max-w-24`} type="number" min="0" value={row.weight} onChange={(e) => setF({ ...f, assessment_ids: f.assessment_ids.map((x: any) => (x.id === a.id ? { ...x, weight: e.target.value } : x)) })} />}</div>) })}</div>}
      </Card>
      <Card className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-3">
          <Field label={t('passing.certTypes')}><select className={input} value={f.certificate_types} onChange={(e) => setF({ ...f, certificate_types: e.target.value })}>{['attendance', 'pass', 'both'].map((m) => <option key={m} value={m}>{t(`passing.types.${m}`)}</option>)}</select></Field>
          <Field label={t('passing.hoursMode')}><select className={input} value={f.hours_mode} onChange={(e) => setF({ ...f, hours_mode: e.target.value })}>{['total', 'actual'].map((m) => <option key={m} value={m}>{t(`passing.hours.${m}`)}</option>)}</select></Field>
          {f.certificate_types !== 'pass' && <Field label={t('passing.attendanceMin')}><input className={input} type="number" min="0" max="100" value={f.attendance_certificate_min} onChange={(e) => setF({ ...f, attendance_certificate_min: e.target.value })} /></Field>}
          {f.certificate_types !== 'pass' && <Field label={t('passing.templateAttendance')}><select className={input} value={f.certificate_templates?.attendance ?? ''} onChange={(e) => setF({ ...f, certificate_templates: { ...f.certificate_templates, attendance: e.target.value } })}><option value="">{t('passing.defaultTemplate')}</option>{tpls.map((x) => <option key={x.id} value={x.id}>{ar ? x.name_ar : x.name_en}</option>)}</select></Field>}
          {f.certificate_types !== 'attendance' && <Field label={t('passing.templatePass')}><select className={input} value={f.certificate_templates?.pass ?? ''} onChange={(e) => setF({ ...f, certificate_templates: { ...f.certificate_templates, pass: e.target.value } })}><option value="">{t('passing.defaultTemplate')}</option>{tpls.map((x) => <option key={x.id} value={x.id}>{ar ? x.name_ar : x.name_en}</option>)}</select></Field>}
        </div>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.survey_required_for_download} onChange={(e) => setF({ ...f, survey_required_for_download: e.target.checked })} />{t('passing.surveyRequired')}</label>
        {programId && <div className="space-y-2"><label className="flex items-center gap-2 text-sm font-semibold"><input type="checkbox" checked={f.allow_test_out} onChange={(e) => setF({ ...f, allow_test_out: e.target.checked })} />{t('passing.testOut')}</label>
          {f.allow_test_out && <Field label={t('passing.testOutAssessment')}><select className={input} value={f.test_out_assessment_id ?? ''} onChange={(e) => setF({ ...f, test_out_assessment_id: e.target.value })}><option value="">{t('passing.pick')}</option>{list.filter((a) => a.status === 'published' || a.id === f.test_out_assessment_id).map((a) => <option key={a.id} value={a.id}>{ar ? a.title_ar : a.title_en}</option>)}</select></Field>}</div>}
      </Card>
      <div className="flex flex-wrap gap-2">
        <Button variant="gold" loading={busy} onClick={() => void save()}>{t('passing.save')}</Button>
        {programId && <Button variant="outline" onClick={() => void simulate()}>{t('passing.simulate')}</Button>}
        {cur.data?.data.policy && <Button variant="ghost" onClick={() => void remove()}>{t('passing.remove')}</Button>}
      </div>
      {sim && <Card className="space-y-3"><div className="font-bold text-navy-900">{t('passing.simulation', { n: sim.would_pass, total: sim.total })}</div>
        <Table head={['', t('passing.now'), t('passing.score'), '']}>{sim.rows.map((r: any) => (
          <tr key={r.registration_id}><Td>{r.employee}</Td><Td><Badge color={r.now === 'passed' || r.now === 'exempted' ? 'green' : 'gray'}>{t(`passing.status.${r.now}`)}</Badge></Td><Td>{r.weighted_score ?? '—'}</Td><Td><Badge color={r.passed ? 'green' : 'red'}>{r.passed ? t('passing.wouldPass') : t('passing.wouldFail')}</Badge></Td></tr>))}</Table></Card>}
    </div>
  )
}

/** Knowledge transfer after the program: whether it is required, how many beneficiaries and hours, the deadline, and the reach so far. */
function KnowledgeTransferSetting({ program }: { program: Program }) {
  const { t } = useTranslation()
  const res = useGet<{ data: any }>(`/admin/programs/${program.id}/knowledge-transfer`, undefined, { staleTime: 0 })
  const [f, setF] = useState<any>(null)
  useEffect(() => { if (res.data) setF({ required: false, min_beneficiaries: 5, min_hours: 1, deadline_days: 30, evidence_required: true, ...(res.data.data.config ?? {}) }) }, [res.data])
  if (!f || !res.data) return null
  const r = res.data.data
  const save = async () => { try { await api.put(`/admin/programs/${program.id}/knowledge-transfer`, { ...f, min_beneficiaries: Number(f.min_beneficiaries), min_hours: Number(f.min_hours), deadline_days: Number(f.deadline_days) }); toast(t('passing.saved')); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <Card className="space-y-3"><h3 className="font-bold text-navy-900">{t('career.kt.setting')}</h3>
      <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={f.required} onChange={(e) => setF({ ...f, required: e.target.checked })} />{t('career.kt.required')}</label>
      {f.required && <div className="grid gap-3 sm:grid-cols-3"><Field label={t('career.kt.minBeneficiaries')}><input className={input} type="number" min="1" value={f.min_beneficiaries} onChange={(e) => setF({ ...f, min_beneficiaries: e.target.value })} /></Field><Field label={t('career.kt.minHours')}><input className={input} type="number" step="0.5" value={f.min_hours} onChange={(e) => setF({ ...f, min_hours: e.target.value })} /></Field><Field label={t('career.kt.deadline')}><input className={input} type="number" min="1" value={f.deadline_days} onChange={(e) => setF({ ...f, deadline_days: e.target.value })} /></Field></div>}
      <p className="text-xs text-slate-500">{t('career.kt.reach', { d: r.direct_trainees, i: r.indirect_beneficiaries, h: r.hours })}</p>
      <Button size="sm" variant="gold" onClick={() => void save()}>{t('passing.save')}</Button></Card>
  )
}

/** The program's passing rules, with an optional override for one group. */
export default function PassingTab({ program }: { program: Program }) {
  const { t } = useTranslation()
  const groups = useGet<{ data: any[] }>(`/admin/programs/${program.id}/groups`)
  const [scope, setScope] = useState<string>('program')
  const list = groups.data?.data ?? []
  return (
    <div className="space-y-4">
      <div className="flex items-center gap-2"><label className="text-sm font-semibold">{t('passing.scope')}</label>
        <select className="rounded-xl border border-navy-100 px-3 py-2 text-sm" value={scope} onChange={(e) => setScope(e.target.value)}><option value="program">{t('passing.program')}</option>{list.map((g) => <option key={g.id} value={g.id}>{t('passing.group')} — {g.code}</option>)}</select></div>
      <PolicyEditor key={scope} scope={scope === 'program' ? 'program' : 'group'} id={scope === 'program' ? program.id : scope} programId={program.id} />
      {scope === 'program' && <KnowledgeTransferSetting program={program} />}
    </div>
  )
}
