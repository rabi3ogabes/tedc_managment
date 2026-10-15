import { Plus, Sparkles } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Field, Modal } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import type { Question } from '@/lib/surveyEngine'
import { toast } from '@/lib/toast'

/** Question suggestions from the configured model (Gate 8): a goal in, normalised engine questions out; the author picks what to add. */
export default function SuggestDialog({ open, onClose, surveyId, onAdd }: { open: boolean; onClose: () => void; surveyId: string; onAdd: (qs: Question[]) => void }) {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const [goal, setGoal] = useState('')
  const [busy, setBusy] = useState(false)
  const [items, setItems] = useState<Question[]>([])
  const [picked, setPicked] = useState<string[]>([])
  const ask = async () => {
    setBusy(true)
    try { const { data } = await api.post(`/admin/surveys/${surveyId}/ai/suggestions`, { goal }); const qs: Question[] = data.data.payload.questions ?? []; setItems(qs); setPicked(qs.map((q) => q.id)) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <Modal open={open} onClose={onClose} title={t('ss.ai.suggestTitle')} wide>
      <p className="mb-3 text-sm text-slate-600">{t('ss.ai.suggestHint')}</p>
      <Field label={t('ss.ai.goal')}><textarea className="input min-h-20" value={goal} onChange={(e) => setGoal(e.target.value)} placeholder={String(t('ss.ai.goalPlaceholder'))} /></Field>
      <div className="mt-3 flex gap-2"><Button variant="gold" icon={<Sparkles className="size-4" />} loading={busy} disabled={goal.trim().length < 5} onClick={ask}>{t('ss.ai.suggest')}</Button></div>
      {!!items.length && (
        <ul className="mt-4 space-y-2">{items.map((q) => (
          <li key={q.id} className={`flex items-start gap-3 rounded-2xl border p-3 ${picked.includes(q.id) ? 'border-gold-400 bg-gold-50' : 'border-slate-200'}`}>
            <input type="checkbox" className="mt-1 size-4 accent-gold-600" checked={picked.includes(q.id)} onChange={() => setPicked((p) => (p.includes(q.id) ? p.filter((x) => x !== q.id) : [...p, q.id]))} />
            <div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><span className="font-semibold text-navy-900">{en && q.title_en ? q.title_en : q.title}</span><Badge color="gray">{t(`ss.types.${q.type}`, { defaultValue: q.type })}</Badge>{q.required && <Badge color="amber">{t('ss.studio.required')}</Badge>}</div>
              {!!q.options?.length && <div className="mt-1 text-xs text-slate-500">{q.options.map((o) => o.label).join(' · ')}</div>}
              {!!q.rows?.length && <div className="mt-1 text-xs text-slate-500">{q.rows.map((r) => r.label).join(' · ')}</div>}</div>
          </li>))}</ul>
      )}
      <div className="mt-4 flex justify-end gap-2"><Button variant="outline" onClick={onClose}>{t('common.close')}</Button>{!!items.length && <Button variant="gold" icon={<Plus className="size-4" />} disabled={!picked.length} onClick={() => { onAdd(items.filter((q) => picked.includes(q.id))); toast(String(t('ss.ai.added', { n: picked.length }))); onClose() }}>{t('ss.ai.addPicked', { n: picked.length })}</Button>}</div>
    </Modal>
  )
}
