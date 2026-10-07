/* eslint-disable @typescript-eslint/no-explicit-any */
import AiDraftBox from '@/components/ai/AiDraftBox'
import { Plus, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, Spinner, Tabs, Table, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import type { Program } from '@/lib/types'
import { toast } from '@/lib/toast'
import { dialogs } from '@/lib/dialogs'

const KINDS = ['final', 'quiz', 'diagnostic', 'pre_test', 'post_test', 'comprehensive', 'practice']
const input = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'
const blank = (): any => ({ kind: 'quiz', title_ar: '', title_en: '', delivery: 'remote', access_code_mode: 'none', time_limit_minutes: '', max_attempts: 1, attempt_cooldown_hours: 0, pass_percent: 60, weight_in_course: 0, shuffle_questions: true, shuffle_options: true, feedback_mode: 'after_submit', show_score: true, show_correct_answers: false, require_restudy_on_fail: false, proctoring: { enabled: false, fullscreen: false, tab_switch_limit: 3, fullscreen_exit_limit: 3, action: 'flag', snapshots: false } })
const local = (v?: string | null) => (v ? v.slice(0, 16) : '')

function Editor({ program, a, onDone }: { program: Program; a: any | null; onDone: () => void }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const [f, setF] = useState<any>(a ? { ...blank(), ...a, window_opens_at: local(a.window_opens_at), window_closes_at: local(a.window_closes_at), proctoring: { ...blank().proctoring, ...(a.proctoring ?? {}) } } : blank())
  const [sections, setSections] = useState<any[]>(a?.sections ?? [])
  const banks = useGet<{ data: any[] }>('/admin/question-banks')
  const [busy, setBusy] = useState(false)
  const p = f.proctoring
  const tx = (k: string): string => String(t(`assess.builder.${k}`))
  const save = async () => {
    setBusy(true)
    try {
      const body = { kind: f.kind, title_ar: f.title_ar, title_en: f.title_en, delivery: f.delivery, access_code_mode: f.access_code_mode, time_limit_minutes: f.time_limit_minutes ? Number(f.time_limit_minutes) : null, window_opens_at: f.window_opens_at || null, window_closes_at: f.window_closes_at || null,
        max_attempts: Number(f.max_attempts), attempt_cooldown_hours: Number(f.attempt_cooldown_hours), pass_percent: Number(f.pass_percent), weight_in_course: Number(f.weight_in_course), shuffle_questions: f.shuffle_questions, shuffle_options: f.shuffle_options, feedback_mode: f.feedback_mode,
        show_score: f.show_score, show_correct_answers: f.show_correct_answers, require_restudy_on_fail: f.require_restudy_on_fail, proctoring: p }
      const { data } = a ? await api.put(`/admin/assessments/${a.id}`, body) : await api.post(`/admin/programs/${program.id}/assessments`, body)
      const id = a?.id ?? data.data.id
      await api.put(`/admin/assessments/${id}/sections`, { sections: sections.map((s) => ({ title: s.title || null, selection: s.selection, bank_id: s.bank_id || null, count: s.selection === 'random' ? Number(s.count) || 1 : null, points_per_question: s.points_per_question ? Number(s.points_per_question) : null, difficulty_mix: s.selection === 'random' && s.difficulty_mix && Object.keys(s.difficulty_mix).length ? s.difficulty_mix : null, question_ids: s.question_ids ?? null })) })
      toast(t('assess.studio.saved')); onDone()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const sel = (key: string, opts: string[], label: (o: string) => string) => <select className={input} value={f[key]} onChange={(e) => setF({ ...f, [key]: e.target.value })}>{opts.map((o) => <option key={o} value={o}>{label(o)}</option>)}</select>
  const chk = (key: string, label: string, obj?: 'p') => <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!(obj ? p[key] : f[key])} onChange={(e) => (obj ? setF({ ...f, proctoring: { ...p, [key]: e.target.checked } }) : setF({ ...f, [key]: e.target.checked }))} />{label}</label>
  const setSec = (i: number, patch: any) => setSections(sections.map((s, j) => (j === i ? { ...s, ...patch } : s)))
  return (
    <div className="space-y-5">
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={tx('kind')}>{sel('kind', KINDS, (k) => t(`assess.kinds.${k}`))}</Field>
        <Field label={tx('delivery')}>{sel('delivery', ['remote', 'in_center', 'either'], (k) => t(`assess.builder.deliveries.${k}`))}</Field>
        <Field label={`${tx('title')} (ع)`}><input className={input} value={f.title_ar} onChange={(e) => setF({ ...f, title_ar: e.target.value })} /></Field>
        <Field label={`${tx('title')} (EN)`}><input dir="ltr" className={input} value={f.title_en} onChange={(e) => setF({ ...f, title_en: e.target.value })} /></Field>
        <Field label={tx('codeMode')}>{sel('access_code_mode', ['none', 'static', 'rotating'], (k) => t(`assess.builder.codeModes.${k}`))}</Field>
        <Field label={tx('time')}><input className={input} type="number" min="1" value={f.time_limit_minutes ?? ''} onChange={(e) => setF({ ...f, time_limit_minutes: e.target.value })} /></Field>
        <Field label={tx('opens')}><input className={input} type="datetime-local" value={f.window_opens_at} onChange={(e) => setF({ ...f, window_opens_at: e.target.value })} /></Field>
        <Field label={tx('closes')}><input className={input} type="datetime-local" value={f.window_closes_at} onChange={(e) => setF({ ...f, window_closes_at: e.target.value })} /></Field>
        <Field label={tx('attempts')}><input className={input} type="number" min="1" value={f.max_attempts} onChange={(e) => setF({ ...f, max_attempts: e.target.value })} /></Field>
        <Field label={tx('cooldown')}><input className={input} type="number" min="0" value={f.attempt_cooldown_hours} onChange={(e) => setF({ ...f, attempt_cooldown_hours: e.target.value })} /></Field>
        <Field label={tx('pass')}><input className={input} type="number" min="0" max="100" value={f.pass_percent} onChange={(e) => setF({ ...f, pass_percent: e.target.value })} /></Field>
        <Field label={tx('weight')}><input className={input} type="number" min="0" max="100" value={f.weight_in_course} onChange={(e) => setF({ ...f, weight_in_course: e.target.value })} /></Field>
        <Field label={tx('feedback')}>{sel('feedback_mode', ['immediate', 'after_submit', 'after_close', 'never'], (k) => t(`assess.builder.feedbacks.${k}`))}</Field>
      </div>
      <div className="grid gap-2 sm:grid-cols-2">{chk('shuffle_questions', tx('shuffleQ'))}{chk('shuffle_options', tx('shuffleO'))}{chk('show_score', tx('showScore'))}{chk('show_correct_answers', tx('showCorrect'))}{chk('require_restudy_on_fail', tx('restudy'))}</div>
      <fieldset className="space-y-3 rounded-2xl border border-navy-100 p-4"><legend className="px-2 text-sm font-bold text-navy-900">{tx('proctoring')}</legend>
        {chk('enabled', tx('proctorOn'), 'p')}
        {p.enabled && <div className="grid gap-3 sm:grid-cols-2">
          <Field label={tx('tabLimit')}><input className={input} type="number" min="0" value={p.tab_switch_limit ?? ''} onChange={(e) => setF({ ...f, proctoring: { ...p, tab_switch_limit: e.target.value === '' ? null : Number(e.target.value) } })} /></Field>
          <Field label={tx('fsLimit')}><input className={input} type="number" min="0" value={p.fullscreen_exit_limit ?? ''} onChange={(e) => setF({ ...f, proctoring: { ...p, fullscreen_exit_limit: e.target.value === '' ? null : Number(e.target.value) } })} /></Field>
          <Field label={tx('action')}><select className={input} value={p.action} onChange={(e) => setF({ ...f, proctoring: { ...p, action: e.target.value } })}>{['flag', 'auto_submit'].map((k) => <option key={k} value={k}>{t(`assess.builder.actions.${k}`)}</option>)}</select></Field>
          <div className="space-y-2">{chk('fullscreen', tx('fullscreen'), 'p')}{chk('snapshots', tx('snapshots'), 'p')}</div></div>}
      </fieldset>
      <div className="space-y-3"><div className="flex items-center justify-between"><h3 className="font-bold text-navy-900">{tx('sections')}</h3><Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => setSections([...sections, { selection: 'random', count: 5, difficulty_mix: {} }])}>{tx('addSection')}</Button></div>
        {sections.map((s, i) => (
          <div key={i} className="grid gap-3 rounded-2xl border border-navy-100 p-3 sm:grid-cols-4">
            <Field label={tx('bank')} className="sm:col-span-2"><select className={input} value={s.bank_id ?? ''} onChange={(e) => setSec(i, { bank_id: e.target.value })}><option value="">—</option>{(banks.data?.data ?? []).map((b) => <option key={b.id} value={b.id}>{ar ? b.title_ar : b.title_en}</option>)}</select></Field>
            <Field label={tx('selection')}><select className={input} value={s.selection} onChange={(e) => setSec(i, { selection: e.target.value })}>{['random', 'fixed'].map((k) => <option key={k} value={k}>{t(`assess.builder.selections.${k}`)}</option>)}</select></Field>
            <Field label={tx('ppq')}><input className={input} type="number" min="0" step="0.5" value={s.points_per_question ?? ''} onChange={(e) => setSec(i, { points_per_question: e.target.value })} /></Field>
            {s.selection === 'random' && <><Field label={tx('count')}><input className={input} type="number" min="1" value={s.count ?? ''} onChange={(e) => setSec(i, { count: e.target.value })} /></Field>
              {['easy', 'medium', 'hard'].map((d) => <Field key={d} label={`${tx('mix')}: ${t(`assess.difficulty.${d}`)}`}><input className={input} type="number" min="0" value={s.difficulty_mix?.[d] ?? ''} onChange={(e) => setSec(i, { difficulty_mix: { ...(s.difficulty_mix ?? {}), [d]: e.target.value === '' ? undefined : Number(e.target.value) } })} /></Field>)}</>}
            <div className="flex items-end"><button type="button" aria-label="remove" className="text-danger" onClick={() => setSections(sections.filter((_, j) => j !== i))}><Trash2 className="size-4" /></button></div>
          </div>))}
      </div>
      <Button variant="gold" loading={busy} onClick={() => void save()}>{t('assess.studio.save')}</Button>
    </div>
  )
}

function Manage({ a, onChanged }: { a: any; onChanged: () => void }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const [tab, setTab] = useState<'ready' | 'live' | 'grading' | 'analytics'>('ready')
  const tx = (k: string, o?: any): string => String(t(`assess.builder.${k}`, o))
  const val = useGet<{ data: any }>(`/admin/assessments/${a.id}/validate`, undefined, { enabled: false })
  const [report, setReport] = useState<any>(null)
  const [code, setCode] = useState<string | null>(null)
  const check = async () => { try { const { data } = await api.post(`/admin/assessments/${a.id}/validate`); setReport(data.data) } catch (e) { toast(errorMessage(e), 'error') } }
  useEffect(() => { void check() }, [a.id]) // eslint-disable-line react-hooks/exhaustive-deps
  void val
  const act = async (fn: () => Promise<any>, ok: string) => { try { await fn(); toast(ok); onChanged(); void check() } catch (e) { toast(errorMessage(e), 'error') } }
  const live = useGet<{ data: any }>(`/admin/assessments/${a.id}/live`, undefined, { enabled: tab === 'live', refetchInterval: 10_000, staleTime: 0 })
  const grading = useGet<{ data: any[] }>(`/admin/assessments/${a.id}/grading`, undefined, { enabled: tab === 'grading', staleTime: 0 })
  const analytics = useGet<{ data: any }>(`/admin/assessments/${a.id}/analytics`, undefined, { enabled: tab === 'analytics', staleTime: 0 })
  const [grades, setGrades] = useState<Record<string, { points: string; comment: string }>>({})

  return (
    <div className="space-y-4">
      <Tabs value={tab} onChange={setTab} tabs={[{ id: 'ready', label: tx('validate') }, { id: 'live', label: tx('live') }, { id: 'grading', label: tx('grading') }, { id: 'analytics', label: tx('analytics') }]} />
      {tab === 'ready' && (
        <div className="space-y-3">
          {report && <div className={`rounded-xl p-3 text-sm ${report.valid ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-danger'}`}>{report.valid ? tx('valid', { q: report.questions, p: report.total_points }) : tx('notValid')}
            {report.sections?.flatMap((s: any) => s.shortages ?? []).map((s: any, i: number) => <div key={i}>{tx('shortage', { d: s.difficulty ?? '', needed: s.needed, available: s.available })}</div>)}</div>}
          <div className="flex flex-wrap gap-2">
            {a.status === 'draft' && <Button variant="gold" disabled={!report?.valid} onClick={() => void act(() => api.post(`/admin/assessments/${a.id}/publish`), tx('published'))}>{tx('publish')}</Button>}
            {a.access_code_mode !== 'none' && <Button variant="outline" onClick={async () => { try { const { data } = await api.post(`/admin/assessments/${a.id}/access-codes`, {}); setCode(data.data.code) } catch (e) { toast(errorMessage(e), 'error') } }}>{tx('newCode')}</Button>}
            <Button variant="outline" onClick={() => void act(() => api.post(`/admin/assessments/${a.id}/release`), tx('released'))}>{tx('release')}</Button>
            <Button variant="outline" onClick={() => void act(() => api.post(`/admin/assessments/${a.id}/regrade`, {}), tx('graded'))}>{tx('regrade')}</Button>
          </div>
          {code && <div className="rounded-xl bg-gold-50 p-3 text-sm font-bold" dir="ltr">{tx('codeShown', { code })}</div>}
        </div>)}
      {tab === 'live' && (!live.data ? <Spinner /> : <div className="space-y-3">
        {live.data.data.rotating_code && <div className="rounded-xl bg-navy-900 p-3 text-center text-white">{tx('rotating')}: <b className="text-2xl tabular-nums" dir="ltr">{live.data.data.rotating_code.code ?? live.data.data.rotating_code}</b></div>}
        {!live.data.data.attempts.length ? <Empty text={tx('noAttempts')} /> : <Table head={['', tx('answered'), tx('flags'), '']}>{live.data.data.attempts.map((r: any) => (
          <tr key={r.id}><Td>{r.employee}<div className="text-xs text-slate-400">{r.status}{r.remaining_seconds !== null && ` · ${Math.floor(r.remaining_seconds / 60)}′`}</div></Td><Td>{r.answered}/{r.total}</Td><Td>{(r.flags ?? []).length ? <Badge color="red">{(r.flags ?? []).join(', ')}</Badge> : '—'}</Td>
            <Td><div className="flex gap-1">{r.status === 'in_progress' && <><Button size="sm" variant="outline" onClick={() => void act(() => api.post(`/admin/attempts/${r.id}/extend`, { minutes: 10 }), tx('extend'))}>+10</Button><Button size="sm" variant="outline" onClick={async () => { const reason = await dialogs.prompt(tx('voidReason')); if (reason) void act(() => api.post(`/admin/attempts/${r.id}/void`, { reason }), tx('void')) }}>{tx('void')}</Button></>}</div></Td></tr>))}</Table>}</div>)}
      {tab === 'grading' && (!grading.data ? <Spinner /> : !grading.data.data.length ? <Empty text={tx('noAttempts')} /> : grading.data.data.map((g: any) => (
        <Card key={g.attempt_id} className="space-y-3"><div className="font-bold">{g.employee}</div><AiDraftBox attemptId={g.attempt_id} onGraded={() => void grading.refetch()} />
          {g.items.map((it: any) => { const k = `${g.attempt_id}:${it.question_id}`; return (
            <div key={k} className="space-y-2 rounded-xl bg-ivory p-3 text-sm"><div className="font-semibold" dir="auto">{ar ? it.stem_ar : it.stem_en || it.stem_ar}</div><div className="whitespace-pre-wrap rounded-lg bg-white p-2" dir="auto">{typeof it.answer === 'string' ? it.answer : JSON.stringify(it.answer)}</div>
              <div className="flex gap-2"><input className={`${input} max-w-28`} type="number" min="0" max={it.max_points} step="0.5" placeholder={`/ ${it.max_points}`} value={grades[k]?.points ?? ''} onChange={(e) => setGrades({ ...grades, [k]: { points: e.target.value, comment: grades[k]?.comment ?? '' } })} /><input className={input} placeholder={tx('comment')} value={grades[k]?.comment ?? ''} onChange={(e) => setGrades({ ...grades, [k]: { points: grades[k]?.points ?? '', comment: e.target.value } })} /></div></div>) })}
          <Button variant="gold" onClick={() => void act(() => api.post(`/admin/attempts/${g.attempt_id}/grade`, { grades: g.items.map((it: any) => ({ question_id: it.question_id, points: Number(grades[`${g.attempt_id}:${it.question_id}`]?.points ?? 0), comment: grades[`${g.attempt_id}:${it.question_id}`]?.comment || null })) }), tx('graded'))}>{tx('saveGrade')}</Button></Card>)))}
      {tab === 'analytics' && (!analytics.data ? <Spinner /> : (() => { const d = analytics.data.data; return (
        <div className="space-y-4"><div className="grid gap-3 sm:grid-cols-4">{[[tx('passRate'), d.pass_rate !== null ? `${d.pass_rate}%` : '—'], [tx('average'), d.average ?? '—'], [tx('median'), d.median ?? '—'], [tx('avgMinutes'), d.average_minutes ?? '—']].map(([l, v]) => <Card key={String(l)} className="text-center"><div className="text-2xl font-extrabold text-navy-900">{typeof v === 'number' ? fmt.number(v, 1) : v}</div><div className="text-xs text-slate-500">{l}</div></Card>)}</div>
          {d.items?.length > 0 && <Table head={[tx('items'), tx('answered'), tx('difficultyIndex'), tx('discrimination')]}>{d.items.map((it: any) => <tr key={it.question_id}><Td><span dir="auto">{ar ? it.stem_ar : it.stem_en || it.stem_ar}</span></Td><Td>{it.answered}</Td><Td>{it.difficulty_index}</Td><Td>{it.discrimination ?? '—'}</Td></tr>)}</Table>}</div>) })())}
    </div>
  )
}

/** The program's assessments: build, publish, run (codes, live monitor), grade, analyse, and the pre/post knowledge gain. */
export default function AssessmentsTab({ program }: { program: Program }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const { can } = useAuth()
  const list = useGet<{ data: any[] }>(`/admin/programs/${program.id}/assessments`, undefined, { staleTime: 0 })
  const gain = useGet<{ data: any }>(`/admin/programs/${program.id}/knowledge-gain`, undefined, { staleTime: 0 })
  const [edit, setEdit] = useState<any | 'new' | null>(null)
  const [open, setOpen] = useState<string | null>(null)
  const manage = can('assessments.manage')
  if (list.isLoading) return <Spinner />
  const rows = list.data?.data ?? []
  const g = gain.data?.data
  return (
    <div className="space-y-5">
      {(manage || can('adaptive.manage')) && <div className="flex flex-wrap justify-end gap-2">{can('adaptive.manage') && <Button variant="outline" to={`/admin/programs/${program.id}/adaptive`}>{t('aix.nav.adaptive')}</Button>}{manage && <Button icon={<Plus className="size-4" />} onClick={() => setEdit('new')}>{t('assess.builder.new')}</Button>}</div>}
      {g && g.rows.length > 0 && <Card className="space-y-2"><div className="flex items-center justify-between"><h3 className="font-bold text-navy-900">{t('assess.builder.gain')}</h3>{g.average_gain_percent !== null && <Badge color={g.average_gain_percent >= g.target_percent ? 'green' : 'gold'}>{fmt.number(g.average_gain_percent, 1)}% · {t('assess.builder.target', { n: g.target_percent })}</Badge>}</div>
        <Table head={['', t('assess.builder.pre'), t('assess.builder.post'), t('assess.builder.gainPercent')]}>{g.rows.map((r: any) => <tr key={r.registration_id}><Td>{r.employee}</Td><Td>{r.pre ?? '—'}</Td><Td>{r.post ?? '—'}</Td><Td>{r.gain_percent ?? '—'}</Td></tr>)}</Table></Card>}
      {!rows.length ? <Empty text={t('assess.builder.empty')} /> : rows.map((a) => (
        <Card key={a.id} className="space-y-3">
          <div className="flex flex-wrap items-center gap-2"><Badge>{t(`assess.kinds.${a.kind}`)}</Badge><b className="text-navy-900">{ar ? a.title_ar : a.title_en}</b><Badge color={a.status === 'published' ? 'green' : 'gold'}>{a.status === 'published' ? t('assess.builder.published') : t('assess.builder.draft')}</Badge><span className="text-xs text-slate-400">{a.attempts_count}</span>
            <div className="ms-auto flex gap-2">{manage && a.status === 'draft' && <Button size="sm" variant="outline" onClick={() => setEdit(a)}>{t('common.edit')}</Button>}<Button size="sm" variant="outline" onClick={() => setOpen(open === a.id ? null : a.id)}>{open === a.id ? '–' : '+'}</Button>
              {manage && a.attempts_count === 0 && <Button size="sm" variant="outline" onClick={async () => { if (await dialogs.confirm(t('assess.builder.delete'))) { try { await api.delete(`/admin/assessments/${a.id}`); void list.refetch() } catch (e) { toast(errorMessage(e), 'error') } } }}>{t('assess.builder.delete')}</Button>}</div></div>
          {open === a.id && <Manage a={a} onChanged={() => void list.refetch()} />}
        </Card>))}
      <Modal wide open={!!edit} onClose={() => setEdit(null)} title={t('assess.builder.new')}>{edit && <Editor key={edit === 'new' ? 'new' : edit.id} program={program} a={edit === 'new' ? null : edit} onDone={() => { setEdit(null); void list.refetch() }} />}</Modal>
    </div>
  )
}
