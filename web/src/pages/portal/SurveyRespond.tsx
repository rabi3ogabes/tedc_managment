import { useCallback } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import EngineForm from '@/components/surveys/EngineForm'
import { Card, ErrorState, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api } from '@/lib/api'
import type { Answers, Question, Settings } from '@/lib/surveyEngine'

type Detail = {
  id: string; title: string; description_ar?: string | null; description_en?: string | null; privacy_mode: 'identified' | 'confidential' | 'anonymous'; is_open: boolean; version: number
  questions: Question[]; settings: Settings | null; answered: boolean; can_edit: boolean; submission: { answers: Answers } | null; draft: { answers: Answers; page: number } | null
}

/** A survey of the new engine addressed to the signed-in person: resume the draft, answer, edit while allowed. */
export default function SurveyRespond() {
  const { id } = useParams()
  const { i18n } = useTranslation()
  const navigate = useNavigate()
  const res = useGet<{ data: Detail }>(`/me/studio-surveys/${id}`, undefined, { staleTime: 0, retry: false })
  const onDraft = useCallback(async (answers: Answers, page: number) => api.put(`/me/studio-surveys/${id}/draft`, { answers, page }), [id])
  if (res.isLoading) return <Spinner />
  if (res.error || !res.data) return <ErrorState error={res.error} onRetry={() => res.refetch()} home="/portal/surveys" />
  const d = res.data.data
  const en = i18n.language === 'en'
  return (
    <Card padded={false} className="overflow-hidden">
      <div className="flex min-h-[80vh] flex-col">
        <EngineForm
          survey={{ title: d.title, description: en ? d.description_en || d.description_ar : d.description_ar, questions: d.questions, settings: d.settings, privacy_mode: d.privacy_mode, version: d.version }}
          initialAnswers={d.submission?.answers ?? (d.answered && d.privacy_mode === 'anonymous' ? {} : null)}
          draft={d.draft}
          closed={!d.is_open}
          canEdit={d.can_edit}
          onDraft={d.is_open && (!d.answered || d.can_edit) ? onDraft : undefined}
          onUpload={async (q, file) => { const fd = new FormData(); fd.append('question_id', q.id); fd.append('file', file); const { data } = await api.post(`/me/studio-surveys/${id}/files`, fd); return data.data }}
          onSubmit={async (answers, duration) => { const { data } = await api.post(`/me/studio-surveys/${id}`, { answers, duration_seconds: duration, source: 'web' }); res.refetch(); return data.message }}
          onClose={() => navigate('/portal/surveys')}
        />
      </div>
    </Card>
  )
}
