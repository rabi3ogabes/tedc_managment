import clsx from 'clsx'
import * as pdfjs from 'pdfjs-dist'
import { FileUp, Link2, Save, Trash2, Video } from 'lucide-react'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Field } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { fmtDuration, fmtSize, uploadLessonFile, videoDuration, type Lesson, type LessonSettings } from './api'
import InteractionsEditor from './InteractionsEditor'
import { LtiCard, PackageCard, VersionsCard } from './PackageCards'
import { QuizEditor, SurveyEditor } from './QuestionEditors'

pdfjs.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.min.mjs', import.meta.url).toString()

const VIDEO_ACCEPT = 'video/mp4,video/webm,video/quicktime,video/x-m4v,video/ogg'
const SLIDES_ACCEPT = 'application/pdf,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/vnd.ms-powerpoint,.pptx,.ppt,.pdf'

function Toggle({ label, hint, value, onChange }: { label: ReactNode; hint?: ReactNode; value: boolean; onChange: (v: boolean) => void }) {
  return (
    <button type="button" role="switch" aria-checked={value} onClick={() => onChange(!value)} className="flex w-full items-start gap-3 rounded-xl border border-navy-100 bg-white p-3 text-start transition hover:border-gold-300">
      <span className={clsx('mt-0.5 inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition', value ? 'bg-emerald-500' : 'bg-slate-300')}><span className={clsx('size-4 rounded-full bg-white shadow transition', value ? 'ltr:translate-x-4 rtl:-translate-x-4' : '')} /></span>
      <span><span className="block text-sm font-semibold text-navy-900">{label}</span>{hint && <span className="block text-xs text-slate-500">{hint}</span>}</span>
    </button>
  )
}

function Num({ label, value, onChange, min, max, step = 1, suffix }: { label: ReactNode; value: number | null | undefined; onChange: (v: number | null) => void; min?: number; max?: number; step?: number; suffix?: string }) {
  return (
    <Field label={label}>
      <div className="flex items-center gap-2"><input type="number" dir="ltr" className="input !py-1.5 text-sm" min={min} max={max} step={step} value={value ?? ''} onChange={(e) => onChange(e.target.value === '' ? null : Number(e.target.value))} />{suffix && <span className="text-xs text-slate-500">{suffix}</span>}</div>
    </Field>
  )
}

/** Drop zone / button that uploads a lesson's video or slides straight to storage, with progress. */
function MediaUpload({ lesson, accept, onDone }: { lesson: Lesson; accept: string; onDone: () => void }) {
  const { t } = useTranslation()
  const input = useRef<HTMLInputElement>(null)
  const [progress, setProgress] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [over, setOver] = useState(false)
  const [url, setUrl] = useState('')
  const [linkBusy, setLinkBusy] = useState(false)

  const send = async (file: File) => {
    setError(null)
    setProgress(0)
    try {
      const extra: { duration_seconds?: number; slide_count?: number } = {}
      if (lesson.type === 'video') extra.duration_seconds = await videoDuration(file)
      if (lesson.type === 'presentation' && file.type === 'application/pdf') {
        const task = pdfjs.getDocument({ data: await file.arrayBuffer() })
        extra.slide_count = (await task.promise).numPages
        void task.destroy()
      }
      await uploadLessonFile(lesson.id, file, extra, setProgress)
      onDone()
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setProgress(null)
    }
  }

  const link = async () => {
    setLinkBusy(true)
    setError(null)
    try {
      await api.put(`/admin/course/lessons/${lesson.id}/link`, { url: url.trim() })
      setUrl('')
      onDone()
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setLinkBusy(false)
    }
  }

  return (
    <div className="space-y-3">
      <input ref={input} type="file" hidden accept={accept} onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) void send(f) }} />
      <div
        onDragOver={(e) => { e.preventDefault(); setOver(true) }} onDragLeave={() => setOver(false)}
        onDrop={(e) => { e.preventDefault(); setOver(false); const f = e.dataTransfer.files?.[0]; if (f) void send(f) }}
        className={clsx('rounded-2xl border-2 border-dashed p-8 text-center transition', over ? 'border-gold-500 bg-gold-100/40' : 'border-navy-200 bg-ivory/60')}
      >
        {progress === null ? (
          <>
            <FileUp className="mx-auto size-9 text-gold-600" />
            <p className="mt-2 font-semibold text-navy-900">{t(lesson.type === 'video' ? 'course.media.dropVideo' : 'course.media.dropSlides')}</p>
            <p className="text-xs text-slate-500">{t(lesson.type === 'video' ? 'course.media.videoTypes' : 'course.media.slideTypes')}</p>
            <Button className="mt-4" variant="gold" icon={<FileUp className="size-4" />} onClick={() => input.current?.click()}>{t('course.media.choose')}</Button>
          </>
        ) : (
          <div className="mx-auto max-w-sm">
            <p className="mb-2 text-sm font-semibold text-navy-900">{t('course.media.uploading')} {progress}%</p>
            <div className="h-2.5 overflow-hidden rounded-full bg-navy-100"><div className="h-full rounded-full bg-gradient-to-r from-gold-500 to-gold-300 transition-all" style={{ width: `${progress}%` }} /></div>
            <p className="mt-2 text-xs text-slate-500">{t('course.media.keepOpen')}</p>
          </div>
        )}
      </div>
      <div className="flex items-end gap-2">
        <Field label={<span className="inline-flex items-center gap-1.5"><Link2 className="size-3.5" />{t('course.media.orLink')}</span>} className="flex-1"><input dir="ltr" type="url" placeholder="https://youtube.com/watch?v=…" className="input !py-1.5 text-sm" value={url} onChange={(e) => setUrl(e.target.value)} /></Field>
        <Button variant="outline" loading={linkBusy} disabled={!/^https?:\/\/\S+$/i.test(url.trim())} onClick={link}>{t('course.media.useLink')}</Button>
      </div>
      {error && <p className="rounded-lg bg-red-50 p-2 text-sm text-danger">{error}</p>}
    </div>
  )
}

/** Edits one lesson: its texts, media, watch / quiz rules and questions. */
export default function LessonEditor({ lesson, onChanged, onDeleted }: { lesson: Lesson; onChanged: () => void; onDeleted: () => void }) {
  const { t } = useTranslation()
  const [draft, setDraft] = useState(lesson)
  const [busy, setBusy] = useState<string | null>(null)
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null)
  const EDITABLE = ['title_ar', 'title_en', 'description_ar', 'description_en', 'body_ar', 'body_en', 'is_required', 'settings', 'duration_seconds'] as const
  const dirty = EDITABLE.some((k) => JSON.stringify(draft[k]) !== JSON.stringify(lesson[k]))
  // Values the server changes (an upload measures the duration, publishing changes the status) flow into the draft.
  useEffect(() => { setDraft((d) => ({ ...d, duration_seconds: lesson.duration_seconds, status: lesson.status })) }, [lesson.duration_seconds, lesson.status])
  const set = <K extends keyof Lesson>(k: K, v: Lesson[K]) => setDraft({ ...draft, [k]: v })
  const rule = <K extends keyof LessonSettings>(k: K, v: LessonSettings[K]) => setDraft({ ...draft, settings: { ...draft.settings, [k]: v } })
  const s = draft.settings

  const save = async (patch: Partial<Lesson> = {}) => {
    setBusy('save')
    setMessage(null)
    try {
      const body = { title_ar: draft.title_ar, title_en: draft.title_en, description_ar: draft.description_ar, description_en: draft.description_en, body_ar: draft.body_ar, body_en: draft.body_en, is_required: draft.is_required, status: draft.status, duration_seconds: draft.duration_seconds, settings: draft.settings, ...patch }
      await api.put(`/admin/course/lessons/${lesson.id}`, body)
      setMessage({ ok: true, text: t('course.saved') })
      onChanged()
    } catch (e) {
      setMessage({ ok: false, text: errorMessage(e) })
    } finally {
      setBusy(null)
    }
  }
  const publish = (status: 'draft' | 'published') => { set('status', status); void save({ status }) }
  const removeFile = async () => {
    setBusy('file')
    try { await api.delete(`/admin/course/lessons/${lesson.id}/file`); onChanged() } catch (e) { setMessage({ ok: false, text: errorMessage(e) }) } finally { setBusy(null) }
  }
  const remove = async () => {
    if (!window.confirm(t('course.confirmDeleteLesson', { name: lesson.title_ar }))) return
    setBusy('delete')
    try { await api.delete(`/admin/course/lessons/${lesson.id}`); onDeleted() } catch (e) { setMessage({ ok: false, text: errorMessage(e) }); setBusy(null) }
  }

  const hasMedia = lesson.has_file
  const ready = lesson.type === 'quiz' ? lesson.questions.length > 0 : lesson.type === 'survey' ? lesson.survey_questions.length > 0 : lesson.type === 'article' ? !!(lesson.body_ar || lesson.body_en) : lesson.type === 'package' ? !!lesson.package_id : lesson.type === 'lti' ? !!lesson.lti_tool_id : hasMedia

  return (
    <div className="space-y-5">
      <Card className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-2"><Badge color="gold">{t(`course.types.${lesson.type}`)}</Badge>{lesson.status === 'published' ? <Badge color="green">{t('course.published')}</Badge> : <Badge color="gray">{t('course.draft')}</Badge>}</div>
          <div className="flex items-center gap-2">
            {lesson.status === 'published'
              ? <Button variant="outline" size="sm" loading={busy === 'save'} onClick={() => publish('draft')}>{t('course.unpublish')}</Button>
              : <Button variant="primary" size="sm" disabled={!ready} loading={busy === 'save'} onClick={() => publish('published')}>{t('course.publish')}</Button>}
            <Button variant="ghost" size="sm" aria-label={t('common.delete')} loading={busy === 'delete'} icon={<Trash2 className="size-4 text-danger" />} onClick={remove} />
          </div>
        </div>
        {!ready && lesson.status !== 'published' && <p className="rounded-lg bg-amber-50 p-2 text-xs text-amber-900">{t(`course.notReady.${lesson.type}`)}</p>}
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label={t('course.titleAr')}><input dir="rtl" className="input" value={draft.title_ar} onChange={(e) => set('title_ar', e.target.value)} /></Field>
          <Field label={t('course.titleEn')}><input dir="ltr" className="input" value={draft.title_en} onChange={(e) => set('title_en', e.target.value)} /></Field>
          <Field label={t('course.descAr')}><textarea rows={2} dir="rtl" className="input text-sm" value={draft.description_ar ?? ''} onChange={(e) => set('description_ar', e.target.value)} /></Field>
          <Field label={t('course.descEn')}><textarea rows={2} dir="ltr" className="input text-sm" value={draft.description_en ?? ''} onChange={(e) => set('description_en', e.target.value)} /></Field>
        </div>
        <Toggle label={t('course.required')} hint={t('course.requiredHint')} value={draft.is_required} onChange={(v) => set('is_required', v)} />
      </Card>

      {lesson.type === 'video' && (
        <>
          <Card className="space-y-4">
            <h3 className="flex items-center gap-2 font-bold text-navy-900"><Video className="size-5 text-gold-600" />{t('course.media.video')}</h3>
            {hasMedia ? (
              <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-ivory p-3">
                <div className="min-w-0"><div className="truncate font-semibold text-navy-900" dir="ltr">{lesson.file_name ?? lesson.external_url}</div><div className="text-xs text-slate-500">{lesson.source === 'upload' ? fmtSize(lesson.file_size) : t('course.media.link')} · {lesson.duration_seconds ? fmtDuration(lesson.duration_seconds) : t('course.media.noDuration')}</div></div>
                <Button variant="outline" size="sm" loading={busy === 'file'} icon={<Trash2 className="size-4" />} onClick={removeFile}>{t('course.media.replace')}</Button>
              </div>
            ) : <MediaUpload lesson={lesson} accept={VIDEO_ACCEPT} onDone={onChanged} />}
            {hasMedia && lesson.source === 'url' && <Num label={t('course.media.durationSeconds')} value={draft.duration_seconds} min={0} onChange={(v) => set('duration_seconds', v ?? 0)} suffix={draft.duration_seconds ? fmtDuration(draft.duration_seconds) : ''} />}
            {hasMedia && lesson.source === 'url' && <p className="text-xs text-slate-500">{t('course.media.linkTracking')}</p>}
          </Card>
          <Card className="space-y-3">
            <h3 className="font-bold text-navy-900">{t('course.rules.videoTitle')}</h3>
            <p className="text-xs text-slate-500">{t('course.rules.videoHint')}</p>
            <div className="grid gap-3 sm:grid-cols-2">
              <Toggle label={t('course.rules.allowSeeking')} hint={t('course.rules.allowSeekingHint')} value={s.allow_seeking ?? true} onChange={(v) => rule('allow_seeking', v)} />
              <Toggle label={t('course.rules.pauseHidden')} hint={t('course.rules.pauseHiddenHint')} value={s.pause_when_hidden ?? true} onChange={(v) => rule('pause_when_hidden', v)} />
              <Num label={t('course.rules.minWatch')} value={s.min_watch_percent ?? 90} min={1} max={100} suffix="%" onChange={(v) => rule('min_watch_percent', v ?? 90)} />
              <Num label={t('course.rules.maxSpeed')} value={s.max_speed ?? 2} min={1} max={3} step={0.25} suffix="×" onChange={(v) => rule('max_speed', v ?? 2)} />
              <Toggle label={t('assess.video.requireVisible')} value={s.require_visible ?? true} onChange={(v) => rule('require_visible', v)} />
              <Toggle label={t('assess.video.requireFullscreen')} value={s.require_fullscreen ?? false} onChange={(v) => rule('require_fullscreen', v)} />
              <Num label={t('assess.video.lockPause')} value={s.lock_pause ?? null} min={0} max={50} onChange={(v) => rule('lock_pause', v)} />
            </div>
          </Card>
          {lesson.id && <InteractionsEditor lessonId={lesson.id} />}
        </>
      )}

      {lesson.type === 'presentation' && (
        <>
          <Card className="space-y-4">
            <h3 className="font-bold text-navy-900">{t('course.media.slides')}</h3>
            {hasMedia ? (
              <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-ivory p-3">
                <div className="min-w-0"><div className="truncate font-semibold text-navy-900" dir="ltr">{lesson.file_name ?? lesson.external_url}</div><div className="text-xs text-slate-500">{lesson.source === 'upload' ? fmtSize(lesson.file_size) : t('course.media.link')}{lesson.slide_count > 0 && ` · ${t('course.media.slideCount', { count: lesson.slide_count })}`}</div></div>
                <Button variant="outline" size="sm" loading={busy === 'file'} icon={<Trash2 className="size-4" />} onClick={removeFile}>{t('course.media.replace')}</Button>
              </div>
            ) : <MediaUpload lesson={lesson} accept={SLIDES_ACCEPT} onDone={onChanged} />}
            {hasMedia && lesson.file_mime !== 'application/pdf' && lesson.source === 'upload' && <p className="rounded-lg bg-amber-50 p-2 text-xs text-amber-900">{t('course.media.pptxNote')}</p>}
          </Card>
          <Card className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-2">
              <Num label={t('course.rules.minView')} value={s.min_view_percent ?? 80} min={1} max={100} suffix="%" onChange={(v) => rule('min_view_percent', v ?? 80)} />
              <Toggle label={t('course.rules.downloadable')} hint={t('course.rules.downloadableHint')} value={s.downloadable ?? false} onChange={(v) => rule('downloadable', v)} />
            </div>
          </Card>
        </>
      )}

      {lesson.type === 'package' && <PackageCard lesson={lesson} onSaved={onChanged} />}
      {lesson.type === 'lti' && <LtiCard lesson={lesson} onSaved={onChanged} />}
      <VersionsCard lesson={lesson} onSaved={onChanged} />

      {lesson.type === 'article' && (
        <Card className="space-y-3">
          <h3 className="font-bold text-navy-900">{t('course.article.body')}</h3>
          <div className="grid gap-3 lg:grid-cols-2">
            <Field label={t('course.article.bodyAr')}><textarea rows={14} dir="rtl" className="input text-sm leading-7" value={draft.body_ar ?? ''} onChange={(e) => set('body_ar', e.target.value)} /></Field>
            <Field label={t('course.article.bodyEn')}><textarea rows={14} dir="ltr" className="input text-sm leading-7" value={draft.body_en ?? ''} onChange={(e) => set('body_en', e.target.value)} /></Field>
          </div>
          <p className="text-xs text-slate-500">{t('course.article.hint')}</p>
        </Card>
      )}

      {lesson.type === 'quiz' && (
        <>
          <Card className="space-y-3">
            <h3 className="font-bold text-navy-900">{t('course.rules.quizTitle')}</h3>
            <div className="grid gap-3 sm:grid-cols-3">
              <Num label={t('course.rules.pass')} value={s.pass_percent ?? 70} min={1} max={100} suffix="%" onChange={(v) => rule('pass_percent', v ?? 70)} />
              <Num label={t('course.rules.attempts')} value={s.max_attempts ?? null} min={1} max={50} onChange={(v) => rule('max_attempts', v)} suffix={s.max_attempts ? '' : t('course.rules.unlimited')} />
              <Num label={t('course.rules.timeLimit')} value={s.time_limit_minutes ?? null} min={1} max={300} onChange={(v) => rule('time_limit_minutes', v)} suffix={s.time_limit_minutes ? t('course.minutes') : t('course.rules.unlimited')} />
              <Field label={t('course.rules.showAnswers')} className="sm:col-span-3"><select className="input !py-1.5 text-sm" value={s.show_answers ?? 'after_submit'} onChange={(e) => rule('show_answers', e.target.value as LessonSettings['show_answers'])}>{(['after_submit', 'after_pass', 'never'] as const).map((k) => <option key={k} value={k}>{t(`course.rules.show.${k}`)}</option>)}</select></Field>
              <Toggle label={t('course.rules.shuffleQuestions')} value={s.shuffle_questions ?? true} onChange={(v) => rule('shuffle_questions', v)} />
              <Toggle label={t('course.rules.shuffleOptions')} value={s.shuffle_options ?? true} onChange={(v) => rule('shuffle_options', v)} />
            </div>
          </Card>
          <Card><QuizEditor key={lesson.id} lessonId={lesson.id} initial={lesson.questions} onSaved={onChanged} /></Card>
        </>
      )}

      {lesson.type === 'survey' && <Card><SurveyEditor key={lesson.id} lessonId={lesson.id} initial={lesson.survey_questions} onSaved={onChanged} /></Card>}

      <div className="sticky bottom-3 z-10 flex items-center justify-end gap-3 rounded-2xl border border-navy-100 bg-white/95 p-3 shadow-glass backdrop-blur">
        {message && <span className={clsx('me-auto text-sm', message.ok ? 'text-emerald-700' : 'text-danger')}>{message.text}</span>}
        <Button variant="gold" loading={busy === 'save'} disabled={!dirty} icon={<Save className="size-4" />} onClick={() => save()}>{dirty ? t('course.save') : t('course.savedShort')}</Button>
      </div>
    </div>
  )
}
