import { Paperclip, Upload } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, PageHeader, Spinner, StatusBadge } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'

type MyTask = {
  id: string; program: string; title: string; instructions?: string; due_at?: string; submission_types: string[]; max_file_mb: number; is_required: boolean
  submission: { id: string; status: string; feedback?: string; text_response?: string; file_name?: string; version: number } | null
}

const ACCEPT: Record<string, string> = { pdf: '.pdf', word: '.doc,.docx', image: '.png,.jpg,.jpeg,.webp,.heic' }

export default function MyTasks() {
  const { t } = useTranslation()
  const { data, isLoading, refetch } = useGet<{ data: MyTask[] }>('/me/tasks')
  return (
    <>
      <PageHeader title={t('portal.tasks')} />
      {isLoading ? <Spinner /> : !data?.data.length ? <Card><Empty /></Card> : (
        <div className="grid gap-4 lg:grid-cols-2">{data.data.map((task) => <TaskCard key={task.id} task={task} onDone={refetch} />)}</div>
      )}
    </>
  )
}

function TaskCard({ task, onDone }: { task: MyTask; onDone: () => void }) {
  const { t } = useTranslation()
  const [text, setText] = useState(task.submission?.text_response ?? '')
  const [file, setFile] = useState<File | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const locked = task.submission?.status === 'approved'
  const accept = task.submission_types.map((s) => ACCEPT[s]).filter(Boolean).join(',')

  const submit = async () => {
    setLoading(true)
    setError(null)
    const body = new FormData()
    if (text) body.append('text_response', text)
    if (file) body.append('file', file)
    try {
      await api.post(`/me/tasks/${task.id}/submit`, body)
      setFile(null)
      onDone()
    } catch (e) { setError(errorMessage(e)) } finally { setLoading(false) }
  }

  return (
    <Card>
      <div className="flex items-start justify-between gap-3">
        <div><div className="text-xs text-gold-700">{task.program}</div><h3 className="font-bold text-navy-900">{task.title}</h3><div className="text-xs text-slate-400">{t('admin.tasks.due')}: {fmt.dateTime(task.due_at)}</div></div>
        {task.submission ? <StatusBadge status={task.submission.status} /> : task.is_required && <Badge color="gold">{t('common.required')}</Badge>}
      </div>
      {task.instructions && <p className="mt-3 text-sm text-slate-600">{task.instructions}</p>}
      {task.submission?.feedback && <div className="mt-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">{task.submission.feedback}</div>}
      {!locked && (
        <div className="mt-4 space-y-3">
          {task.submission_types.includes('text') && <textarea className="input min-h-24" placeholder={t('portal.textResponse')} value={text} onChange={(e) => setText(e.target.value)} />}
          {task.submission_types.some((s) => s !== 'text') && (
            <label className="flex cursor-pointer items-center gap-2 rounded-xl border border-dashed border-navy-100 p-3 text-sm text-slate-500 hover:border-gold-400">
              <Paperclip className="size-4" />{file?.name ?? task.submission?.file_name ?? `${t('portal.attachFile')} (${task.submission_types.filter((s) => s !== 'text').join(', ').toUpperCase()} · ≤ ${task.max_file_mb}MB)`}
              <input type="file" className="hidden" accept={accept} onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
            </label>
          )}
          {error && <p className="text-sm text-danger">{error}</p>}
          <Button variant="gold" size="sm" icon={<Upload className="size-4" />} loading={loading} disabled={!text && !file} onClick={submit}>{t('portal.submitTask')}</Button>
        </div>
      )}
    </Card>
  )
}
