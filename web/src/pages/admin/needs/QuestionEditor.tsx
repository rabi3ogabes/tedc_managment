import clsx from 'clsx'
import {
  AlignLeft, ArrowDown, ArrowUp, Calendar, CheckSquare, ChevronDown, CircleDot, Copy, GitBranch, Grid3x3, Hash, Heading, ListOrdered, Link2,
  SlidersHorizontal, Star, ThumbsUp, Trash2, Type, Gauge, Wand2, X,
} from 'lucide-react'
import type { ClipboardEvent, ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { CHOICE_TYPES, SCALED_TYPES, suggestSkill, uid, type Choice, type Logic, type Question, type QuestionType, type Skill } from '@/lib/surveys'

export const TYPE_ICONS: Record<QuestionType, typeof Type> = {
  section: Heading, short_text: Type, long_text: AlignLeft, single: CircleDot, multiple: CheckSquare, dropdown: ChevronDown, yes_no: ThumbsUp,
  rating: Star, scale: SlidersHorizontal, nps: Gauge, matrix: Grid3x3, ranking: ListOrdered, number: Hash, date: Calendar,
}

/** Converts a question to another type, keeping what still makes sense. */
function convert(q: Question, type: QuestionType, t: (k: string) => string): Question {
  const next: Question = { id: q.id, type, title: q.title, description: q.description, required: type === 'section' ? false : q.required, show_if: q.show_if }
  const items = q.options ?? q.rows
  if (CHOICE_TYPES.includes(type)) next.options = items?.length ? items.map((o) => ({ ...o, id: o.id.startsWith('r') ? uid('o') : o.id })) : [1, 2, 3].map((n) => ({ id: uid('o'), label: `${t('surveys.defaults.option')} ${n}` }))
  if (type === 'matrix') next.rows = items?.length ? items.map((o) => ({ ...o })) : [{ id: uid('r'), label: `${t('surveys.defaults.statement')} 1` }]
  if (SCALED_TYPES.includes(type)) { next.scale = q.scale ?? { min: 1, max: 5 }; next.mode = q.mode ?? (type === 'scale' ? 'need' : 'competence') }
  if (['rating', 'scale', 'yes_no'].includes(type)) { next.skill_id = q.skill_id; next.skill_name = q.skill_name }
  return next
}

export function linkedCount(q: Question): number {
  return (q.options ?? []).filter((o) => o.skill_id || o.skill_name).length + (q.rows ?? []).filter((o) => o.skill_id || o.skill_name).length + (q.skill_id || q.skill_name ? 1 : 0)
}

export function SkillSelect({ value, name, skills, suggestFrom, onChange, compact }: { value?: string | null; name?: string | null; skills: Skill[]; suggestFrom?: string; onChange: (skill: Skill | null) => void; compact?: boolean }) {
  const { t, i18n } = useTranslation()
  const nm = (s: Skill) => (i18n.language === 'ar' ? s.name_ar : s.name_en)
  const suggestion = !value && suggestFrom ? suggestSkill(suggestFrom, skills) : null
  const groups = skills.reduce<Record<string, Skill[]>>((acc, s) => { (acc[s.category ?? '—'] ??= []).push(s); return acc }, {})
  return (
    <div className={clsx('flex items-center gap-1.5', compact ? 'w-full sm:w-56' : 'w-full')}>
      <Link2 className={clsx('size-3.5 shrink-0', value || name ? 'text-gold-600' : 'text-slate-300')} />
      <select className={clsx('input py-1.5 text-xs', (value || name) && 'border-gold-300 bg-gold-100/30 font-semibold text-navy-900')} value={value ?? ''} onChange={(e) => onChange(skills.find((s) => s.id === e.target.value) ?? null)}>
        <option value="">{name && !value ? name : t('surveys.builder.noSkill')}</option>
        {Object.entries(groups).map(([cat, list]) => <optgroup key={cat} label={cat}>{list.map((s) => <option key={s.id} value={s.id}>{nm(s)}</option>)}</optgroup>)}
      </select>
      {suggestion && (
        <button type="button" title={nm(suggestion)} onClick={() => onChange(suggestion)} className="shrink-0 rounded-lg bg-gold-100 p-1.5 text-gold-700 transition hover:bg-gold-200"><Wand2 className="size-3.5" /></button>
      )}
    </div>
  )
}

function ItemsEditor({ items, onChange, skills, addLabel, prefix }: { items: Choice[]; onChange: (items: Choice[]) => void; skills: Skill[]; addLabel: string; prefix: 'o' | 'r' }) {
  const { t } = useTranslation()
  const update = (i: number, patch: Partial<Choice>) => onChange(items.map((o, j) => (j === i ? { ...o, ...patch } : o)))
  const move = (i: number, d: number) => { const n = [...items]; [n[i], n[i + d]] = [n[i + d], n[i]]; onChange(n) }
  const paste = (i: number, e: ClipboardEvent<HTMLInputElement>) => {
    const lines = e.clipboardData.getData('text').split(/\r?\n/).map((l) => l.replace(/^\s*(?:[-•*]|\d+[.)])\s*/, '').trim()).filter(Boolean)
    if (lines.length < 2) return
    e.preventDefault()
    const added = lines.map((label) => ({ id: uid(prefix), label }))
    onChange([...items.slice(0, i), ...(items[i].label ? [items[i]] : []), ...added, ...items.slice(i + 1)])
  }
  return (
    <div className="space-y-2">
      {items.map((o, i) => (
        <div key={o.id} className="flex flex-col gap-2 rounded-xl bg-ivory p-2 sm:flex-row sm:items-center">
          <div className="flex flex-1 items-center gap-2">
            <span className="w-5 text-center text-xs font-bold text-slate-400">{i + 1}</span>
            <input className="input py-1.5 text-sm" value={o.label} onChange={(e) => update(i, { label: e.target.value })} onPaste={(e) => paste(i, e)} placeholder={t('surveys.builder.pasteHint')} />
          </div>
          <div className="flex items-center gap-1 ps-7 sm:ps-0">
            <SkillSelect compact value={o.skill_id} name={o.skill_name} skills={skills} suggestFrom={o.label} onChange={(s) => update(i, { skill_id: s?.id ?? null, skill_name: s?.name_ar ?? null })} />
            <button type="button" aria-label={t('surveys.builder.moveUp')} disabled={i === 0} onClick={() => move(i, -1)} className="rounded p-1 text-slate-400 hover:text-navy-900 disabled:opacity-20"><ArrowUp className="size-3.5" /></button>
            <button type="button" aria-label={t('surveys.builder.moveDown')} disabled={i === items.length - 1} onClick={() => move(i, 1)} className="rounded p-1 text-slate-400 hover:text-navy-900 disabled:opacity-20"><ArrowDown className="size-3.5" /></button>
            <button type="button" aria-label={t('surveys.builder.remove')} onClick={() => onChange(items.filter((_, j) => j !== i))} className="rounded p-1 text-slate-400 hover:text-red-600"><X className="size-4" /></button>
          </div>
        </div>
      ))}
      <button type="button" onClick={() => onChange([...items, { id: uid(prefix), label: '' }])} className="text-sm font-semibold text-gold-700 hover:underline">+ {addLabel}</button>
    </div>
  )
}

function Toggle({ checked, onChange, label }: { checked: boolean; onChange: (v: boolean) => void; label: string }) {
  return (
    <label className="inline-flex cursor-pointer items-center gap-2 text-sm font-semibold text-navy-900">
      <button type="button" role="switch" aria-checked={checked} onClick={() => onChange(!checked)} className={clsx('relative h-6 w-11 rounded-full transition', checked ? 'bg-gold-500' : 'bg-slate-200')}>
        <span className={clsx('absolute top-0.5 size-5 rounded-full bg-white shadow transition-all', checked ? 'start-[22px]' : 'start-0.5')} />
      </button>
      {label}
    </label>
  )
}
export { Toggle }

export default function QuestionEditor({ q, index, previous, skills, onChange, onRemove, onDuplicate, onMove, canUp, canDown }: {
  q: Question; index: number; previous: Question[]; skills: Skill[]
  onChange: (q: Question) => void; onRemove: () => void; onDuplicate: () => void; onMove: (d: number) => void; canUp: boolean; canDown: boolean
}) {
  const { t } = useTranslation()
  const set = (patch: Partial<Question>) => onChange({ ...q, ...patch })
  const scale = q.scale ?? { min: 1, max: 5 }
  const source = previous.find((p) => p.id === q.show_if?.question)
  const setLogic = (patch: Partial<Logic> | null) => set({ show_if: patch === null ? null : { question: q.show_if?.question ?? '', op: q.show_if?.op ?? 'equals', value: q.show_if?.value ?? null, ...patch } })

  return (
    <div className="space-y-5">
      <div className="grid gap-3 sm:grid-cols-[1fr_220px]">
        <label className="block">
          <span className="label">{q.type === 'section' ? t('surveys.types.section') : `${t('surveys.builder.question')} ${index}`}</span>
          <textarea rows={2} className="input resize-none text-base font-semibold" value={q.title} onChange={(e) => set({ title: e.target.value })} autoFocus />
        </label>
        <label className="block">
          <span className="label">{t('surveys.builder.type')}</span>
          <select className="input" value={q.type} onChange={(e) => onChange(convert(q, e.target.value as QuestionType, t))}>
            {(Object.keys(TYPE_ICONS) as QuestionType[]).map((type) => <option key={type} value={type}>{t(`surveys.types.${type}`)}</option>)}
          </select>
        </label>
      </div>
      <input className="input text-sm" placeholder={t('surveys.builder.description')} value={q.description ?? ''} onChange={(e) => set({ description: e.target.value || null })} />

      {CHOICE_TYPES.includes(q.type) && (
        <div>
          <div className="mb-2 flex items-center justify-between"><span className="label mb-0">{t('surveys.builder.options')}</span>{q.type === 'multiple' && (
            <label className="flex items-center gap-2 text-xs text-slate-500">{t('surveys.builder.maxSelect')}
              <input type="number" min={0} max={q.options?.length ?? 1} className="input w-16 py-1 text-xs" value={q.max_select ?? ''} onChange={(e) => set({ max_select: e.target.value ? Number(e.target.value) : null })} />
            </label>
          )}</div>
          <ItemsEditor items={q.options ?? []} onChange={(options) => set({ options })} skills={skills} addLabel={t('surveys.builder.addOption')} prefix="o" />
        </div>
      )}

      {q.type === 'matrix' && (
        <div>
          <span className="label">{t('surveys.builder.rows')}</span>
          <ItemsEditor items={q.rows ?? []} onChange={(rows) => set({ rows })} skills={skills} addLabel={t('surveys.builder.addRow')} prefix="r" />
        </div>
      )}

      {SCALED_TYPES.includes(q.type) && (
        <div className="grid gap-3 rounded-2xl border border-navy-100 p-4 sm:grid-cols-2">
          <div className="flex items-end gap-2">
            <label className="flex-1"><span className="label">{t('surveys.builder.from')}</span>
              <select className="input" value={scale.min} onChange={(e) => set({ scale: { ...scale, min: Number(e.target.value) } })}>{[0, 1].map((n) => <option key={n}>{n}</option>)}</select>
            </label>
            <label className="flex-1"><span className="label">{t('surveys.builder.to')}</span>
              <select className="input" value={scale.max} onChange={(e) => set({ scale: { ...scale, max: Number(e.target.value) } })}>{[3, 4, 5, 6, 7, 10].map((n) => <option key={n}>{n}</option>)}</select>
            </label>
          </div>
          {q.type !== 'rating' ? (
            <div className="flex gap-2">
              <input className="input" placeholder={t('surveys.builder.minLabel')} value={scale.min_label ?? ''} onChange={(e) => set({ scale: { ...scale, min_label: e.target.value } })} />
              <input className="input" placeholder={t('surveys.builder.maxLabel')} value={scale.max_label ?? ''} onChange={(e) => set({ scale: { ...scale, max_label: e.target.value } })} />
            </div>
          ) : <div />}
          <div className="sm:col-span-2">
            <span className="label">{t('surveys.builder.mode')}</span>
            <div className="grid gap-2 sm:grid-cols-2">
              {(['competence', 'need'] as const).map((m) => (
                <button type="button" key={m} onClick={() => set({ mode: m })} className={clsx('rounded-xl border px-3 py-2 text-start text-xs transition', (q.mode ?? 'competence') === m ? 'border-gold-500 bg-gold-100/50 font-semibold text-navy-900' : 'border-navy-100 text-slate-500')}>{t(`surveys.builder.${m}`)}</button>
              ))}
            </div>
          </div>
        </div>
      )}

      {['rating', 'scale', 'yes_no'].includes(q.type) && (
        <div><span className="label">{t('surveys.builder.skill')}</span><SkillSelect value={q.skill_id} name={q.skill_name} skills={skills} suggestFrom={q.title} onChange={(s) => set({ skill_id: s?.id ?? null, skill_name: s?.name_ar ?? null })} /></div>
      )}

      {q.type !== 'section' && previous.length > 0 && (
        <div className="rounded-2xl bg-navy-100/30 p-4">
          <div className="mb-2 flex items-center gap-2 text-sm font-bold text-navy-900"><GitBranch className="size-4 text-gold-600" />{t('surveys.builder.logic')}</div>
          <div className="grid gap-2 sm:grid-cols-3">
            <select className="input py-1.5 text-xs" value={q.show_if?.question ?? ''} onChange={(e) => setLogic(e.target.value ? { question: e.target.value, value: null } : null)}>
              <option value="">{t('surveys.builder.always')}</option>
              {previous.map((p, i) => <option key={p.id} value={p.id}>{i + 1}. {p.title.slice(0, 60)}</option>)}
            </select>
            {q.show_if?.question && (
              <>
                <select className="input py-1.5 text-xs" value={q.show_if.op} onChange={(e) => setLogic({ op: e.target.value as Logic['op'] })}>
                  {(['equals', 'not_equals', 'includes', 'gte', 'lte', 'answered'] as const).map((op) => <option key={op} value={op}>{t(`surveys.builder.ops.${op}`)}</option>)}
                </select>
                {q.show_if.op !== 'answered' && (source?.options || source?.type === 'yes_no' ? (
                  <select className="input py-1.5 text-xs" value={String(q.show_if.value ?? '')} onChange={(e) => setLogic({ value: e.target.value })}>
                    <option value="">{t('surveys.builder.value')}</option>
                    {source?.type === 'yes_no' ? ['yes', 'no'].map((v) => <option key={v} value={v}>{t(`surveys.form.${v}`)}</option>) : source?.options?.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
                  </select>
                ) : (
                  <input className="input py-1.5 text-xs" placeholder={t('surveys.builder.value')} value={String(q.show_if.value ?? '')} onChange={(e) => setLogic({ value: e.target.value === '' ? null : Number.isNaN(Number(e.target.value)) ? e.target.value : Number(e.target.value) })} />
                ))}
              </>
            )}
          </div>
        </div>
      )}

      <div className="flex flex-wrap items-center gap-3 border-t border-navy-100 pt-4">
        {q.type !== 'section' && <Toggle checked={!!q.required} onChange={(v) => set({ required: v })} label={t('surveys.builder.required')} />}
        <div className="ms-auto flex items-center gap-1">
          <IconAction label={t('surveys.builder.moveUp')} disabled={!canUp} onClick={() => onMove(-1)}><ArrowUp className="size-4" /></IconAction>
          <IconAction label={t('surveys.builder.moveDown')} disabled={!canDown} onClick={() => onMove(1)}><ArrowDown className="size-4" /></IconAction>
          <IconAction label={t('surveys.builder.duplicate')} onClick={onDuplicate}><Copy className="size-4" /></IconAction>
          <IconAction label={t('surveys.builder.remove')} onClick={onRemove} danger><Trash2 className="size-4" /></IconAction>
        </div>
      </div>
    </div>
  )
}

function IconAction({ label, onClick, children, disabled, danger }: { label: string; onClick: () => void; children: ReactNode; disabled?: boolean; danger?: boolean }) {
  return <button type="button" title={label} aria-label={label} disabled={disabled} onClick={onClick} className={clsx('rounded-lg p-2 text-slate-400 transition disabled:opacity-25', danger ? 'hover:bg-red-50 hover:text-red-600' : 'hover:bg-navy-100/60 hover:text-navy-900')}>{children}</button>
}
