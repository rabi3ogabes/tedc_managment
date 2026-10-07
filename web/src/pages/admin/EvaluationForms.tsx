/* eslint-disable @typescript-eslint/no-explicit-any */
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { newQuestion, type Question } from '@/lib/surveys'
import { toast } from '@/lib/toast'
import QuestionEditor from './needs/QuestionEditor'
import { dialogs } from '@/lib/dialogs'

const input = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'
const KINDS = ['trainer_reflection', 'planning_evaluation', 'supervisor_feedback', 'specialist_feedback', 'custom']

/** The evaluation form designer: Survey Studio questions plus bilingual titles, evidence per question and the approval flow. */
export default function EvaluationForms() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const { can } = useAuth()
  const list = useGet<{ data: any[] }>('/admin/evaluation-forms', undefined, { staleTime: 0 })
  const [edit, setEdit] = useState<any | null>(null)
  const open = (f: any | null) => setEdit(f ? { ...f, settings: f.settings ?? {} } : { kind: 'custom', title_ar: '', title_en: '', questions: [newQuestion('rating', (k) => t(k))], settings: { evidence: true, max_files: 3 } })
  const act = async (id: string, path: string, body?: object) => { try { await api.post(`/admin/evaluation-forms/${id}/${path}`, body); void list.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <>
      <PageHeader title={t('evalc.forms.title')} actions={<Button icon={<Plus className="size-4" />} onClick={() => open(null)}>{t('evalc.forms.new')}</Button>} />
      {list.isLoading ? <Spinner /> : !list.data?.data.length ? <Empty /> : (
        <div className="grid gap-3 lg:grid-cols-2">{list.data.data.map((f) => (
          <Card key={f.id} className="space-y-2"><div className="flex flex-wrap items-center gap-2"><b className="text-navy-900">{ar ? f.title_ar : f.title_en}</b><Badge>{t(`evalc.kinds.${f.kind}`)}</Badge><Badge color={f.approval_status === 'approved' ? 'green' : 'gold'}>{t(`evalc.forms.approval.${f.approval_status}`)}</Badge><span className="text-xs text-slate-400">{t('evalc.forms.version', { n: f.version })}</span></div>
            {f.is_system ? <p className="text-xs text-slate-500">{t('evalc.forms.system')}</p> : (
              <div className="flex flex-wrap gap-2"><Button size="sm" variant="outline" onClick={() => open(f)}>{t('common.edit')}</Button>
                {['draft', 'returned'].includes(f.approval_status) && <Button size="sm" variant="outline" onClick={() => void act(f.id, 'submit-approval')}>{t('evalc.forms.submit')}</Button>}
                {f.approval_status === 'pending' && can('instruments.approve') && <><Button size="sm" variant="gold" onClick={() => void act(f.id, 'approve')}>{t('evalc.forms.approve')}</Button><Button size="sm" variant="ghost" onClick={async () => { const note = await dialogs.prompt(t('evalc.forms.note')); if (note) void act(f.id, 'return', { note }) }}>{t('evalc.forms.returnIt')}</Button></>}</div>)}
          </Card>))}</div>)}
      <Modal wide open={!!edit} onClose={() => setEdit(null)} title={t('evalc.forms.title')}>{edit && <Editor f={edit} onDone={() => { setEdit(null); void list.refetch() }} />}</Modal>
    </>
  )
}

function Editor({ f, onDone }: { f: any; onDone: () => void }) {
  const { t } = useTranslation()
  const [d, setD] = useState<any>(f)
  const [busy, setBusy] = useState(false)
  const qs: Question[] = d.questions
  const setQ = (i: number, q: Question) => setD({ ...d, questions: qs.map((x, j) => (j === i ? q : x)) })
  const save = async () => {
    setBusy(true)
    try {
      const body = { kind: d.kind, title_ar: d.title_ar, title_en: d.title_en, questions: qs, settings: d.settings }
      f.id ? await api.put(`/admin/evaluation-forms/${f.id}`, body) : await api.post('/admin/evaluation-forms', body)
      toast(t('evalc.forms.saved')); onDone()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <div className="space-y-4">
      {f.approval_status === 'approved' && <p className="rounded-xl bg-amber-50 p-3 text-xs text-amber-900">{t('evalc.forms.changedNote')}</p>}
      <div className="grid gap-3 sm:grid-cols-3">
        <Field label={t('evalc.forms.kind')}><select className={input} disabled={!!f.id} value={d.kind} onChange={(e) => setD({ ...d, kind: e.target.value })}>{KINDS.map((k) => <option key={k} value={k}>{t(`evalc.kinds.${k}`)}</option>)}</select></Field>
        <Field label={t('evalc.forms.titleAr')}><input className={input} value={d.title_ar} onChange={(e) => setD({ ...d, title_ar: e.target.value })} /></Field>
        <Field label={t('evalc.forms.titleEn')}><input dir="ltr" className={input} value={d.title_en} onChange={(e) => setD({ ...d, title_en: e.target.value })} /></Field>
      </div>
      <div className="flex flex-wrap items-center gap-5 text-sm">
        <label className="flex items-center gap-2"><input type="checkbox" checked={!!d.settings.evidence} onChange={(e) => setD({ ...d, settings: { ...d.settings, evidence: e.target.checked } })} />{t('evalc.forms.evidenceAll')}</label>
        <label className="flex items-center gap-2"><input type="checkbox" checked={!!d.settings.anonymous} onChange={(e) => setD({ ...d, settings: { ...d.settings, anonymous: e.target.checked } })} />{t('evalc.forms.anonymous')}</label>
        <label className="flex items-center gap-2">{t('evalc.forms.maxFiles')}<input className="w-16 rounded-lg border border-navy-100 px-2 py-1" type="number" min="1" max="10" value={d.settings.max_files ?? 3} onChange={(e) => setD({ ...d, settings: { ...d.settings, max_files: Number(e.target.value) } })} /></label>
      </div>
      <div className="space-y-3">{qs.map((q, i) => (
        <div key={q.id} className="space-y-2">
          <QuestionEditor q={q} index={i} previous={qs.slice(0, i)} skills={[]} onChange={(x) => setQ(i, x)} onRemove={() => setD({ ...d, questions: qs.filter((_, j) => j !== i) })} onDuplicate={() => setD({ ...d, questions: [...qs.slice(0, i + 1), { ...q, id: `${q.id}c` }, ...qs.slice(i + 1)] })} onMove={(m) => { const a = [...qs]; [a[i], a[i + m]] = [a[i + m], a[i]]; setD({ ...d, questions: a }) }} canUp={i > 0} canDown={i < qs.length - 1} />
          <div className="grid gap-2 px-2 sm:grid-cols-[1fr_auto]"><input dir="ltr" className={input} placeholder={t('evalc.forms.questionEn')} value={q.title_en ?? ''} onChange={(e) => setQ(i, { ...q, title_en: e.target.value })} /><label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={!!q.evidence} onChange={(e) => setQ(i, { ...q, evidence: e.target.checked })} />{t('evalc.forms.evidence')}</label></div>
        </div>))}
        <Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => setD({ ...d, questions: [...qs, newQuestion('rating', (k) => t(k))] })}>{t('evalc.forms.addQuestion')}</Button></div>
      <Button variant="gold" loading={busy} onClick={() => void save()}>{t('common.save')}</Button>
    </div>
  )
}
