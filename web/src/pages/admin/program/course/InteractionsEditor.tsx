/* eslint-disable @typescript-eslint/no-explicit-any */
import { Plus, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Spinner } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

const input = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'

/** Questions, reflections and checkpoints placed at moments of a video. A blocking one stops the credited progress until it is answered. */
export default function InteractionsEditor({ lessonId }: { lessonId: string }) {
  const { t } = useTranslation()
  const [rows, setRows] = useState<any[] | null>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => { api.get(`/admin/course/lessons/${lessonId}/interactions`).then((r) => setRows(r.data.data)).catch(() => setRows([])) }, [lessonId])
  if (!rows) return <Spinner />
  const set = (i: number, patch: any) => setRows(rows.map((r, j) => (j === i ? { ...r, ...patch } : r)))
  const save = async () => {
    setBusy(true)
    try {
      const { data } = await api.put(`/admin/course/lessons/${lessonId}/interactions`, { interactions: rows.map((r) => ({ at_seconds: Number(r.at_seconds) || 0, type: r.type, question_id: r.question_id || null, prompt_ar: r.prompt_ar || null, prompt_en: r.prompt_en || null, required: !!r.required, blocks_progress: !!r.blocks_progress, require_correct: !!r.require_correct })) })
      setRows(data.data); toast(t('assess.video.saved'))
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const chk = (i: number, k: string, label: string) => <label className="flex items-center gap-2 text-xs"><input type="checkbox" checked={!!rows[i][k]} onChange={(e) => set(i, { [k]: e.target.checked })} />{label}</label>
  return (
    <Card className="space-y-3">
      <div className="flex items-center justify-between"><h3 className="font-bold text-navy-900">{t('assess.video.title')}</h3><Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => setRows([...rows, { at_seconds: 0, type: 'question', required: true, blocks_progress: true, require_correct: false }])}>{t('assess.video.add')}</Button></div>
      {rows.map((r, i) => (
        <div key={i} className="grid gap-2 rounded-xl border border-navy-100 p-3 sm:grid-cols-4">
          <label className="text-xs">{t('assess.video.at')}<input className={input} type="number" min="0" value={r.at_seconds} onChange={(e) => set(i, { at_seconds: e.target.value })} /></label>
          <label className="text-xs">{t('assess.video.type')}<select className={input} value={r.type} onChange={(e) => set(i, { type: e.target.value })}>{['question', 'reflection', 'note', 'checkpoint'].map((k) => <option key={k} value={k}>{t(`assess.video.types.${k}`)}</option>)}</select></label>
          <label className="text-xs sm:col-span-2">{t('assess.video.question')}<input dir="ltr" className={input} value={r.question_id ?? ''} onChange={(e) => set(i, { question_id: e.target.value })} /></label>
          <label className="text-xs sm:col-span-2">{t('assess.video.prompt')} (ع)<input className={input} value={r.prompt_ar ?? ''} onChange={(e) => set(i, { prompt_ar: e.target.value })} /></label>
          <label className="text-xs sm:col-span-2">{t('assess.video.prompt')} (EN)<input dir="ltr" className={input} value={r.prompt_en ?? ''} onChange={(e) => set(i, { prompt_en: e.target.value })} /></label>
          <div className="flex flex-wrap items-center gap-4 sm:col-span-4">{chk(i, 'required', t('assess.video.required'))}{chk(i, 'blocks_progress', t('assess.video.blocks'))}{chk(i, 'require_correct', t('assess.video.correct'))}<button type="button" aria-label="remove" className="ms-auto text-danger" onClick={() => setRows(rows.filter((_, j) => j !== i))}><Trash2 className="size-4" /></button></div>
        </div>))}
      <Button variant="gold" loading={busy} onClick={() => void save()}>{t('assess.video.save')}</Button>
    </Card>
  )
}
