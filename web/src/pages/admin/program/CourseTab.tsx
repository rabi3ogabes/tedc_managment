import clsx from 'clsx'
import { BarChart3, BookOpenText, ChevronDown, ChevronUp, ClipboardList, FileText, GripVertical, Layers, ListChecks, Pencil, Plus, Presentation, Rocket, Trash2, Video } from 'lucide-react'
import { useState, type ComponentType } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { Program } from '@/lib/types'
import { fmtDuration, type Course, type Lesson, type LessonType, type Module } from './course/api'
import CourseAnalytics from './course/CourseAnalytics'
import LessonEditor from './course/LessonEditor'

const ICONS: Record<LessonType, ComponentType<{ className?: string }>> = { video: Video, presentation: Presentation, quiz: ListChecks, survey: ClipboardList, article: FileText }
const TYPES: LessonType[] = ['video', 'presentation', 'quiz', 'survey', 'article']

/** The online course of a program: build modules and lessons (video, slides, quizzes, surveys, articles) and follow learning. */
export default function CourseTab({ program }: { program: Program }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const manage = can('programs.manage')
  const { data, isLoading, refetch } = useGet<{ data: Course }>(`/admin/programs/${program.id}/course`, undefined, { staleTime: 0 })
  const [view, setView] = useState<'build' | 'analytics'>('build')
  const [selected, setSelected] = useState<string | null>(null)
  const [menu, setMenu] = useState<string | null>(null)
  const [moduleForm, setModuleForm] = useState<{ id?: string; title_ar: string; title_en: string } | null>(null)
  const [drag, setDrag] = useState<{ lesson: string } | null>(null)
  const [over, setOver] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (isLoading || !data) return <Spinner />
  const course = data.data
  const lessons = course.modules.flatMap((m) => m.lessons)
  const current = lessons.find((l) => l.id === selected) ?? null

  const run = async (fn: () => Promise<unknown>) => {
    setBusy(true)
    setError(null)
    try { await fn(); await refetch() } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }
  const saveModule = () => moduleForm && run(async () => {
    if (moduleForm.id) await api.put(`/admin/course/modules/${moduleForm.id}`, { title_ar: moduleForm.title_ar, title_en: moduleForm.title_en })
    else await api.post(`/admin/programs/${program.id}/course/modules`, { title_ar: moduleForm.title_ar, title_en: moduleForm.title_en })
    setModuleForm(null)
  })
  const addLesson = (m: Module, type: LessonType) => run(async () => {
    setMenu(null)
    const n = m.lessons.length + 1
    const res = await api.post<{ data: Lesson }>(`/admin/course/modules/${m.id}/lessons`, { type, title_ar: `${t(`course.defaultTitle.${type}`, { lng: 'ar' })} ${n}`, title_en: `${t(`course.defaultTitle.${type}`, { lng: 'en' })} ${n}` })
    setSelected(res.data.data.id)
  })
  const removeModule = (m: Module) => window.confirm(t('course.confirmDeleteModule', { name: m.title_ar })) && run(async () => { await api.delete(`/admin/course/modules/${m.id}`); if (m.lessons.some((l) => l.id === selected)) setSelected(null) })

  const persist = (modules: Module[]) => run(() => api.put(`/admin/programs/${program.id}/course/reorder`, { modules: modules.map((m) => ({ id: m.id, lessons: m.lessons.map((l) => l.id) })) }))
  const moveModule = (i: number, d: -1 | 1) => { const next = [...course.modules]; const j = i + d; if (j < 0 || j >= next.length) return; [next[i], next[j]] = [next[j], next[i]]; void persist(next) }
  /** Drops the dragged lesson before `before` (or at the end of the module). */
  const drop = (moduleId: string, before?: string) => {
    if (!drag || drag.lesson === before) return setDrag(null)
    const stripped = course.modules.map((m) => ({ ...m, lessons: m.lessons.filter((l) => l.id !== drag.lesson) }))
    const moved = lessons.find((l) => l.id === drag.lesson)
    if (!moved) return setDrag(null)
    const next = stripped.map((m) => {
      if (m.id !== moduleId) return m
      const at = before ? m.lessons.findIndex((l) => l.id === before) : m.lessons.length
      const list = [...m.lessons]
      list.splice(at < 0 ? list.length : at, 0, moved)
      return { ...m, lessons: list }
    })
    setDrag(null)
    setOver(null)
    void persist(next)
  }
  const setting = (patch: Record<string, unknown>) => run(() => api.put(`/admin/programs/${program.id}/course/settings`, patch))

  const quickStart = () => run(async () => {
    const mod = (await api.post<{ data: Module }>(`/admin/programs/${program.id}/course/modules`, { title_ar: 'مقدمة البرنامج', title_en: 'Introduction' })).data.data
    for (const type of ['video', 'presentation', 'quiz', 'survey'] as LessonType[]) {
      await api.post(`/admin/course/modules/${mod.id}/lessons`, { type, title_ar: t(`course.defaultTitle.${type}`, { lng: 'ar' }), title_en: t(`course.defaultTitle.${type}`, { lng: 'en' }) })
    }
  })

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex gap-1 rounded-2xl border border-navy-100 bg-white p-1">
          {([['build', Layers], ['analytics', BarChart3]] as const).map(([id, Icon]) => <button key={id} type="button" onClick={() => setView(id)} className={clsx('inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold transition', view === id ? 'bg-navy-900 text-white shadow' : 'text-slate-600 hover:bg-ivory')}><Icon className="size-4" />{t(`course.view.${id}`)}</button>)}
        </div>
        <div className="flex flex-wrap items-center gap-2 text-sm text-slate-600">
          <Badge color="navy">{t('course.totals.lessons', { count: course.totals.lessons })}</Badge>
          <Badge color="green">{t('course.totals.published', { count: course.totals.published })}</Badge>
          {course.totals.video_seconds > 0 && <Badge color="gold">{fmtDuration(course.totals.video_seconds)} {t('course.totals.video')}</Badge>}
        </div>
      </div>

      {view === 'analytics' ? <CourseAnalytics programId={program.id} /> : (
        <>
          {manage && (
            <Card className="grid gap-4 md:grid-cols-[1fr_1fr_12rem] md:items-center">
              <button type="button" role="switch" aria-checked={course.settings.sequential} onClick={() => setting({ course_sequential: !course.settings.sequential })} className="flex items-start gap-3 text-start">
                <span className={clsx('mt-0.5 inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition', course.settings.sequential ? 'bg-emerald-500' : 'bg-slate-300')}><span className={clsx('size-4 rounded-full bg-white shadow transition', course.settings.sequential && 'ltr:translate-x-4 rtl:-translate-x-4')} /></span>
                <span><span className="block text-sm font-semibold text-navy-900">{t('course.sequential')}</span><span className="block text-xs text-slate-500">{t('course.sequentialHint')}</span></span>
              </button>
              <button type="button" role="switch" aria-checked={course.settings.auto_certificate} onClick={() => setting({ course_auto_certificate: !course.settings.auto_certificate })} className="flex items-start gap-3 text-start">
                <span className={clsx('mt-0.5 inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition', course.settings.auto_certificate ? 'bg-emerald-500' : 'bg-slate-300')}><span className={clsx('size-4 rounded-full bg-white shadow transition', course.settings.auto_certificate && 'ltr:translate-x-4 rtl:-translate-x-4')} /></span>
                <span><span className="block text-sm font-semibold text-navy-900">{t('course.autoCert')}</span><span className="block text-xs text-slate-500">{t('course.autoCertHint')}</span></span>
              </button>
              <Field label={t('course.completionPercent')}><div className="flex items-center gap-2"><input type="number" min={1} max={100} dir="ltr" className="input !py-1.5 text-sm" defaultValue={course.settings.completion_percent} onBlur={(e) => Number(e.target.value) !== course.settings.completion_percent && setting({ course_completion_percent: Math.min(100, Math.max(1, Number(e.target.value))) })} /><span className="text-xs text-slate-500">%</span></div></Field>
            </Card>
          )}
          {error && <div className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}

          {course.modules.length === 0 ? (
            <Card className="py-10 text-center">
              <Empty icon={<BookOpenText className="size-8" />} text={t('course.empty')} />
              {manage && <div className="mt-4 flex flex-wrap justify-center gap-3"><Button variant="gold" icon={<Rocket className="size-4" />} loading={busy} onClick={quickStart}>{t('course.quickStart')}</Button><Button variant="outline" icon={<Plus className="size-4" />} onClick={() => setModuleForm({ title_ar: '', title_en: '' })}>{t('course.addModule')}</Button></div>}
              <p className="mt-3 text-xs text-slate-500">{t('course.quickStartHint')}</p>
            </Card>
          ) : (
            <div className="grid gap-5 lg:grid-cols-[20rem_minmax(0,1fr)]">
              <div className="space-y-3 lg:sticky lg:top-4 lg:self-start">
                {course.modules.map((m, mi) => (
                  <div key={m.id} className={clsx('overflow-hidden rounded-2xl border bg-white transition', over === m.id ? 'border-gold-500 ring-2 ring-gold-100' : 'border-navy-100')}
                    onDragOver={(e) => { if (drag) { e.preventDefault(); setOver(m.id) } }} onDragLeave={() => setOver(null)} onDrop={(e) => { e.preventDefault(); drop(m.id) }}>
                    <div className="flex items-center gap-1 bg-navy-900 px-3 py-2 text-white">
                      <span className="grid size-6 shrink-0 place-items-center rounded-full bg-gold-500 text-xs font-bold text-navy-950">{fmt.number(mi + 1)}</span>
                      <span className="min-w-0 flex-1 truncate ps-1 text-sm font-bold">{m.title_ar}</span>
                      {manage && <>
                        <button type="button" aria-label="up" disabled={mi === 0} onClick={() => moveModule(mi, -1)} className="rounded p-1 hover:bg-white/15 disabled:opacity-30"><ChevronUp className="size-4" /></button>
                        <button type="button" aria-label="down" disabled={mi === course.modules.length - 1} onClick={() => moveModule(mi, 1)} className="rounded p-1 hover:bg-white/15 disabled:opacity-30"><ChevronDown className="size-4" /></button>
                        <button type="button" aria-label="edit" onClick={() => setModuleForm({ id: m.id, title_ar: m.title_ar, title_en: m.title_en })} className="rounded p-1 hover:bg-white/15"><Pencil className="size-4" /></button>
                        <button type="button" aria-label="delete" onClick={() => removeModule(m)} className="rounded p-1 hover:bg-white/15"><Trash2 className="size-4" /></button>
                      </>}
                    </div>
                    <ul className="divide-y divide-navy-100/70">
                      {m.lessons.map((l) => {
                        const Icon = ICONS[l.type]
                        return (
                          <li key={l.id} draggable={manage} onDragStart={() => setDrag({ lesson: l.id })} onDragEnd={() => { setDrag(null); setOver(null) }} onDragOver={(e) => { if (drag) { e.preventDefault(); e.stopPropagation() } }} onDrop={(e) => { e.preventDefault(); e.stopPropagation(); drop(m.id, l.id) }}>
                            <button type="button" onClick={() => setSelected(l.id)} className={clsx('flex w-full items-center gap-2 px-3 py-2.5 text-start transition', l.id === selected ? 'bg-gold-100/60' : 'hover:bg-ivory', drag?.lesson === l.id && 'opacity-40')}>
                              {manage && <GripVertical className="size-4 shrink-0 cursor-grab text-slate-300" />}
                              <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-navy-100/70 text-navy-800"><Icon className="size-4" /></span>
                              <span className="min-w-0 flex-1"><span className="block truncate text-sm font-semibold text-navy-900">{l.title_ar}</span><span className="block text-[11px] text-slate-500">{t(`course.types.${l.type}`)}{l.type === 'video' && l.duration_seconds > 0 && ` · ${fmtDuration(l.duration_seconds)}`}{l.type === 'quiz' && ` · ${l.questions.length} ?`}{!l.is_required && ` · ${t('course.optional')}`}</span></span>
                              <span className={clsx('size-2.5 shrink-0 rounded-full', l.status === 'published' ? 'bg-emerald-500' : 'bg-slate-300')} title={t(l.status === 'published' ? 'course.published' : 'course.draft')} />
                            </button>
                          </li>
                        )
                      })}
                    </ul>
                    {manage && (
                      <div className="relative border-t border-navy-100 p-2">
                        <Button variant="ghost" size="sm" className="w-full" icon={<Plus className="size-4" />} onClick={() => setMenu(menu === m.id ? null : m.id)}>{t('course.addLesson')}</Button>
                        {menu === m.id && (
                          <div className="absolute inset-x-2 bottom-12 z-20 grid grid-cols-1 overflow-hidden rounded-xl border border-navy-100 bg-white shadow-glass">
                            {TYPES.map((type) => { const Icon = ICONS[type]; return <button key={type} type="button" onClick={() => addLesson(m, type)} className="flex items-center gap-3 px-3 py-2.5 text-start text-sm hover:bg-ivory"><Icon className="size-4 text-gold-600" /><span><span className="block font-semibold text-navy-900">{t(`course.types.${type}`)}</span><span className="block text-[11px] text-slate-500">{t(`course.typeHint.${type}`)}</span></span></button> })}
                          </div>
                        )}
                      </div>
                    )}
                  </div>
                ))}
                {manage && <Button variant="outline" className="w-full" icon={<Plus className="size-4" />} onClick={() => setModuleForm({ title_ar: '', title_en: '' })}>{t('course.addModule')}</Button>}
              </div>

              <div className="min-w-0">
                {current ? <LessonEditor key={current.id} lesson={current} onChanged={() => void refetch()} onDeleted={() => { setSelected(null); void refetch() }} />
                  : <Card className="grid min-h-[16rem] place-items-center text-center"><div><Layers className="mx-auto size-10 text-slate-300" /><p className="mt-3 font-semibold text-navy-900">{t('course.selectLesson')}</p><p className="text-sm text-slate-500">{t('course.selectLessonHint')}</p></div></Card>}
              </div>
            </div>
          )}
        </>
      )}

      <Modal open={!!moduleForm} onClose={() => setModuleForm(null)} title={t(moduleForm?.id ? 'course.editModule' : 'course.addModule')}>
        {moduleForm && (
          <div className="space-y-4">
            <Field label={t('course.titleAr')}><input dir="rtl" className="input" autoFocus value={moduleForm.title_ar} onChange={(e) => setModuleForm({ ...moduleForm, title_ar: e.target.value })} /></Field>
            <Field label={t('course.titleEn')}><input dir="ltr" className="input" value={moduleForm.title_en} onChange={(e) => setModuleForm({ ...moduleForm, title_en: e.target.value })} /></Field>
            <div className="flex justify-end gap-2"><Button variant="ghost" onClick={() => setModuleForm(null)}>{t('common.cancel')}</Button><Button variant="gold" loading={busy} disabled={!moduleForm.title_ar.trim() || !moduleForm.title_en.trim()} onClick={saveModule}>{t('common.save')}</Button></div>
          </div>
        )}
      </Modal>
    </div>
  )
}
