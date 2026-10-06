/* eslint-disable @typescript-eslint/no-explicit-any */
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Field, Spinner, Tabs } from '@/components/ui'
import { useFeature } from '@/hooks/useFeature'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import SpacePanel from './SpacePanel'
import StarRating from './StarRating'

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
      {tab === 'rate' && <Rate lessonId={lessonId} />}
      {tab === 'notes' && <Notes lessonId={lessonId} />}
      {tab === 'ask' && <Ask lessonId={lessonId} programId={programId} />}
      {tab === 'discuss' && <Discuss lessonId={lessonId} />}
    </Card>
  )
}

function Rate({ lessonId }: { lessonId: string }) {
  const { t } = useTranslation()
  const res = useGet<{ data: any }>(`/social/ratings/lesson/${lessonId}`, undefined, { staleTime: 0 })
  const [stars, setStars] = useState<number | null>(null)
  const [review, setReview] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  if (res.isLoading) return <Spinner />
  const d = res.data?.data
  const cur = stars ?? d?.mine?.stars ?? 0
  const submit = async () => {
    setBusy(true)
    try { await api.post('/social/ratings', { subject_type: 'lesson', subject_id: lessonId, stars: cur, review: (review ?? d?.mine?.review) || undefined }); toast(String(t('soc.lesson.ratingSaved'))); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-3">
        <StarRating value={d?.average ?? 0} size={22} />
        <span className="text-sm text-slate-500">{d?.count ? `${t('soc.lesson.average')}: ${d.average} · ${t('soc.lesson.ratings', { n: d.count })}` : t('soc.lesson.noRatings')}</span>
      </div>
      <div className="flex flex-wrap items-start gap-3 border-t border-navy-50 pt-3">
        <StarRating value={cur} onChange={setStars} size={26} />
        <input className="input min-w-52 flex-1" value={review ?? d?.mine?.review ?? ''} onChange={(e) => setReview(e.target.value)} placeholder={String(t('soc.lesson.yourReview'))} maxLength={1000} />
        <Button variant="gold" loading={busy} disabled={!cur} onClick={submit}>{t('soc.common.save')}</Button>
      </div>
      {(d?.reviews?.length ?? 0) > 0 && (
        <ul className="space-y-2 border-t border-navy-50 pt-3">
          {d.reviews.map((r: any) => <li key={r.id} className="text-sm"><StarRating value={r.stars} size={14} /> <span className="ms-2 text-xs text-slate-400">{r.author}</span><p className="text-ink">{r.review}</p></li>)}
        </ul>
      )}
    </div>
  )
}

function Notes({ lessonId }: { lessonId: string }) {
  const { t } = useTranslation()
  const res = useGet<{ data: any[] }>('/social/lesson-notes', { lesson_id: lessonId }, { staleTime: 0 })
  const [body, setBody] = useState('')
  const [busy, setBusy] = useState(false)
  const add = async () => {
    setBusy(true)
    try { await api.post('/social/lesson-notes', { lesson_id: lessonId, body }); setBody(''); toast(String(t('soc.lesson.noteSaved'))); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const del = async (id: string) => { try { await api.delete(`/social/lesson-notes/${id}`); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <div className="space-y-3">
      <p className="text-xs text-slate-500">{t('soc.lesson.notesHint')}</p>
      <div className="flex items-start gap-2"><textarea className="input min-h-20 flex-1" value={body} onChange={(e) => setBody(e.target.value)} maxLength={4000} /><Button variant="gold" loading={busy} disabled={!body.trim()} onClick={add}>{t('soc.lesson.addNote')}</Button></div>
      {res.isLoading ? <Spinner /> : (res.data?.data.length ?? 0) === 0 ? <p className="text-sm text-slate-400">{t('soc.lesson.noNotes')}</p> : (
        <ul className="space-y-2">{res.data!.data.map((n) => <li key={n.id} className="flex items-start justify-between gap-3 rounded-xl bg-gold-50/60 p-3 text-sm"><span className="whitespace-pre-line">{n.body}</span><button type="button" className="text-xs text-slate-400 hover:text-danger" onClick={() => del(n.id)}>{t('soc.common.delete')}</button></li>)}</ul>
      )}
    </div>
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
