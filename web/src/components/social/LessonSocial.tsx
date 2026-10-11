/* eslint-disable @typescript-eslint/no-explicit-any */
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Field, Spinner, Tabs } from '@/components/ui'
import { useFeature } from '@/hooks/useFeature'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import SpacePanel from './SpacePanel'
import RatingSummary from './RatingSummary'
import SubjectNotes from './SubjectNotes'

type Tab = 'rate' | 'notes' | 'ask' | 'discuss'

/** Under a lesson: rate it, keep private notes, ask the trainer, and talk with the group. */
export default function LessonSocial({ lessonId, programId }: { lessonId: string; programId: string }) {
  const { t } = useTranslation()
  const forums = useFeature('forums')
  const [tab, setTab] = useState<Tab>('rate')
  const tabs: { id: Tab; label: string }[] = [{ id: 'rate', label: t('soc.lesson.rate') }, { id: 'notes', label: t('soc.lesson.notes') }, ...(forums ? [{ id: 'ask' as Tab, label: t('soc.lesson.ask') }, { id: 'discuss' as Tab, label: t('soc.forum.lesson') }] : [])]
  return (
    <Card>
      <Tabs<Tab> value={tab} onChange={setTab} tabs={tabs} />
      {tab === 'rate' && <RatingSummary type="lesson" id={lessonId} />}
      {tab === 'notes' && <SubjectNotes type="lesson" id={lessonId} />}
      {tab === 'ask' && <Ask lessonId={lessonId} programId={programId} />}
      {tab === 'discuss' && <Discuss lessonId={lessonId} />}
    </Card>
  )
}

function Ask({ lessonId, programId }: { lessonId: string; programId: string }) {
  const { t } = useTranslation()
  const [f, setF] = useState({ subject: '', body: '', visibility: 'private' })
  const [busy, setBusy] = useState(false)
  const send = async () => {
    setBusy(true)
    try {
      const esc = f.body.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>')
      await api.post('/social/questions', { program_id: programId, lesson_id: lessonId, subject: f.subject, body: `<p>${esc}</p>`, visibility: f.visibility })
      toast(String(t('soc.lesson.sent'))); setF({ subject: '', body: '', visibility: 'private' })
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-500">{t('soc.lesson.askHint')}</p>
      <Field label={t('soc.lesson.subject')}><input className="input" value={f.subject} onChange={(e) => setF({ ...f, subject: e.target.value })} maxLength={190} /></Field>
      <Field label={t('soc.lesson.question')}><textarea className="input min-h-24" value={f.body} onChange={(e) => setF({ ...f, body: e.target.value })} maxLength={6000} /></Field>
      <Field label={t('soc.lesson.visibility')}><select className="input" value={f.visibility} onChange={(e) => setF({ ...f, visibility: e.target.value })}>{['private', 'group'].map((v) => <option key={v} value={v}>{t(`soc.lesson.vis.${v}`)}</option>)}</select></Field>
      <div className="flex justify-end"><Button variant="gold" loading={busy} disabled={!f.subject.trim() || !f.body.trim()} onClick={send}>{t('soc.lesson.send')}</Button></div>
    </div>
  )
}

function Discuss({ lessonId }: { lessonId: string }) {
  const res = useGet<{ data: any }>('/social/spaces/resolve', { type: 'lesson', id: lessonId }, { staleTime: 0, retry: false })
  if (res.isLoading) return <Spinner />
  if (!res.data) return null
  return <SpacePanel space={res.data.data} onChanged={() => res.refetch()} compact />
}
