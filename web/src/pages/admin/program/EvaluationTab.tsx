/* eslint-disable @typescript-eslint/no-explicit-any */
import { Plus, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, Progress, Spinner, Table, Tabs, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import type { Program } from '@/lib/types'
import { toast } from '@/lib/toast'

const input = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'
type Sub = 'board' | 'results' | 'compare' | 'interviews' | 'report'

/** The evaluation centre of a program (or one group): every instrument, results with evidence, pre/post, interviews and the report. */
export default function EvaluationTab({ program }: { program: Program }) {
  const { t } = useTranslation()
  const [sub, setSub] = useState<Sub>('board')
  const [group, setGroup] = useState('')
  const groups = useGet<{ data: any[] }>(`/admin/programs/${program.id}/groups`)
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <Tabs<Sub> value={sub} onChange={setSub} tabs={(['board', 'results', 'compare', 'interviews', 'report'] as Sub[]).map((s) => ({ id: s, label: t(`evalc.sub.${s}`) }))} />
        <select aria-label={t('evalc.group')} className="ms-auto rounded-xl border border-navy-100 px-3 py-2 text-sm" value={group} onChange={(e) => setGroup(e.target.value)}><option value="">{t('evalc.all')}</option>{(groups.data?.data ?? []).map((g) => <option key={g.id} value={g.id}>{g.code}</option>)}</select>
      </div>
      {sub === 'board' && <Board program={program} group={group} groups={groups.data?.data ?? []} />}
      {sub === 'results' && <Results program={program} group={group} />}
      {sub === 'compare' && <Compare program={program} group={group} />}
      {sub === 'interviews' && <Interviews program={program} groups={groups.data?.data ?? []} />}
      {sub === 'report' && <Reports program={program} group={group} />}
    </div>
  )
}

function Board({ program, group, groups }: { program: Program; group: string; groups: any[] }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const res = useGet<{ data: any }>(`/admin/programs/${program.id}/evaluations`, group ? { group } : undefined, { staleTime: 0 })
  const [assign, setAssign] = useState(false)
  if (res.isLoading || !res.data) return <Spinner />
  const d = res.data.data
  const remind = async (id: string) => { try { await api.post(`/admin/evaluation-assignments/${id}/remind`); toast(t('evalc.reminded')); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <div className="space-y-4">
      {d.alert && <div className="rounded-2xl bg-red-50 p-4 text-sm font-semibold text-danger">{t('evalc.alertBanner', { avg: Math.round(d.alert.average), rate: Math.round(d.alert.response_rate), threshold: Math.round(d.alert.threshold) })}</div>}
      <p className="text-xs text-slate-500">{t('evalc.ruleNote', { rate: d.rule.min_response_rate, threshold: d.rule.threshold })}</p>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">{d.instruments.map((i: any) => (
        <Card key={i.key} className="space-y-2"><div className="font-bold text-navy-900">{t(`evalc.kinds.${i.key}`)}</div><Progress value={i.rate} tone={i.rate >= 80 ? 'green' : 'gold'} /><div className="text-xs text-slate-500">{t('evalc.responses', { n: i.responses, total: i.expected })} · {t('evalc.rate')} {Math.round(i.rate)}%</div></Card>))}</div>
      <div className="flex items-center justify-between"><h3 className="font-bold text-navy-900">{t('evalc.assignments')}</h3><Button size="sm" icon={<Plus className="size-4" />} onClick={() => setAssign(true)}>{t('evalc.assign')}</Button></div>
      {!d.assignments.length ? <Empty /> : <Table head={[t('evalc.form'), t('evalc.respondent'), t('common.status'), t('evalc.due'), '']}>{d.assignments.map((a: any) => (
        <tr key={a.id}><Td>{ar ? a.title_ar : a.title_en}</Td><Td>{a.respondent}</Td><Td><Badge color={a.status === 'submitted' ? 'green' : a.status === 'expired' ? 'gray' : 'gold'}>{t(`evalc.status.${a.status}`)}</Badge></Td><Td>{fmt.date(a.due_at)}</Td><Td>{a.status === 'pending' && <Button size="sm" variant="outline" onClick={() => void remind(a.id)}>{t('evalc.remind')}</Button>}</Td></tr>))}</Table>}
      {assign && <AssignModal program={program} forms={d.forms} groups={groups} defaultGroup={group} onClose={(c) => { setAssign(false); if (c) void res.refetch() }} />}
    </div>
  )
}

function AssignModal({ program, forms, groups, defaultGroup, onClose }: { program: Program; forms: any[]; groups: any[]; defaultGroup: string; onClose: (changed?: boolean) => void }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const [formId, setFormId] = useState(forms[0]?.id ?? '')
  const [groupId, setGroupId] = useState(defaultGroup || groups[0]?.id || '')
  const [picked, setPicked] = useState<string[]>([])
  const [due, setDue] = useState('')
  const kind = forms.find((f) => f.id === formId)?.kind
  const users = useGet<{ data: any[] }>('/admin/evaluations/assignable-users', { kind })
  const go = async () => { try { const { data } = await api.post(`/admin/programs/${program.id}/evaluations/assign`, { form_id: formId, group_id: groupId, user_ids: picked, due_at: due || null }); toast(t('evalc.assigned', { n: data.data.assigned })); onClose(true) } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <Modal open onClose={() => onClose()} title={t('evalc.assign')}>
      {!forms.length ? <Empty text={t('evalc.noForms')} /> : (
        <div className="space-y-3">
          <Field label={t('evalc.form')}><select className={input} value={formId} onChange={(e) => setFormId(e.target.value)}>{forms.map((f) => <option key={f.id} value={f.id}>{ar ? f.title_ar : f.title_en}</option>)}</select></Field>
          <Field label={t('evalc.group')}><select className={input} value={groupId} onChange={(e) => setGroupId(e.target.value)}>{groups.map((g) => <option key={g.id} value={g.id}>{g.code}</option>)}</select></Field>
          <Field label={t('evalc.due')}><input className={input} type="date" value={due} onChange={(e) => setDue(e.target.value)} /></Field>
          <div className="max-h-48 space-y-1 overflow-y-auto rounded-xl border border-navy-100 p-2">{(users.data?.data ?? []).map((u) => <label key={u.id} className="flex items-center gap-2 text-sm"><input type="checkbox" checked={picked.includes(u.id)} onChange={(e) => setPicked(e.target.checked ? [...picked, u.id] : picked.filter((x) => x !== u.id))} />{u.name}</label>)}</div>
          <Button variant="gold" disabled={!picked.length || !groupId} onClick={() => void go()}>{t('evalc.assign')}</Button>
        </div>)}
    </Modal>
  )
}

function Results({ program, group }: { program: Program; group: string }) {
  const { t, i18n } = useTranslation()
  const [kind, setKind] = useState('satisfaction')
  const board = useGet<{ data: any }>(`/admin/programs/${program.id}/evaluations`, group ? { group } : undefined)
  const res = useGet<{ data: any }>(`/admin/programs/${program.id}/evaluations/${kind}/results`, group ? { group } : undefined, { staleTime: 0, retry: false })
  const kinds = (board.data?.data.instruments ?? []).map((i: any) => i.key).filter((k: string) => !['impact_trainee', 'impact_manager'].includes(k))
  const d = res.data?.data
  const open = async (e: any) => { try { const { data } = await api.get(`/admin/evaluation-responses/${e.response_id}/evidence/${e.question_id}/${e.index}`); window.open(data.data.url, '_blank', 'noopener') } catch (x) { toast(errorMessage(x), 'error') } }
  const exp = (format: string) => d?.form ? downloadFile(`/admin/evaluation-forms/${d.form.id}/export?program_id=${program.id}&format=${format}&lang=${i18n.language}${group ? `&group=${group}` : ''}`, `evaluation.${format}`) : downloadFile(`/admin/programs/${program.id}/satisfaction/export?format=${format}&lang=${i18n.language}`, `satisfaction.${format}`)
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2"><select className="rounded-xl border border-navy-100 px-3 py-2 text-sm" value={kind} onChange={(e) => setKind(e.target.value)}>{kinds.map((k: string) => <option key={k} value={k}>{t(`evalc.kinds.${k}`)}</option>)}</select>
        <div className="ms-auto flex gap-2">{['xlsx', 'pdf', 'docx'].map((f) => <Button key={f} size="sm" variant="outline" onClick={() => void exp(f)}>{f.toUpperCase()}</Button>)}</div></div>
      {!d ? (res.isLoading ? <Spinner /> : <Empty text={t('evalc.results.noData')} />) : d.hidden_until ? <Card>{t('evalc.results.hidden', { n: d.hidden_until })}</Card> : (
        <>
          <Card className="flex flex-wrap gap-6"><div><div className="text-2xl font-extrabold text-navy-900">{d.responses}</div><div className="text-xs text-slate-500">{t('evalc.sub.results')}</div></div>{(d.average_score ?? null) !== null && <div><div className="text-2xl font-extrabold text-navy-900">{fmt.number(d.average_score, 1)}%</div><div className="text-xs text-slate-500">{t('evalc.results.score')}</div></div>}</Card>
          {d.questions.map((q: any) => (
            <Card key={q.id} className="space-y-2"><div className="font-semibold text-navy-900">{q.title}</div><div className="text-xs text-slate-500">{t('evalc.results.answered')}: {q.answered}{q.average != null && ` · ${t('evalc.results.average')}: ${q.average}`}</div>
              {(q.options ?? q.distribution ?? []).map((o: any) => { const v = o.percent ?? o.score ?? (q.answered ? Math.round(((o.value ?? 0) / q.answered) * 100) : 0); return <div key={o.id ?? o.label} className="flex items-center gap-2 text-xs"><span className="w-28 truncate">{o.label}</span><div className="flex-1"><Progress value={v} /></div><span className="w-10 text-end">{v}%</span></div> })}
              {q.samples && <ul className="list-disc space-y-1 ps-5 text-sm text-slate-700">{q.samples.map((s: string, i: number) => <li key={i}>{s}</li>)}</ul>}
            </Card>))}
          {d.comments?.length > 0 && <Card><div className="mb-2 font-semibold">{t('evalc.results.comments')}</div><ul className="list-disc space-y-1 ps-5 text-sm">{d.comments.map((c: string, i: number) => <li key={i}>{c}</li>)}</ul></Card>}
          {d.evidence?.length > 0 && <Card><div className="mb-2 font-semibold">{t('evalc.results.evidence')}</div><div className="flex flex-wrap gap-2">{d.evidence.map((e: any, i: number) => <button key={i} type="button" onClick={() => void open(e)} className="rounded-full bg-ivory px-3 py-1 text-xs font-semibold text-link">{e.name || t('evalc.results.openEvidence')}{e.by ? ` · ${e.by}` : ''}</button>)}</div></Card>}
        </>)}
    </div>
  )
}

function Compare({ program, group }: { program: Program; group: string }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const res = useGet<{ data: any }>(`/admin/programs/${program.id}/comparative`, group ? { group } : undefined, { staleTime: 0 })
  if (res.isLoading || !res.data) return <Spinner />
  const d = res.data.data
  if (!d.participants_with_both) return <Card><Empty text={t('evalc.cmp.none')} /></Card>
  const sig = d.significance
  const max = Math.max(1, ...Object.values<number>(d.distribution))
  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-4">{[[t('evalc.cmp.pre'), `${fmt.number(d.pre_average, 1)}%`], [t('evalc.cmp.post'), `${fmt.number(d.post_average, 1)}%`], [t('evalc.cmp.gain'), d.average_gain_percent != null ? `${d.average_gain_percent}%` : '—'], [t('evalc.cmp.target', { n: d.target_percent }), d.target_met == null ? '—' : d.target_met ? t('evalc.cmp.met') : t('evalc.cmp.notMet')]].map(([l, v]) => <Card key={String(l)} className="text-center"><div className="text-2xl font-extrabold text-navy-900">{v}</div><div className="text-xs text-slate-500">{l}</div></Card>)}</div>
      <Card className="space-y-2"><div className="text-xs text-slate-500">{t('evalc.cmp.pairs', { n: d.participants_with_both })}</div>
        <div className="flex gap-3"><div className="flex-1"><div className="mb-1 text-xs">{t('evalc.cmp.pre')}</div><Progress value={d.pre_average} tone="navy" /></div><div className="flex-1"><div className="mb-1 text-xs">{t('evalc.cmp.post')}</div><Progress value={d.post_average} tone="green" /></div></div>
        <p className="text-sm">{t(`evalc.cmp.sig.${sig.level}`)}{sig.effect ? ` · ${t(`evalc.cmp.effect.${sig.effect}`)}` : ''}</p></Card>
      <Card className="space-y-2"><div className="font-semibold">{t('evalc.cmp.distribution')}</div>{Object.entries<number>(d.distribution).map(([k, v]) => <div key={k} className="flex items-center gap-2 text-xs"><span className="w-24">{t(`evalc.cmp.buckets.${k}`)}</span><div className="flex-1"><Progress value={(v / max) * 100} /></div><span className="w-6 text-end">{v}</span></div>)}</Card>
      {d.skills.length > 0 && <Card><div className="mb-2 font-semibold">{t('evalc.cmp.skills')}</div><Table head={[t('evalc.cmp.skill'), t('evalc.cmp.pre'), t('evalc.cmp.post'), '+']}>{d.skills.map((s: any) => <tr key={s.skill_id}><Td>{ar ? s.name_ar : s.name_en}</Td><Td>{s.pre ?? '—'}</Td><Td>{s.post ?? '—'}</Td><Td>{s.gain ?? '—'}</Td></tr>)}</Table></Card>}
    </div>
  )
}

function Interviews({ program, groups }: { program: Program; groups: any[] }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const res = useGet<{ data: any[] }>(`/admin/programs/${program.id}/interviews`, undefined, { staleTime: 0 })
  const [open, setOpen] = useState(false)
  const [f, setF] = useState<any>({ held_at: new Date().toISOString().slice(0, 16), method: 'in_person', sentiment: 'neutral', interviewee_name: '', summary: '', group_id: '', qa: [{ question: '', answer: '' }], links: '' })
  const save = async () => {
    try {
      await api.post(`/admin/programs/${program.id}/interviews`, { held_at: f.held_at, method: f.method, sentiment: f.sentiment, interviewee_name: f.interviewee_name || null, summary: f.summary || null, group_id: f.group_id || null, questions_answers: f.qa.filter((x: any) => x.question), links: f.links.split('\n').map((x: string) => x.trim()).filter(Boolean) })
      toast(t('evalc.interviews.saved')); setOpen(false); void res.refetch()
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <div className="space-y-3">
      {can('interviews.manage') && <div className="flex justify-end"><Button size="sm" icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>{t('evalc.interviews.add')}</Button></div>}
      {res.isLoading ? <Spinner /> : !res.data?.data.length ? <Card><Empty text={t('evalc.interviews.empty')} /></Card> : res.data.data.map((i) => (
        <Card key={i.id} className="space-y-1"><div className="flex flex-wrap items-center gap-2"><b>{i.interviewee_name || '—'}</b><Badge color={i.sentiment === 'positive' ? 'green' : i.sentiment === 'negative' ? 'red' : 'gray'}>{t(`evalc.interviews.sentiments.${i.sentiment}`)}</Badge><span className="text-xs text-slate-400">{fmt.date(i.held_at)} · {t(`evalc.interviews.methods.${i.method}`)}</span></div>{i.summary && <p className="text-sm text-slate-700">{i.summary}</p>}</Card>))}
      <Modal wide open={open} onClose={() => setOpen(false)} title={t('evalc.interviews.add')}>
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t('evalc.interviews.who')}><input className={input} value={f.interviewee_name} onChange={(e) => setF({ ...f, interviewee_name: e.target.value })} /></Field>
          <Field label={t('evalc.interviews.held')}><input className={input} type="datetime-local" value={f.held_at} onChange={(e) => setF({ ...f, held_at: e.target.value })} /></Field>
          <Field label={t('evalc.interviews.method')}><select className={input} value={f.method} onChange={(e) => setF({ ...f, method: e.target.value })}>{['in_person', 'online', 'phone'].map((m) => <option key={m} value={m}>{t(`evalc.interviews.methods.${m}`)}</option>)}</select></Field>
          <Field label={t('evalc.interviews.sentiment')}><select className={input} value={f.sentiment} onChange={(e) => setF({ ...f, sentiment: e.target.value })}>{['positive', 'neutral', 'negative'].map((m) => <option key={m} value={m}>{t(`evalc.interviews.sentiments.${m}`)}</option>)}</select></Field>
          <Field label={t('evalc.group')}><select className={input} value={f.group_id} onChange={(e) => setF({ ...f, group_id: e.target.value })}><option value="">—</option>{groups.map((g) => <option key={g.id} value={g.id}>{g.code}</option>)}</select></Field>
          <Field label={t('evalc.interviews.summary')} className="sm:col-span-2"><textarea className={input} rows={3} value={f.summary} onChange={(e) => setF({ ...f, summary: e.target.value })} /></Field>
          <div className="space-y-2 sm:col-span-2"><div className="text-sm font-semibold">{t('evalc.interviews.qa')}</div>{f.qa.map((x: any, i: number) => <div key={i} className="flex gap-2"><input className={input} placeholder={t('evalc.interviews.question')} value={x.question} onChange={(e) => setF({ ...f, qa: f.qa.map((y: any, j: number) => (j === i ? { ...y, question: e.target.value } : y)) })} /><input className={input} placeholder={t('evalc.interviews.answer')} value={x.answer} onChange={(e) => setF({ ...f, qa: f.qa.map((y: any, j: number) => (j === i ? { ...y, answer: e.target.value } : y)) })} /><button type="button" aria-label="remove" className="text-danger" onClick={() => setF({ ...f, qa: f.qa.filter((_: any, j: number) => j !== i) })}><Trash2 className="size-4" /></button></div>)}<Button size="sm" variant="outline" onClick={() => setF({ ...f, qa: [...f.qa, { question: '', answer: '' }] })}>{t('evalc.interviews.addQa')}</Button></div>
          <Field label={t('evalc.interviews.links')} className="sm:col-span-2"><textarea dir="ltr" className={input} rows={2} value={f.links} onChange={(e) => setF({ ...f, links: e.target.value })} /></Field>
          <Button variant="gold" onClick={() => void save()}>{t('common.save')}</Button>
        </div>
      </Modal>
    </div>
  )
}

function Reports({ program, group }: { program: Program; group: string }) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const list = useGet<{ data: any[] }>(`/admin/programs/${program.id}/evaluation-reports`, undefined, { staleTime: 0 })
  const [cur, setCur] = useState<any | null>(null)
  const [lang, setLang] = useState(i18n.language === 'ar' ? 'ar' : 'en')
  const generate = async () => { try { const { data } = await api.post(`/admin/programs/${program.id}/evaluation-reports`, { group_id: group || null }); void list.refetch(); setCur(data.data) } catch (e) { toast(errorMessage(e), 'error') } }
  const lines = (a: any[], l: string) => (a ?? []).map((x) => x[l]).join('\n')
  const save = async (status?: string) => {
    try {
      const body: any = { qualitative: { strengths: split(cur._s_ar, cur._s_en), improvements: split(cur._i_ar, cur._i_en) }, recommendations_ar: cur.recommendations_ar, recommendations_en: cur.recommendations_en, classification: cur.classification, ...(status ? { status } : {}) }
      const { data } = await api.put(`/admin/evaluation-reports/${cur.id}`, body); toast(t('evalc.report.saved')); setCur(prep(data.data)); void list.refetch()
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  const approve = async () => { try { const { data } = await api.post(`/admin/evaluation-reports/${cur.id}/approve`); toast(t('evalc.report.approved')); setCur(prep(data.data)); void list.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const split = (ar: string, en: string) => { const a = (ar ?? '').split('\n'); const e = (en ?? '').split('\n'); return Array.from({ length: Math.max(a.length, e.length) }, (_, i) => ({ ar: a[i] ?? '', en: e[i] ?? '' })).filter((x) => x.ar || x.en) }
  const prep = (r: any) => ({ ...r, _s_ar: lines(r.qualitative?.strengths, 'ar'), _s_en: lines(r.qualitative?.strengths, 'en'), _i_ar: lines(r.qualitative?.improvements, 'ar'), _i_en: lines(r.qualitative?.improvements, 'en') })
  const locked = cur?.status === 'approved'
  return (
    <div className="space-y-3">
      {can('evaluation_reports.prepare') && <div className="flex justify-end"><Button size="sm" icon={<Plus className="size-4" />} onClick={() => void generate()}>{t('evalc.report.generate')}</Button></div>}
      {list.isLoading ? <Spinner /> : !list.data?.data.length ? <Card><Empty text={t('evalc.report.empty')} /></Card> : list.data.data.map((r) => (
        <Card key={r.id} className="flex flex-wrap items-center gap-3"><Badge color={r.classification === 'successful_continue' ? 'green' : r.classification === 'weak_stop' ? 'red' : 'gold'}>{t(`evalc.report.class.${r.classification}`)}</Badge><Badge color={r.status === 'approved' ? 'green' : 'gray'}>{t(`evalc.report.status.${r.status}`)}</Badge><span className="text-xs text-slate-400">{r.period}</span><Button className="ms-auto" size="sm" variant="outline" onClick={() => setCur(prep(r))}>{t('evalc.report.open')}</Button></Card>))}
      <Modal wide open={!!cur} onClose={() => setCur(null)} title={t('evalc.report.title')}>
        {cur && (
          <div className="space-y-4">
            <div className="grid gap-2 sm:grid-cols-5">{[['attendance', cur.metrics.attendance_average], ['pass', cur.metrics.pass_rate], ['satisfaction', cur.metrics.satisfaction.average], ['gain', cur.metrics.knowledge_gain.average_percent], ['impact', cur.metrics.impact.score]].map(([k, v]) => <div key={String(k)} className="rounded-xl bg-ivory p-2 text-center"><div className="text-lg font-bold">{v == null ? '—' : fmt.number(Number(v), 1)}</div><div className="text-[11px] text-slate-500">{t(`evalc.report.metricNames.${k}`)}</div></div>)}</div>
            <div className="space-y-1 text-sm"><b>{t('evalc.report.reasons')}</b>{(cur.classification_reasons ?? []).map((r: any) => <div key={r.key} className="flex gap-2"><span className="w-32">{t(`evalc.report.metricNames.${r.key === 'knowledge_gain' ? 'gain' : r.key}`)}</span><span>{r.value ?? '—'}</span><Badge color={r.state === 'good' ? 'green' : r.state === 'low' ? 'red' : 'gray'}>{t(`evalc.report.state.${r.state}`)}</Badge></div>)}</div>
            <Field label={t('evalc.report.classification')}><select className={input} disabled={locked} value={cur.classification} onChange={(e) => setCur({ ...cur, classification: e.target.value })}>{['successful_continue', 'needs_review', 'weak_stop'].map((c) => <option key={c} value={c}>{t(`evalc.report.class.${c}`)}</option>)}</select></Field>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field label={`${t('evalc.report.strengths')} (ع)`}><textarea className={input} rows={4} disabled={locked} value={cur._s_ar} onChange={(e) => setCur({ ...cur, _s_ar: e.target.value })} /></Field>
              <Field label={`${t('evalc.report.strengths')} (EN)`}><textarea dir="ltr" className={input} rows={4} disabled={locked} value={cur._s_en} onChange={(e) => setCur({ ...cur, _s_en: e.target.value })} /></Field>
              <Field label={`${t('evalc.report.improvements')} (ع)`}><textarea className={input} rows={4} disabled={locked} value={cur._i_ar} onChange={(e) => setCur({ ...cur, _i_ar: e.target.value })} /></Field>
              <Field label={`${t('evalc.report.improvements')} (EN)`}><textarea dir="ltr" className={input} rows={4} disabled={locked} value={cur._i_en} onChange={(e) => setCur({ ...cur, _i_en: e.target.value })} /></Field>
              <Field label={`${t('evalc.report.recommendations')} (ع)`}><textarea className={input} rows={3} disabled={locked} value={cur.recommendations_ar ?? ''} onChange={(e) => setCur({ ...cur, recommendations_ar: e.target.value })} /></Field>
              <Field label={`${t('evalc.report.recommendations')} (EN)`}><textarea dir="ltr" className={input} rows={3} disabled={locked} value={cur.recommendations_en ?? ''} onChange={(e) => setCur({ ...cur, recommendations_en: e.target.value })} /></Field>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              {!locked && can('evaluation_reports.prepare') && <><Button variant="outline" onClick={() => void save()}>{t('common.save')}</Button>{cur.status === 'draft' && <Button variant="gold" onClick={() => void save('reviewed')}>{t('evalc.report.markReviewed')}</Button>}</>}
              {cur.status === 'reviewed' && can('evaluation_reports.approve') && <Button variant="gold" onClick={() => void approve()}>{t('evalc.report.approve')}</Button>}
              <select aria-label={t('evalc.report.lang')} className="ms-auto rounded-xl border border-navy-100 px-2 py-2 text-sm" value={lang} onChange={(e) => setLang(e.target.value)}><option value="ar">العربية</option><option value="en">English</option></select>
              {[['pdf', 'exportPdf'], ['docx', 'exportWord'], ['xlsx', 'exportExcel']].map(([f, k]) => <Button key={f} size="sm" variant="outline" onClick={() => void downloadFile(`/admin/evaluation-reports/${cur.id}/export?format=${f}&lang=${lang}`, `evaluation-report.${f}`)}>{t(`evalc.report.${k}`)}</Button>)}
            </div>
          </div>)}
      </Modal>
    </div>
  )
}
