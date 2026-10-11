import { Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Note = { id: string; body: string; created_at: string }

/** Private notes on a lesson or a material: only their author ever sees them (COL-02). */
export default function SubjectNotes({ type, id }: { type: 'lesson' | 'material'; id: string }) {
  const { t } = useTranslation()
  const res = useGet<{ data: Note[] }>('/social/notes', { subject_type: type, subject_id: id }, { staleTime: 0 })
  const [body, setBody] = useState('')
  const [busy, setBusy] = useState(false)
  const add = async () => {
    setBusy(true)
    try { await api.post('/social/notes', { subject_type: type, subject_id: id, body }); setBody(''); toast(String(t('soc.lesson.noteSaved'))); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const del = async (noteId: string) => { try { await api.delete(`/social/notes/${noteId}`); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-500">{t('soc.lesson.notesHint')}</p>
      <div className="flex flex-wrap items-start gap-2">
        <textarea className="input min-h-20 min-w-52 flex-1" value={body} onChange={(e) => setBody(e.target.value)} maxLength={4000} aria-label={String(t('soc.lesson.addNote'))} />
        <Button variant="gold" loading={busy} disabled={!body.trim()} onClick={add}>{t('soc.lesson.addNote')}</Button>
      </div>
      {res.isLoading ? <Spinner /> : (res.data?.data.length ?? 0) === 0 ? <p className="text-sm text-slate-400">{t('soc.lesson.noNotes')}</p> : (
        <ul className="space-y-2">
          {res.data!.data.map((n) => (
            <li key={n.id} className="flex items-start justify-between gap-3 rounded-xl bg-gold-50/60 p-3 text-sm">
              <span className="min-w-0"><span className="block whitespace-pre-line text-ink">{n.body}</span><span className="mt-1 block text-xs text-slate-400">{fmt.dateTime(n.created_at)}</span></span>
              <button type="button" className="shrink-0 rounded-lg p-1 text-slate-400 hover:bg-white hover:text-danger" onClick={() => void del(n.id)} aria-label={String(t('soc.common.delete'))}><Trash2 className="size-4" /></button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
