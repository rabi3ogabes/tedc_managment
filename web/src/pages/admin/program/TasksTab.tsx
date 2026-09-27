import { FileText, Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, Spinner, StatusBadge } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { Program } from '@/lib/types'

type Task = { id: string; title_ar: string; title_en: string; instructions_ar?: string; due_at?: string; submission_types: string[]; is_required: boolean; submissions_count: number; pending_count: number; approved_count: number }
type Submission = { id: string; employee_name: string; employee_no: string; status: string; text_response?: string; file_name?: string; file_url?: string; feedback?: string; version: number; updated_at: string }

export default function TasksTab({ program }: { program: Program }) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const { data, isLoading, refetch } = useGet<{ data: Task[] }>(`/admin/programs/${program.id}/tasks`)
  const [creating, setCreating] = useState(false)
  const [reviewing, setReviewing] = useState<Task | null>(null)
  const [form, setForm] = useState({ title_ar: '', title_en: '', instructions_ar: '', due_at: '', submission_types: ['pdf', 'word', 'text'], is_required: true })
  const [error, setError] = useState<string | null>(null)
  const title = (task: Task) => (i18n.language === 'ar' ? task.title_ar : task.title_en)

  const create = async () => {
    setError(null)
    try {
      await api.post(`/admin/programs/${program.id}/tasks`, { ...form, due_at: form.due_at || null })
      setCreating(false)
      refetch()
    } catch (e) {
      setError(errorMessage(e))
    }
  }

  return (
    <>
      {can('tasks.manage') && <Button className="mb-4" size="sm" icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>{t('admin.programs.addTask')}</Button>}
      {isLoading ? <Spinner /> : !data?.data.length ? <Card><Empty /></Card> : (
        <div className="grid gap-4 md:grid-cols-2">
          {data.data.map((task) => (
            <Card key={task.id}>
              <div className="flex items-start justify-between gap-3">
                <div>
                  <h4 className="font-bold text-navy-900">{title(task)}</h4>
                  <p className="mt-1 text-xs text-slate-400">{t('admin.tasks.due')}: {fmt.date(task.due_at)}</p>
                </div>
                {task.is_required && <Badge color="gold">{t('common.required')}</Badge>}
              </div>
              <div className="mt-3 flex flex-wrap gap-1">{task.submission_types.map((s) => <Badge key={s} color="gray">{s.toUpperCase()}</Badge>)}</div>
              <div className="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
                <div className="rounded-xl bg-ivory p-2"><div className="text-lg font-bold">{task.submissions_count}</div>{t('admin.tasks.submissions')}</div>
                <div className="rounded-xl bg-amber-50 p-2"><div className="text-lg font-bold text-amber-700">{task.pending_count}</div>{t('status.submitted')}</div>
                <div className="rounded-xl bg-emerald-50 p-2"><div className="text-lg font-bold text-emerald-700">{task.approved_count}</div>{t('status.approved')}</div>
              </div>
              {can('tasks.review') && <Button className="mt-4 w-full" variant="outline" size="sm" onClick={() => setReviewing(task)}>{t('admin.tasks.title')}</Button>}
            </Card>
          ))}
        </div>
      )}

      <Modal open={creating} onClose={() => setCreating(false)} title={t('admin.programs.addTask')}>
        <div className="space-y-3">
          <Field label={t('admin.programs.titleAr')}><input className="input" value={form.title_ar} onChange={(e) => setForm({ ...form, title_ar: e.target.value })} /></Field>
          <Field label={t('admin.programs.titleEn')}><input className="input" dir="ltr" value={form.title_en} onChange={(e) => setForm({ ...form, title_en: e.target.value })} /></Field>
          <Field label={t('admin.programs.descriptionAr')}><textarea className="input" value={form.instructions_ar} onChange={(e) => setForm({ ...form, instructions_ar: e.target.value })} /></Field>
          <Field label={t('admin.tasks.due')}><input className="input" type="datetime-local" value={form.due_at} onChange={(e) => setForm({ ...form, due_at: e.target.value })} /></Field>
          <div>
            <span className="label">{t('admin.tasks.types')}</span>
            <div className="flex gap-4">{['pdf', 'word', 'image', 'text'].map((s) => (
              <label key={s} className="flex items-center gap-1.5 text-sm"><input type="checkbox" className="accent-gold-600" checked={form.submission_types.includes(s)} onChange={(e) => setForm({ ...form, submission_types: e.target.checked ? [...form.submission_types, s] : form.submission_types.filter((x) => x !== s) })} />{s.toUpperCase()}</label>
            ))}</div>
          </div>
          {error && <p className="text-sm text-danger">{error}</p>}
          <div className="flex justify-end"><Button variant="gold" onClick={create}>{t('common.create')}</Button></div>
        </div>
      </Modal>

      {reviewing && <ReviewModal task={reviewing} onClose={() => { setReviewing(null); refetch() }} />}
    </>
  )
}

function ReviewModal({ task, onClose }: { task: Task; onClose: () => void }) {
  const { t } = useTranslation()
  const { data, isLoading, refetch } = useGet<{ data: Submission[] }>(`/admin/tasks/${task.id}/submissions`)
  const [feedback, setFeedback] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)

  const review = async (id: string, status: string) => {
    setError(null)
    try {
      await api.post(`/admin/submissions/${id}/review`, { status, feedback: feedback[id] || null })
      refetch()
    } catch (e) {
      setError(errorMessage(e))
    }
  }
  const openFile = async (id: string) => {
    const { data: res } = await api.get(`/admin/submissions/${id}/file`)
    window.open(res.data.url, '_blank', 'noopener')
  }

  return (
    <Modal open onClose={onClose} title={t('admin.tasks.title')} wide>
      {error && <p className="mb-3 text-sm text-danger">{error}</p>}
      {isLoading ? <Spinner /> : !data?.data.length ? <Empty /> : (
        <div className="max-h-[60vh] space-y-3 overflow-y-auto">
          {data.data.map((s) => (
            <div key={s.id} className="rounded-2xl border border-navy-100 p-4">
              <div className="flex items-center justify-between">
                <div><span className="font-bold text-navy-900">{s.employee_name}</span> <span className="text-xs text-slate-400">{s.employee_no} · v{s.version}</span></div>
                <StatusBadge status={s.status} />
              </div>
              {s.text_response && <p className="mt-2 rounded-xl bg-ivory p-3 text-sm text-slate-700">{s.text_response}</p>}
              {s.file_name && <button onClick={() => openFile(s.id)} className="mt-2 inline-flex items-center gap-1.5 text-sm font-semibold text-gold-700"><FileText className="size-4" />{s.file_name}</button>}
              <textarea className="input mt-3 text-sm" placeholder={t('admin.tasks.feedback')} value={feedback[s.id] ?? s.feedback ?? ''} onChange={(e) => setFeedback({ ...feedback, [s.id]: e.target.value })} />
              <div className="mt-2 flex gap-2">
                <Button size="sm" variant="gold" onClick={() => review(s.id, 'approved')}>{t('admin.tasks.approve')}</Button>
                <Button size="sm" variant="outline" onClick={() => review(s.id, 'changes_requested')}>{t('admin.tasks.requestChanges')}</Button>
                <Button size="sm" variant="ghost" onClick={() => review(s.id, 'rejected')}>{t('admin.tasks.reject')}</Button>
              </div>
            </div>
          ))}
        </div>
      )}
    </Modal>
  )
}
