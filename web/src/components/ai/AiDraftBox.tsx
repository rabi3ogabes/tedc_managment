/* eslint-disable @typescript-eslint/no-explicit-any */
import { Bot } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useFeature } from '@/hooks/useFeature'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

/** The grader's side-by-side: a draft of feedback and a suggested score per essay. Nothing counts until the grader accepts or edits it. */
export default function AiDraftBox({ attemptId, onGraded }: { attemptId: string; onGraded?: () => void }) {
  const { t } = useTranslation()
  const on = useFeature('ai')
  const res = useGet<{ data: any[] }>(on ? `/admin/attempts/${attemptId}/ai-feedback` : null, undefined, { staleTime: 0, retry: false })
  const [edit, setEdit] = useState<Record<string, { score: string; comment: string }>>({})
  const [busy, setBusy] = useState<string | null>(null)
  const drafts = (res.data?.data ?? []).filter((d) => d.status === 'draft')
  if (!on || !res.data?.data.length) return null

  const act = async (d: any, action: 'accept' | 'edit' | 'reject') => {
    setBusy(d.id)
    try {
      await api.post(`/admin/ai-feedback/${d.id}/${action}`, action === 'edit' ? { score: Number(edit[d.id]?.score ?? d.suggested_score), comment: edit[d.id]?.comment ?? d.draft_text } : {})
      toast(String(t(action === 'reject' ? 'aix.draft.rejected' : 'aix.draft.saved')))
      res.refetch(); onGraded?.()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(null) }
  }

  return (
    <div className="space-y-3 rounded-2xl border border-gold-200 bg-gold-50/40 p-4">
      <div className="flex items-center gap-2 font-bold text-navy-900"><Bot className="size-5 text-gold-600" />{t('aix.draft.title')}</div>
      <p className="text-xs text-slate-500">{t('aix.draft.hint')}</p>
      {res.data!.data.map((d) => (
        <div key={d.id} className="space-y-2 rounded-xl bg-white p-3 text-sm">
          <div className="flex flex-wrap items-center gap-2"><Badge color={d.status === 'draft' ? 'amber' : d.status === 'rejected' ? 'gray' : 'green'}>{t(`aix.draft.status.${d.status}`)}</Badge><Badge color="blue">{t(`aix.draft.source.${d.source}`)}</Badge><b>{t('aix.draft.suggested')}: {d.suggested_score} / {d.max_points}</b></div>
          <p className="whitespace-pre-wrap text-slate-700" dir="auto">{d.draft_text}</p>
          {d.criteria?.length > 0 && <ul className="space-y-0.5 text-xs text-slate-500">{d.criteria.map((c: any) => <li key={c.id}>{c.met >= 1 ? '✓' : c.met > 0 ? '◐' : '✗'} {c.text} <span className="text-slate-400">({Math.round(c.met * c.points * 10) / 10}/{c.points})</span>{c.comment ? ` — ${c.comment}` : ''}</li>)}</ul>}
          {d.status === 'draft' && (
            <div className="flex flex-wrap items-end gap-2">
              <input type="number" className="input w-24" min={0} max={d.max_points} step="0.5" aria-label={String(t('aix.draft.score'))} value={edit[d.id]?.score ?? ''} placeholder={String(d.suggested_score)} onChange={(e) => setEdit({ ...edit, [d.id]: { score: e.target.value, comment: edit[d.id]?.comment ?? '' } })} />
              <input className="input min-w-48 flex-1" aria-label={String(t('aix.draft.comment'))} value={edit[d.id]?.comment ?? ''} placeholder={String(t('aix.draft.comment'))} onChange={(e) => setEdit({ ...edit, [d.id]: { score: edit[d.id]?.score ?? '', comment: e.target.value } })} />
              <Button size="sm" variant="gold" loading={busy === d.id} onClick={() => act(d, 'accept')}>{t('aix.draft.accept')}</Button>
              <Button size="sm" variant="outline" loading={busy === d.id} disabled={!(edit[d.id]?.score ?? '') && !(edit[d.id]?.comment ?? '')} onClick={() => act(d, 'edit')}>{t('aix.draft.edit')}</Button>
              <Button size="sm" variant="ghost" onClick={() => act(d, 'reject')}>{t('aix.draft.reject')}</Button>
            </div>
          )}
        </div>
      ))}
      {drafts.length === 0 && null}
    </div>
  )
}
