import clsx from 'clsx'
import { AlertTriangle, ArrowLeft, ArrowRight, BookOpenCheck, Check, CheckCircle2, ClipboardList, FileEdit, GraduationCap, Layers, Plus, RefreshCcw, Rocket, Sparkles, Trash2, Users2, Video, Wand2 } from 'lucide-react'
import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Avatar, Badge, Button, Card, Empty, Field, PageHeader, Spinner, StatusBadge } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { Paginated, Program, Room, Trainer } from '@/lib/types'
import AudienceBuilder from './AudienceBuilder'
import { activeFilters } from './filters'
import ContentStep, { applyContentPlan, emptyPlan, type ContentPlan } from './ContentStep'
import { CertificatesStep, DeliveryStep, emptyRemote, SmartSlots, TargetCategories, type RemoteSettings } from './RemoteParts'
import type { Audience, AudiencePreview, BuilderOptions, Draft, NeedTopic, PlannedSession, TrainerSuggestion } from './types'

type Step = 'source' | 'audience' | 'details' | 'team' | 'delivery' | 'content' | 'certificates' | 'review'
const stepsFor = (remote: boolean): Step[] => (remote ? ['source', 'audience', 'details', 'team', 'delivery', 'content', 'certificates', 'review'] : ['source', 'audience', 'details', 'team', 'certificates', 'review'])
type Mode = 'needs' | 'manual'
type RoomChoice = { room: Room; available: boolean; fits_capacity: boolean; score: number }

const priorityTone = { critical: 'red', high: 'amber', medium: 'blue', low: 'gray' } as const
const splitAt = (s: string) => ({ date: s.slice(0, 10), time: s.slice(11, 16) })
const join = (date: string, time: string) => `${date} ${time}:00`

function Stepper({ steps, step, reached, onGo, remote }: { steps: Step[]; step: Step; reached: number; onGo: (s: Step) => void; remote: boolean }) {
  const { t } = useTranslation()
  const index = steps.indexOf(step)
  return (
    <ol className="mb-6 flex gap-2 overflow-x-auto rounded-2xl border border-navy-100 bg-white p-2">
      {steps.map((s, i) => {
        const done = i < index
        const active = i === index
        return (
          <li key={s} className="min-w-0 flex-1">
            <button type="button" disabled={i > reached} onClick={() => onGo(s)} aria-current={active ? 'step' : undefined}
              className={clsx('flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-start transition', active ? 'bg-navy-900 text-white shadow' : done ? 'text-navy-800 hover:bg-ivory' : 'text-slate-400', i > reached && 'cursor-not-allowed')}>
              <span className={clsx('grid size-7 shrink-0 place-items-center rounded-full text-xs font-bold', active ? 'bg-gold-500 text-navy-950' : done ? 'bg-emerald-500 text-white' : 'bg-slate-100 text-slate-500')}>{done ? <Check className="size-4" /> : fmt.number(i + 1)}</span>
              <span className="hidden truncate text-sm font-semibold sm:block">{remote && s === 'delivery' ? t('studio.steps.delivery') : s === 'content' ? t('studio.steps.content') : s === 'certificates' ? t('studio.steps.certificates') : t(`mgmt.wizard.steps.${s}`)}</span>
            </button>
          </li>
        )
      })}
    </ol>
  )
}

/** Step 1 - pick collected needs, or start from a topic and define the audience by hand. */
function SourceStep({ options, mode, setMode, selected, setSelected, skillId, setSkillId }: {
  options: BuilderOptions; mode: Mode; setMode: (m: Mode) => void; selected: string[]; setSelected: (s: string[]) => void; skillId: string; setSkillId: (s: string) => void
}) {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const toggle = (key: string) => setSelected(selected.includes(key) ? selected.filter((k) => k !== key) : [...selected, key])
  const picked = options.needs.filter((n) => selected.includes(n.key))

  return (
    <div className="space-y-6">
      <div className="grid gap-3 md:grid-cols-2">
        {([['needs', ClipboardList], ['manual', Wand2]] as const).map(([id, Icon]) => (
          <button key={id} type="button" aria-pressed={mode === id} onClick={() => setMode(id)} className={clsx('flex items-start gap-4 rounded-2xl border p-5 text-start transition', mode === id ? 'border-navy-900 bg-navy-900 text-white shadow-glass' : 'border-navy-100 bg-white hover:border-gold-400')}>
            <span className={clsx('grid size-12 shrink-0 place-items-center rounded-2xl', mode === id ? 'bg-gold-500 text-navy-950' : 'bg-navy-900 text-gold-300')}><Icon className="size-6" /></span>
            <span>
              <span className="block text-lg font-bold">{t(`mgmt.wizard.source.${id}`)}</span>
              <span className={clsx('mt-1 block text-sm', mode === id ? 'text-white/75' : 'text-slate-500')}>{t(`mgmt.wizard.source.${id}Text`)}</span>
              {id === 'needs' && <span className={clsx('mt-2 block text-xs font-semibold', mode === id ? 'text-gold-300' : 'text-gold-700')}>{fmt.number(options.totals.topics)} {t('mgmt.wizard.source.topics')} · {fmt.number(options.totals.employees)} {t('mgmt.wizard.source.employees')}{options.totals.critical > 0 && ` · ${fmt.number(options.totals.critical)} ${t('mgmt.wizard.source.critical')}`}</span>}
            </span>
          </button>
        ))}
      </div>

      {mode === 'manual' ? (
        <Card>
          <Field label={t('mgmt.wizard.source.pickSkill')} hint={t('mgmt.wizard.source.pickSkillHint')}>
            <select className="input" value={skillId} onChange={(e) => setSkillId(e.target.value)}>
              <option value="">—</option>
              {options.skills.map((s) => <option key={s.id} value={s.id}>{en ? s.name_en : s.name_ar}</option>)}
            </select>
          </Field>
        </Card>
      ) : options.needs.length === 0 ? <Empty text={t('mgmt.wizard.source.noNeeds')} /> : (
        <>
          <p className="text-sm text-slate-500">{t('mgmt.wizard.source.combine')}</p>
          <div className="grid gap-4 lg:grid-cols-2">
            {options.needs.map((n) => <NeedCard key={n.key} need={n} on={selected.includes(n.key)} onToggle={() => toggle(n.key)} />)}
          </div>
          {picked.length > 0 && <div className="rounded-xl bg-gold-100/40 px-4 py-2.5 text-sm font-semibold text-navy-900">{t('mgmt.wizard.source.selected')}: {picked.map((p) => p.skill).join('، ')} · {fmt.number(picked.reduce((a, p) => a + p.employees, 0))} {t('mgmt.wizard.source.employees')}</div>}
        </>
      )}
    </div>
  )
}

function NeedCard({ need, on, onToggle }: { need: NeedTopic; on: boolean; onToggle: () => void }) {
  const { t } = useTranslation()
  return (
    <article className={clsx('rounded-2xl border bg-white p-4 transition', on ? 'border-gold-500 ring-2 ring-gold-100' : 'border-navy-100 hover:border-gold-300')}>
      <button type="button" aria-pressed={on} onClick={onToggle} className="w-full text-start">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2"><h4 className="font-bold text-navy-900">{need.skill}</h4>{need.from_survey && <Badge color="blue">{t('mgmt.wizard.source.fromSurvey')}</Badge>}</div>
            <div className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
              <span>{fmt.number(need.employees)} {t('mgmt.wizard.source.employees')}</span><span>{fmt.number(need.requests)} {t('mgmt.wizard.source.requests')}</span><span>{fmt.number(need.schools)} {t('mgmt.wizard.source.schools')}</span>
            </div>
          </div>
          <div className="flex shrink-0 items-center gap-2"><Badge color={priorityTone[need.priority as keyof typeof priorityTone] ?? 'gray'}>{t(`priority.${need.priority}`, { defaultValue: need.priority })}</Badge>
            <span className={clsx('grid size-6 place-items-center rounded-full border-2', on ? 'border-gold-500 bg-gold-500 text-navy-950' : 'border-navy-200')}>{on && <Check className="size-4" />}</span></div>
        </div>
        {need.reasons.length > 0 && <ul className="mt-3 space-y-1 text-xs text-slate-600">{need.reasons.map((r) => <li key={r} className="line-clamp-2">• {r}</li>)}</ul>}
        {need.school_names.length > 0 && <p className="mt-2 truncate text-[11px] text-slate-400">{need.school_names.join('، ')}</p>}
      </button>
      {need.existing_programs.length > 0 && (
        <div className="mt-3 rounded-xl bg-amber-50 p-3 text-xs text-amber-900">
          <div className="flex items-center gap-1.5 font-bold"><AlertTriangle className="size-4" />{t('mgmt.wizard.source.existing')}</div>
          <ul className="mt-1 space-y-0.5">{need.existing_programs.map((p) => <li key={p.id}><Link className="underline" to={`/admin/programs/${p.id}`} target="_blank">{p.title}</Link> · {fmt.number(p.seats_available)} {t('mgmt.wizard.source.seatsLeft')}</li>)}</ul>
        </div>
      )}
    </article>
  )
}

type FormState = {
  code: string; title_ar: string; title_en: string; summary_ar: string; summary_en: string; category_id: string; objectives: string; delivery_mode: Draft['delivery_mode']; level: Draft['level']
  total_hours: string; capacity: string; min_attendance_percent: string; requires_tasks: boolean; requires_evaluation: boolean; registration_modes: string[]
}

/** Step 3 - program facts, prefilled from the needs. */
function DetailsStep({ form, setForm, draft, options, skills, setSkills, remote }: {
  remote: boolean; form: FormState; setForm: (f: FormState) => void; draft: Draft; options: BuilderOptions; skills: Draft['skills']; setSkills: (s: Draft['skills']) => void
}) {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const set = <K extends keyof FormState>(k: K, v: FormState[K]) => setForm({ ...form, [k]: v })
  const available = options.skills.filter((s) => !skills.some((x) => x.id === s.id))
  const name = (s: { name_ar: string; name_en: string }) => (en ? s.name_en : s.name_ar)

  return (
    <div className="space-y-5">
      {draft.demand > 0 && (
        <div className="flex flex-wrap items-center gap-3 rounded-xl bg-gold-100/40 px-4 py-2.5 text-sm text-navy-900">
          <Sparkles className="size-4 text-gold-600" /><span className="font-semibold">{t('mgmt.wizard.details.demand', { count: draft.demand })}</span>
          {draft.cohorts > 1 && <span className="text-xs text-slate-600">{t('mgmt.wizard.details.cohorts', { count: draft.cohorts, capacity: draft.capacity })}</span>}
        </div>
      )}
      <Card className="grid gap-4 sm:grid-cols-2">
        <Field label={t('mgmt.wizard.details.titleAr')}><input dir="rtl" className="input" value={form.title_ar} onChange={(e) => set('title_ar', e.target.value)} /></Field>
        <Field label={t('mgmt.wizard.details.titleEn')}><input dir="ltr" className="input" value={form.title_en} onChange={(e) => set('title_en', e.target.value)} /></Field>
        <Field label={t('mgmt.wizard.details.summaryAr')}><textarea rows={3} dir="rtl" className="input" value={form.summary_ar} onChange={(e) => set('summary_ar', e.target.value)} /></Field>
        <Field label={t('mgmt.wizard.details.summaryEn')}><textarea rows={3} dir="ltr" className="input" value={form.summary_en} onChange={(e) => set('summary_en', e.target.value)} /></Field>
        <Field label={t('mgmt.wizard.details.objectives')} hint={t('mgmt.wizard.details.objectivesHint')} className="sm:col-span-2"><textarea rows={4} className="input" value={form.objectives} onChange={(e) => set('objectives', e.target.value)} /></Field>
      </Card>
      <Card className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Field label={t('mgmt.wizard.details.code')}><input dir="ltr" className="input font-mono" value={form.code} onChange={(e) => set('code', e.target.value.toUpperCase())} /></Field>
        <Field label={t('mgmt.wizard.details.category')}><select className="input" value={form.category_id} onChange={(e) => set('category_id', e.target.value)}><option value="">—</option>{options.categories.map((c) => <option key={c.id} value={c.id}>{name(c)}</option>)}</select></Field>
        <Field label={t('mgmt.wizard.details.hours')}><input type="number" min={1} step={0.5} className="input" value={form.total_hours} onChange={(e) => set('total_hours', e.target.value)} /></Field>
        <Field label={t('mgmt.wizard.details.capacity')}><input type="number" min={1} className="input" value={form.capacity} onChange={(e) => set('capacity', e.target.value)} /></Field>
        {!remote && <Field label={t('mgmt.wizard.details.delivery')}><select className="input" value={form.delivery_mode} onChange={(e) => set('delivery_mode', e.target.value as Draft['delivery_mode'])}>{(['in_person', 'online', 'hybrid'] as const).map((m) => <option key={m} value={m}>{t(`mgmt.wizard.details.deliveryModes.${m}`)}</option>)}</select></Field>}
        <Field label={t('mgmt.wizard.details.level')}><select className="input" value={form.level} onChange={(e) => set('level', e.target.value as Draft['level'])}>{(['beginner', 'intermediate', 'advanced'] as const).map((m) => <option key={m} value={m}>{t(`mgmt.wizard.details.levels.${m}`)}</option>)}</select></Field>
        <Field label={t('mgmt.wizard.details.attendance')}><input type="number" min={0} max={100} className="input" value={form.min_attendance_percent} onChange={(e) => set('min_attendance_percent', e.target.value)} /></Field>
        <div className="flex flex-col justify-end gap-2 pb-1 text-sm text-navy-800">
          <label className="flex items-center gap-2"><input type="checkbox" className="size-4 accent-gold-600" checked={form.requires_tasks} onChange={(e) => set('requires_tasks', e.target.checked)} />{t('mgmt.wizard.details.requiresTasks')}</label>
          <label className="flex items-center gap-2"><input type="checkbox" className="size-4 accent-gold-600" checked={form.requires_evaluation} onChange={(e) => set('requires_evaluation', e.target.checked)} />{t('mgmt.wizard.details.requiresEvaluation')}</label>
        </div>
      </Card>
      <Card className="space-y-4">
        <div>
          <div className="label">{t('mgmt.wizard.details.registrationModes')}</div>
          <div className="flex flex-wrap gap-1.5">
            {(['self', 'school_nomination', 'center_nomination', 'bulk_import'] as const).map((m) => {
              const on = form.registration_modes.includes(m)
              return <button key={m} type="button" aria-pressed={on} onClick={() => set('registration_modes', on ? form.registration_modes.filter((x) => x !== m) : [...form.registration_modes, m])} className={clsx('rounded-full border px-3 py-1 text-xs font-semibold transition', on ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white text-slate-600 hover:border-gold-400')}>{t(`mgmt.wizard.details.modes.${m}`)}</button>
            })}
          </div>
        </div>
        <div>
          <div className="label">{t('mgmt.wizard.details.skills')}</div>
          <div className="flex flex-wrap items-center gap-2">
            {skills.map((s) => (
              <span key={s.id} className="inline-flex items-center gap-2 rounded-full bg-navy-100/70 py-1 ps-3 pe-1.5 text-xs font-semibold text-navy-800">
                {s.name}
                <select aria-label="level" className="rounded-md border-0 bg-white px-1 py-0.5 text-xs" value={s.target_level} onChange={(e) => setSkills(skills.map((x) => (x.id === s.id ? { ...x, target_level: Number(e.target.value) } : x)))}>{[1, 2, 3, 4, 5].map((l) => <option key={l} value={l}>{l}</option>)}</select>
                <button type="button" aria-label="remove" onClick={() => setSkills(skills.filter((x) => x.id !== s.id))} className="grid size-5 place-items-center rounded-full hover:bg-white">×</button>
              </span>
            ))}
            <select className="input !w-auto !py-1.5 text-xs" value="" onChange={(e) => { const sk = options.skills.find((x) => x.id === e.target.value); if (sk) setSkills([...skills, { id: sk.id, name: name(sk), target_level: 3 }]) }}>
              <option value="">+ {t('mgmt.common.add')}</option>{available.map((s) => <option key={s.id} value={s.id}>{name(s)}</option>)}
            </select>
          </div>
        </div>
      </Card>
    </div>
  )
}

/** One editable session row; loads the rooms that are free for its slot. */
function SessionRow({ session, index, capacity, onChange, onRemove, remote }: { session: PlannedSession; index: number; capacity: number; onChange: (s: PlannedSession) => void; onRemove: () => void; remote: boolean }) {
  const { t, i18n } = useTranslation()
  const [choices, setChoices] = useState<RoomChoice[]>([])
  const start = splitAt(session.starts_at)
  const end = splitAt(session.ends_at)
  const valid = session.starts_at < session.ends_at

  useEffect(() => {
    if (!valid || remote) return
    let cancelled = false
    const id = setTimeout(() => {
      api.get<{ data: RoomChoice[] }>('/admin/rooms/availability', { params: { starts_at: session.starts_at, ends_at: session.ends_at, capacity } })
        .then((r) => !cancelled && setChoices(r.data.data)).catch(() => !cancelled && setChoices([]))
    }, 300)
    return () => { cancelled = true; clearTimeout(id) }
  }, [session.starts_at, session.ends_at, capacity, valid, remote])

  const minutes = valid ? Math.round((new Date(session.ends_at.replace(' ', 'T')).getTime() - new Date(session.starts_at.replace(' ', 'T')).getTime()) / 60000) : 0
  const patch = (p: Partial<PlannedSession>) => onChange({ ...session, ...p })
  const setSlot = (date: string, from: string, to: string) => patch({ starts_at: join(date, from), ends_at: join(date, to) })
  const roomName = (c: RoomChoice) => (i18n.language === 'en' ? c.room.name_en : c.room.name_ar)

  return (
    <li className="grid gap-3 rounded-2xl border border-navy-100 bg-white p-3 md:grid-cols-[2.5rem_1fr_1fr_1fr_1.4fr_auto] md:items-end">
      <span className="grid size-8 place-items-center rounded-full bg-navy-900 text-xs font-bold text-gold-300">{fmt.number(index + 1)}</span>
      <Field label={t('mgmt.wizard.team.date')}><input type="date" className="input" value={start.date} onChange={(e) => setSlot(e.target.value, start.time, end.time)} /></Field>
      <Field label={t('mgmt.wizard.team.time')}><input type="time" className="input" value={start.time} onChange={(e) => setSlot(start.date, e.target.value, end.time)} /></Field>
      <Field label={`${t('mgmt.wizard.team.duration')} · ${valid ? `${fmt.number(minutes / 60, 1)} ${t('mgmt.wizard.team.hoursShort')}` : '—'}`}><input type="time" className={clsx('input', !valid && 'border-danger')} value={end.time} onChange={(e) => setSlot(start.date, start.time, e.target.value)} /></Field>
      {remote ? (
        <Field label={t('studio.team.sessionLink')}><input dir="ltr" type="url" placeholder={t('studio.team.programLink')} className="input" value={session.online_url ?? ''} onChange={(e) => patch({ online_url: e.target.value.trim() })} /></Field>
      ) : <Field label={t('mgmt.wizard.team.room')}>
        <select className="input" value={session.training_room_id ?? ''} onChange={(e) => { const c = choices.find((x) => x.room.id === e.target.value); patch({ training_room_id: e.target.value || null, room: c ? roomName(c) : null }) }}>
          <option value="">{t('mgmt.wizard.team.noRoom')}</option>
          {session.training_room_id && !choices.some((c) => c.room.id === session.training_room_id) && <option value={session.training_room_id}>{session.room ?? '…'}</option>}
          {choices.map((c) => <option key={c.room.id} value={c.room.id} disabled={!c.available}>{c.available ? '✓' : '✗'} {roomName(c)} · {fmt.number(c.room.capacity)}{!c.fits_capacity ? ' ⚠' : ''}</option>)}
        </select>
      </Field>}
      <Button variant="ghost" size="sm" aria-label={t('mgmt.wizard.team.removeSession')} icon={<Trash2 className="size-4" />} onClick={onRemove} />
    </li>
  )
}

/** Step 4 - trainers and the session schedule. */
function TeamStep({ draft, form, sessions, setSessions, trainers, setTrainers, suggestions, setSuggestions, allTrainers, skillIds, remote, audience, day }: {
  day: BuilderOptions['day']; remote: boolean; audience: Audience; draft: Draft; form: FormState; sessions: PlannedSession[]; setSessions: (s: PlannedSession[]) => void; trainers: { id: string; role: 'lead' | 'assistant' }[]
  setTrainers: (t: { id: string; role: 'lead' | 'assistant' }[]) => void; suggestions: TrainerSuggestion[]; setSuggestions: (s: TrainerSuggestion[]) => void; allTrainers: Trainer[]; skillIds: string[]
}) {
  const { t } = useTranslation()
  const [from, setFrom] = useState(() => (sessions[0]?.starts_at ?? draft.start_date ?? '').slice(0, 10))
  const [startTime, setStartTime] = useState(() => (sessions[0] ? splitAt(sessions[0].starts_at).time : day.day_start))
  const [sessionHours, setSessionHours] = useState(String(Math.max(1, Math.floor((Number(day.day_end.slice(0, 2)) * 60 + Number(day.day_end.slice(3)) - Number(day.day_start.slice(0, 2)) * 60 - Number(day.day_start.slice(3))) / 60))))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const scheduled = sessions.reduce((sum, s) => sum + Math.max(0, (new Date(s.ends_at.replace(' ', 'T')).getTime() - new Date(s.starts_at.replace(' ', 'T')).getTime()) / 3_600_000), 0)
  const lead = trainers.find((x) => x.role === 'lead')?.id
  const known = new Set(suggestions.map((s) => s.id))
  const extras = allTrainers.filter((tr) => !known.has(tr.id))

  const replan = async (fromDate: string = from, time: string = startTime) => {
    if (!fromDate) return
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: { sessions: PlannedSession[]; trainers: TrainerSuggestion[] } }>('/admin/program-builder/schedule', {
        total_hours: Number(form.total_hours) || 1, capacity: Number(form.capacity) || 1, from: fromDate, start_time: time, session_hours: Number(sessionHours) || 3, skill_ids: skillIds, remote,
      })
      setSessions(res.data.data.sessions)
      setSuggestions(res.data.data.trainers)
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }
  const setRole = (id: string, role: 'lead' | 'assistant' | null) => {
    let next = trainers.filter((x) => x.id !== id)
    if (role === 'lead') next = next.map((x) => (x.role === 'lead' ? { ...x, role: 'assistant' as const } : x))
    if (role) next = [...next, { id, role }]
    setTrainers(next)
  }
  const newSession = (): PlannedSession => {
    const last = sessions.at(-1)
    const date = last ? splitAt(last.starts_at).date : from || new Date().toISOString().slice(0, 10)
    const dt = new Date(date + 'T00:00:00')
    dt.setDate(dt.getDate() + (last ? 1 : 0))
    const d = `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`
    return { sequence: sessions.length + 1, title_ar: `الجلسة ${sessions.length + 1}`, title_en: `Session ${sessions.length + 1}`, starts_at: join(d, startTime), ends_at: join(d, day.day_end), training_room_id: null, room: null, trainer_id: null, ...(remote ? { mode: 'online' as const } : {}) }
  }

  return (
    <div className="space-y-6">
      <Card>
        <div className="mb-1 flex items-center gap-2"><GraduationCap className="size-5 text-gold-600" /><h3 className="text-lg font-bold text-navy-900">{t('mgmt.wizard.team.trainers')}</h3></div>
        <p className="mb-4 text-sm text-slate-500">{t('mgmt.wizard.team.trainerHint')}</p>
        {suggestions.length === 0 ? <p className="rounded-xl bg-slate-50 p-3 text-sm text-slate-500">{t('mgmt.wizard.team.noTrainers')}</p> : (
          <div className="grid gap-3 lg:grid-cols-2">
            {suggestions.map((s) => {
              const role = trainers.find((x) => x.id === s.id)?.role
              return (
                <article key={s.id} className={clsx('flex items-center gap-3 rounded-2xl border p-3 transition', role === 'lead' ? 'border-gold-500 bg-gold-100/30 ring-2 ring-gold-100' : role ? 'border-navy-300 bg-navy-100/30' : 'border-navy-100 bg-white')}>
                  <Avatar name={s.name} size={44} />
                  <div className="min-w-0 flex-1">
                    <div className="truncate font-bold text-navy-900">{s.name}</div>
                    <div className="truncate text-xs text-slate-500">{s.organization ?? s.source_label}</div>
                    <div className="mt-1 flex flex-wrap gap-1"><Badge color={s.available ? 'green' : 'red'}>{s.available ? t('mgmt.wizard.team.available') : t('mgmt.wizard.team.busy')}</Badge><Badge color="gold">{t('mgmt.wizard.team.match')} {fmt.number(s.score)}</Badge></div>
                  </div>
                  <div className="flex shrink-0 flex-col gap-1">
                    <Button size="sm" variant={role === 'lead' ? 'gold' : 'outline'} onClick={() => setRole(s.id, role === 'lead' ? null : 'lead')}>{t('mgmt.wizard.team.lead')}</Button>
                    <Button size="sm" variant={role === 'assistant' ? 'primary' : 'ghost'} onClick={() => setRole(s.id, role === 'assistant' ? null : 'assistant')}>{t('mgmt.wizard.team.assistant')}</Button>
                  </div>
                </article>
              )
            })}
          </div>
        )}
        {extras.length > 0 && (
          <div className="mt-4 max-w-sm">
            <select className="input" value="" onChange={(e) => e.target.value && setRole(e.target.value, lead ? 'assistant' : 'lead')}>
              <option value="">+ {t('mgmt.wizard.team.pickTrainer')}</option>{extras.map((tr) => <option key={tr.id} value={tr.id}>{tr.name} — {tr.source_label}</option>)}
            </select>
          </div>
        )}
        {trainers.filter((x) => !known.has(x.id)).map((x) => { const tr = allTrainers.find((a) => a.id === x.id); return tr ? <div key={x.id} className="mt-2 flex items-center gap-2 text-sm"><Badge color={x.role === 'lead' ? 'gold' : 'navy'}>{t(`mgmt.wizard.team.${x.role}`)}</Badge>{tr.name}<button type="button" className="text-xs text-slate-400 hover:text-danger" onClick={() => setRole(x.id, null)}>×</button></div> : null })}
      </Card>

      <Card>
        <div className="mb-1 flex items-center gap-2"><Layers className="size-5 text-gold-600" /><h3 className="text-lg font-bold text-navy-900">{t('mgmt.wizard.team.sessions')}</h3></div>
        <p className="mb-4 text-sm text-slate-500">{t('mgmt.wizard.team.hint')}</p>
        <div className="mb-5 grid gap-3 rounded-2xl bg-ivory p-3 sm:grid-cols-[1fr_1fr_1fr_auto] sm:items-end">
          <Field label={t('mgmt.wizard.team.startFrom')}><input type="date" className="input" value={from} onChange={(e) => setFrom(e.target.value)} /></Field>
          <Field label={t('mgmt.wizard.team.startTime')}><input type="time" className="input" value={startTime} onChange={(e) => setStartTime(e.target.value)} /></Field>
          <Field label={t('mgmt.wizard.team.sessionHours')}><input type="number" min={1} max={8} className="input" value={sessionHours} onChange={(e) => setSessionHours(e.target.value)} /></Field>
          <Button variant="outline" loading={busy} disabled={!from} icon={<RefreshCcw className="size-4" />} onClick={() => replan()}>{t('mgmt.wizard.team.replan')}</Button>
        </div>
        {remote && <div className="mb-5"><SmartSlots audience={audience} hours={Number(sessionHours) || 3} from={from} onPick={(slot) => { setFrom(slot.date); setStartTime(slot.time); void replan(slot.date, slot.time) }} /></div>}
        {error && <div className="mb-4 rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
        {sessions.length === 0 ? <p className="rounded-xl bg-slate-50 p-3 text-sm text-slate-500">{t('mgmt.wizard.team.noSessions')}</p> : (
          <ul className="space-y-2.5">
            {sessions.map((s, i) => <SessionRow key={`${i}-${s.sequence}`} remote={remote} session={s} index={i} capacity={Number(form.capacity) || 1} onChange={(next) => setSessions(sessions.map((x, j) => (j === i ? next : x)))} onRemove={() => setSessions(sessions.filter((_, j) => j !== i).map((x, j) => ({ ...x, sequence: j + 1 })))} />)}
          </ul>
        )}
        <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
          <Button variant="outline" size="sm" icon={<Plus className="size-4" />} onClick={() => setSessions([...sessions, newSession()])}>{t('mgmt.wizard.team.addSession')}</Button>
          <span className={clsx('text-sm font-semibold', Math.abs(scheduled - Number(form.total_hours)) < 0.01 ? 'text-emerald-700' : 'text-amber-700')}>{t('mgmt.wizard.team.planTotal', { hours: fmt.number(scheduled, 1), total: fmt.number(Number(form.total_hours), 1) })}</span>
        </div>
      </Card>
    </div>
  )
}

function Row({ label, children }: { label: string; children: ReactNode }) {
  return <div className="flex items-start justify-between gap-4 border-b border-dashed border-navy-100 py-2 text-sm last:border-0"><dt className="text-slate-500">{label}</dt><dd className="text-end font-semibold text-navy-900">{children}</dd></div>
}

export default function ProgramWizard({ remote = false }: { remote?: boolean }) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const en = i18n.language === 'en'
  const options = useGet<{ data: BuilderOptions }>('/admin/program-builder/options')
  const trainerList = useGet<Paginated<Trainer>>('/admin/trainers', { per_page: 100, status: 'active' })

  const STEPS = useMemo(() => stepsFor(remote), [remote])
  const [step, setStep] = useState<Step>('source')
  const [remoteSettings, setRemoteSettings] = useState<RemoteSettings>(emptyRemote)
  const [content, setContent] = useState<ContentPlan>(emptyPlan)
  const [prepared, setPrepared] = useState<number | null>(null)
  const [traineeTemplate, setTraineeTemplate] = useState('')
  const [trainerTemplate, setTrainerTemplate] = useState('')
  const [reached, setReached] = useState(0)
  const [mode, setMode] = useState<Mode>('needs')
  const [selected, setSelected] = useState<string[]>([])
  const [skillId, setSkillId] = useState('')
  const [draft, setDraft] = useState<Draft | null>(null)
  const [audience, setAudience] = useState<Audience>({})
  const [preview, setPreview] = useState<AudiencePreview | null>(null)
  const [form, setForm] = useState<FormState | null>(null)
  const [skills, setSkills] = useState<Draft['skills']>([])
  const [sessions, setSessions] = useState<PlannedSession[]>([])
  const [trainers, setTrainers] = useState<{ id: string; role: 'lead' | 'assistant' }[]>([])
  const [suggestions, setSuggestions] = useState<TrainerSuggestion[]>([])
  const [publish, setPublish] = useState<'draft' | 'open'>('draft')
  const [invite, setInvite] = useState(false)
  const [nominate, setNominate] = useState(false)
  const [approval, setApproval] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [created, setCreated] = useState<{ program: Program; meta: { invited?: number; nomination?: { nominated: number } | null } } | null>(null)

  const opts = options.data?.data
  const index = STEPS.indexOf(step)
  const needIds = useMemo(() => (mode === 'needs' ? (opts?.needs.filter((n) => selected.includes(n.key)).flatMap((n) => n.need_ids) ?? []) : []), [mode, opts, selected])
  const skillIds = skills.map((s) => s.id)

  const go = (s: Step) => { setError(null); setStep(s); setReached((r) => Math.max(r, STEPS.indexOf(s))) }

  const startDraft = async () => {
    setBusy(true)
    setError(null)
    try {
      const body = { ...(mode === 'needs' ? { need_ids: needIds } : { skill_id: skillId }), remote }
      const res = await api.post<{ data: Draft }>('/admin/program-builder/draft', body)
      const d = res.data.data
      setDraft(d)
      setAudience(mode === 'needs' ? d.audience : {})
      setSkills(d.skills)
      setSessions(d.sessions)
      setSuggestions(d.trainers)
      const best = d.trainers.find((x) => x.available)
      setTrainers(best ? [{ id: best.id, role: 'lead' }] : [])
      setForm({
        code: d.code, title_ar: d.title_ar, title_en: d.title_en, summary_ar: d.summary_ar, summary_en: d.summary_en, category_id: d.category_id ?? '', objectives: d.objectives.join('\n'),
        delivery_mode: remote ? 'online' : d.delivery_mode, level: d.level, total_hours: String(d.total_hours), capacity: String(d.capacity), min_attendance_percent: String(d.min_attendance_percent),
        requires_tasks: d.requires_tasks, requires_evaluation: d.requires_evaluation, registration_modes: d.registration_modes,
      })
      go('audience')
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const titleOk = !!form && form.title_ar.trim().length > 0 && form.title_en.trim().length > 0
  const nextDisabled = step === 'source' ? (mode === 'needs' ? selected.length === 0 : !skillId) : step === 'details' ? !titleOk || !(Number(form?.total_hours) > 0) || !(Number(form?.capacity) > 0) : step === 'delivery' ? !(remoteSettings.join_url === '' || /^https?:\/\/\S+$/i.test(remoteSettings.join_url)) : false

  const next = () => {
    if (step === 'source') void startDraft()
    else go(STEPS[index + 1])
  }

  const create = async () => {
    if (!form || !draft) return
    setBusy(true)
    setError(null)
    const lead = trainers.find((x) => x.role === 'lead')?.id
    const ordered = [...sessions].sort((a, b) => a.starts_at.localeCompare(b.starts_at))
    const opens = new Date()
    const closesRaw = ordered[0] ? new Date(ordered[0].starts_at.replace(' ', 'T')) : null
    if (closesRaw) closesRaw.setDate(closesRaw.getDate() - 1)
    const closes = closesRaw && closesRaw.getTime() > opens.getTime() + 60_000 ? closesRaw.toISOString() : null
    try {
      const res = await api.post<{ data: Program; meta: { invited?: number; nomination?: { nominated: number } | null } }>('/admin/program-builder', {
        code: form.code || undefined, category_id: form.category_id || null, title_ar: form.title_ar, title_en: form.title_en, summary_ar: form.summary_ar || null, summary_en: form.summary_en || null,
        objectives: form.objectives.split('\n').map((s) => s.trim()).filter(Boolean), delivery_mode: form.delivery_mode, level: form.level, total_hours: Number(form.total_hours), capacity: Number(form.capacity),
        min_attendance_percent: Number(form.min_attendance_percent), requires_tasks: form.requires_tasks, requires_evaluation: form.requires_evaluation, registration_modes: form.registration_modes,
        start_date: ordered[0]?.starts_at.slice(0, 10) ?? null, end_date: ordered.at(-1)?.starts_at.slice(0, 10) ?? null,
        registration_opens_at: closes ? opens.toISOString() : null, registration_closes_at: closes,
        status: publish === 'open' ? 'registration_open' : 'draft', skills: skills.map((s) => ({ id: s.id, target_level: s.target_level })), trainers,
        need_ids: needIds, audience,
        certificate_template_id: traineeTemplate || null, trainer_certificate_template_id: trainerTemplate || null,
        ...(remote ? { remote: { platform: remoteSettings.platform, join_url: remoteSettings.join_url || null, passcode: remoteSettings.passcode || null, join_opens_minutes: Number(remoteSettings.join_opens_minutes) || 0, instructions_ar: remoteSettings.instructions_ar || null, instructions_en: remoteSettings.instructions_en || null } } : {}),
        sessions: ordered.map((s, i) => ({ sequence: i + 1, title_ar: s.title_ar, title_en: s.title_en, starts_at: s.starts_at, ends_at: s.ends_at, training_room_id: remote ? null : s.training_room_id, trainer_id: lead ?? null, ...(remote ? { mode: 'online', online_url: s.online_url || null } : {}) })),
        calendar_approval_reason: approval.trim() || undefined, invite_audience: publish === 'open' && invite, nominate_audience: publish === 'open' && nominate,
      })
      // The online program starts with its course: structure, rules and the imported kit.
      if (remote) {
        try { setPrepared((await applyContentPlan(res.data.data.id, content)).lessons) } catch (e) { setError(errorMessage(e)); setPrepared(0) }
      }
      setCreated({ program: res.data.data, meta: res.data.meta ?? {} })
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const reset = () => { setCreated(null); setDraft(null); setForm(null); setSelected([]); setSkillId(''); setAudience({}); setSessions([]); setTrainers([]); setSuggestions([]); setPublish('draft'); setInvite(false); setNominate(false); setApproval(''); setReached(0); setStep('source'); setRemoteSettings(emptyRemote); setContent(emptyPlan); setPrepared(null); setTraineeTemplate(''); setTrainerTemplate(''); options.refetch() }

  if (options.isLoading || !opts) return <Spinner />

  if (created) {
    return (
      <Card className="mx-auto max-w-2xl text-center">
        <span className="mx-auto grid size-16 place-items-center rounded-full bg-emerald-100 text-emerald-600"><CheckCircle2 className="size-9" /></span>
        <h2 className="mt-4 text-2xl font-bold text-navy-900">{t('mgmt.wizard.review.created')}</h2>
        <p className="mt-1 text-lg font-semibold text-navy-800">{created.program.title}</p>
        <p className="font-mono text-sm text-slate-400" dir="ltr">{created.program.code}</p>
        <div className="mt-4 flex flex-wrap justify-center gap-2"><StatusBadge status={created.program.status} />{created.meta.invited != null && <Badge color="blue">{t('mgmt.wizard.review.invited', { count: created.meta.invited })}</Badge>}{created.meta.nomination && <Badge color="green">{t('mgmt.wizard.review.nominated', { count: created.meta.nomination.nominated })}</Badge>}</div>
        {remote && prepared != null && <p className="mt-4 rounded-xl bg-gold-100/50 p-3 text-sm text-navy-900">{t('studio.content.prepared', { count: prepared })}</p>}
        <div className="mt-6 flex flex-wrap justify-center gap-3">{remote && <Button variant="gold" to={`/admin/programs/${created.program.id}?tab=course`} icon={<Video className="size-4" />}>{t('studio.content.openBuilder')}</Button>}<Button variant="outline" to={`/admin/programs/${created.program.id}`} icon={<BookOpenCheck className="size-4" />}>{t('mgmt.wizard.review.openProgram')}</Button><Button variant="outline" onClick={reset}>{t('mgmt.wizard.review.another')}</Button></div>
      </Card>
    )
  }

  const leadTrainer = trainers.find((x) => x.role === 'lead')
  const leadName = suggestions.find((s) => s.id === leadTrainer?.id)?.name ?? trainerList.data?.data.find((x) => x.id === leadTrainer?.id)?.name
  const category = opts.categories.find((c) => c.id === form?.category_id)
  const checklist = [
    { ok: titleOk, label: titleOk ? form?.title_ar || '' : t('mgmt.wizard.review.missingTitle') },
    { ok: (preview?.count ?? 0) > 0 || activeFilters(audience) === 0, label: t('mgmt.wizard.review.okAudience') },
    { ok: !!leadTrainer, label: leadTrainer ? `${t('mgmt.wizard.review.okTrainer')}: ${leadName ?? ''}` : t('mgmt.wizard.review.noLead') },
    { ok: sessions.length > 0, label: sessions.length > 0 ? t('mgmt.wizard.review.okSessions') : t('mgmt.wizard.review.missingSessions') },
    ...(remote ? [{ ok: !!remoteSettings.join_url || sessions.every((s) => !!s.online_url), label: remoteSettings.join_url || sessions.every((s) => !!s.online_url) ? t('studio.review.okLink') : t('studio.review.noLink') }] : []),
  ]

  return (
    <>
      <PageHeader title={remote ? t('studio.remote.title') : t('mgmt.wizard.title')} subtitle={remote ? t('studio.remote.subtitle') : t('mgmt.wizard.subtitle')} actions={<Button variant="ghost" to="/admin/programs">{t('mgmt.common.cancel')}</Button>} />
      <Stepper steps={STEPS} step={step} reached={reached} onGo={go} remote={remote} />

      {step === 'source' && <SourceStep options={opts} mode={mode} setMode={setMode} selected={selected} setSelected={setSelected} skillId={skillId} setSkillId={setSkillId} />}

      {step === 'audience' && (
        <div className="space-y-4">
          <div><h2 className="text-xl font-bold text-navy-900">{t('mgmt.wizard.audience.title')}</h2><p className="text-sm text-slate-500">{t('mgmt.wizard.audience.hint')}</p></div>
          <TargetCategories audience={audience} onChange={setAudience} options={opts.filters} />
          <AudienceBuilder audience={audience} onChange={setAudience} options={opts.filters} prefilled={mode === 'needs' && activeFilters(audience) > 0} onPreview={setPreview} />
        </div>
      )}

      {step === 'details' && draft && form && (
        <div className="space-y-4">
          <div><h2 className="text-xl font-bold text-navy-900">{t('mgmt.wizard.details.title')}</h2><p className="text-sm text-slate-500">{t('mgmt.wizard.details.hint')}</p></div>
          <DetailsStep remote={remote} form={form} setForm={setForm} draft={draft} options={opts} skills={skills} setSkills={setSkills} />
        </div>
      )}

      {step === 'team' && draft && form && (
        <div className="space-y-4">
          <div><h2 className="text-xl font-bold text-navy-900">{t('mgmt.wizard.team.title')}</h2><p className="text-sm text-slate-500">{t('mgmt.wizard.team.hint')}</p></div>
          <TeamStep day={opts.day} remote={remote} audience={audience} draft={draft} form={form} sessions={sessions} setSessions={setSessions} trainers={trainers} setTrainers={setTrainers} suggestions={suggestions} setSuggestions={setSuggestions} allTrainers={trainerList.data?.data ?? []} skillIds={skillIds} />
        </div>
      )}

      {step === 'delivery' && (
        <div className="space-y-4">
          <div><h2 className="text-xl font-bold text-navy-900">{t('studio.delivery.title')}</h2><p className="text-sm text-slate-500">{t('studio.delivery.subtitle')}</p></div>
          <DeliveryStep value={remoteSettings} onChange={setRemoteSettings} />
        </div>
      )}

      {step === 'content' && (
        <div className="space-y-4">
          <div><h2 className="text-xl font-bold text-navy-900">{t('studio.content.title')}</h2><p className="text-sm text-slate-500">{t('studio.content.subtitle')}</p></div>
          <ContentStep plan={content} onChange={setContent} />
        </div>
      )}

      {step === 'certificates' && (
        <div className="space-y-4">
          <div><h2 className="text-xl font-bold text-navy-900">{t('studio.certs.title')}</h2><p className="text-sm text-slate-500">{t('studio.certs.subtitle')}</p></div>
          <CertificatesStep trainee={traineeTemplate} trainer={trainerTemplate} setTrainee={setTraineeTemplate} setTrainer={setTrainerTemplate} />
        </div>
      )}

      {step === 'review' && draft && form && (
        <div className="grid gap-5 lg:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)]">
          <div className="space-y-5">
            <Card>
              <h3 className="mb-2 text-lg font-bold text-navy-900">{t('mgmt.wizard.review.summary')}</h3>
              <dl>
                <Row label={t('mgmt.wizard.details.titleAr')}>{form.title_ar}</Row>
                <Row label={t('mgmt.wizard.details.titleEn')}><span dir="ltr">{form.title_en}</span></Row>
                <Row label={t('mgmt.wizard.details.code')}><span dir="ltr" className="font-mono">{form.code}</span></Row>
                {category && <Row label={t('mgmt.wizard.details.category')}>{en ? category.name_en : category.name_ar}</Row>}
                {remote && <Row label={t('studio.review.platform')}>{t(`studio.delivery.platforms.${remoteSettings.platform}`)}</Row>}
                <Row label={t('mgmt.wizard.details.hours')}>{fmt.number(Number(form.total_hours), 1)} {t('mgmt.common.hours')}</Row>
                <Row label={t('mgmt.wizard.details.capacity')}>{fmt.number(Number(form.capacity))}</Row>
                <Row label={t('mgmt.wizard.review.sessions')}>{fmt.number(sessions.length)}{sessions[0] && ` · ${fmt.date(sessions[0].starts_at, { day: 'numeric', month: 'short', year: 'numeric' })}`}</Row>
                <Row label={t('mgmt.wizard.review.trainer')}>{leadName ?? '—'}</Row>
                <Row label={t('mgmt.wizard.review.audience')}>{fmt.number(preview?.count ?? 0)} {t('mgmt.wizard.review.people')}<span className="block text-xs font-normal text-slate-500">{preview?.summary}</span></Row>
                {needIds.length > 0 && <Row label={t('mgmt.wizard.review.needs')}>{fmt.number(needIds.length)}</Row>}
              </dl>
            </Card>
            <Card>
              <h3 className="mb-3 text-lg font-bold text-navy-900">{t('mgmt.wizard.review.checklist')}</h3>
              <ul className="space-y-2 text-sm">{checklist.map((c, i) => <li key={i} className={clsx('flex items-center gap-2', c.ok ? 'text-emerald-700' : 'text-amber-700')}>{c.ok ? <CheckCircle2 className="size-4 shrink-0" /> : <AlertTriangle className="size-4 shrink-0" />}{c.label}</li>)}</ul>
            </Card>
          </div>
          <div className="space-y-5">
            <Card className="space-y-4">
              <h3 className="text-lg font-bold text-navy-900">{t('mgmt.wizard.review.publishing')}</h3>
              <div className="grid gap-2">
                {([['draft', FileEdit], ['open', Rocket]] as const).map(([id, Icon]) => (
                  <button key={id} type="button" aria-pressed={publish === id} onClick={() => setPublish(id)} className={clsx('flex items-start gap-3 rounded-xl border p-3 text-start transition', publish === id ? 'border-navy-900 bg-navy-900/5 ring-2 ring-navy-900/10' : 'border-navy-100 hover:border-gold-400')}>
                    <Icon className="mt-0.5 size-5 shrink-0 text-gold-600" />
                    <span><span className="block text-sm font-bold text-navy-900">{t(id === 'draft' ? 'mgmt.wizard.review.asDraft' : 'mgmt.wizard.review.asOpen')}</span><span className="block text-xs text-slate-500">{t(id === 'draft' ? 'mgmt.wizard.review.asDraftHint' : 'mgmt.wizard.review.asOpenHint')}</span></span>
                  </button>
                ))}
              </div>
              <label className={clsx('flex items-start gap-2 text-sm', publish !== 'open' && 'opacity-50')}>
                <input type="checkbox" className="mt-1 size-4 accent-gold-600" disabled={publish !== 'open'} checked={publish === 'open' && invite} onChange={(e) => setInvite(e.target.checked)} />
                <span><span className="font-semibold text-navy-900">{t('mgmt.wizard.review.invite')}</span><span className="block text-xs text-slate-500">{publish === 'open' ? t('mgmt.wizard.review.inviteHint') : t('mgmt.wizard.review.onlyWhenPublished')}</span></span>
              </label>
              {can('nominations.center') && (
                <label className={clsx('flex items-start gap-2 text-sm', publish !== 'open' && 'opacity-50')}>
                  <input type="checkbox" className="mt-1 size-4 accent-gold-600" disabled={publish !== 'open'} checked={publish === 'open' && nominate} onChange={(e) => setNominate(e.target.checked)} />
                  <span><span className="font-semibold text-navy-900">{t('mgmt.wizard.review.nominate')}</span><span className="block text-xs text-slate-500">{publish === 'open' ? t('mgmt.wizard.review.nominateHint') : t('mgmt.wizard.review.onlyWhenPublished')}</span></span>
                </label>
              )}
              {can('calendar.approve') && <Field label={t('mgmt.wizard.team.approvalReason')} hint={t('mgmt.wizard.team.approvalHint')}><textarea rows={2} className="input" value={approval} onChange={(e) => setApproval(e.target.value)} /></Field>}
            </Card>
            {error && <div className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
            <Button size="lg" variant="gold" className="w-full" loading={busy} disabled={!titleOk} icon={<Users2 className="size-5" />} onClick={create}>{busy ? t('mgmt.wizard.review.creating') : t('mgmt.wizard.review.create')}</Button>
          </div>
        </div>
      )}

      {error && step !== 'review' && <div className="mt-4 rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}

      {step !== 'review' && (
        <div className="mt-8 flex items-center justify-between border-t border-navy-100 pt-5">
          <Button variant="ghost" disabled={index === 0} icon={i18n.language === 'ar' ? <ArrowRight className="size-4" /> : <ArrowLeft className="size-4" />} onClick={() => go(STEPS[index - 1])}>{t('mgmt.common.back')}</Button>
          <Button variant="primary" loading={busy} disabled={nextDisabled} onClick={next}>{t('mgmt.common.next')}{i18n.language === 'ar' ? <ArrowLeft className="size-4" /> : <ArrowRight className="size-4" />}</Button>
        </div>
      )}
      {step === 'review' && <div className="mt-6"><Button variant="ghost" icon={i18n.language === 'ar' ? <ArrowRight className="size-4" /> : <ArrowLeft className="size-4" />} onClick={() => go(STEPS[STEPS.indexOf('review') - 1])}>{t('mgmt.common.back')}</Button></div>}
    </>
  )
}
